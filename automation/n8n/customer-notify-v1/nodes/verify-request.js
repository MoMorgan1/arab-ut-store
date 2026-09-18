/* eslint-disable */
// customer-notify v1 — the store's customer.notify (schema 1) request,
// verified before anything reads it. A verification failure THROWS: the Error
// Workflow sends the Telegram alert, the store sees no acknowledgement and
// retries on its own backoff. A request that fails verification is never
// answered as an acknowledgement (contract, step 1).
//
// The signature covers the exact bytes the store sent (timestamp, event id and
// raw body joined by newlines, HMAC-SHA256 with N8N_CUSTOMER_NOTIFY_SECRET),
// so the Webhook node must run with "Raw Body" on. Verification reads those
// bytes and never a re-serialised copy: PHP escapes Arabic where JavaScript
// does not, and every body here is Arabic.
//
// Keys and secrets live in the Config node (Edit Fields) that sits between the
// Webhook and this node - the instance has neither environment variables nor
// $vars (owner decision, 2026-09-14). A value still reading CONFIGURE_ is a
// value nobody pasted.

const crypto = require('crypto');

const config = $('Config').first().json || {};
const requiredKeys = ['N8N_CUSTOMER_NOTIFY_KEY', 'N8N_CUSTOMER_NOTIFY_SECRET', 'WHAPI_TOKEN'];
const missingKeys = requiredKeys.filter((name) => !config[name] || /^CONFIGURE_/.test(String(config[name])));
if (missingKeys.length) {
    throw new Error(`[verify] not set in the Config node: ${missingKeys.join(', ')}`);
}
if (String(config.N8N_CUSTOMER_NOTIFY_SECRET).length < 32) {
    throw new Error('[verify] N8N_CUSTOMER_NOTIFY_SECRET is shorter than 32 characters');
}

const item = $('Webhook').first();
const json = item.json || {};
const headers = json.headers || {};

function header(name) {
    const value = headers[name] ?? headers[name.toLowerCase()];
    return String(Array.isArray(value) ? value[0] : value ?? '');
}

function equal(left, right) {
    const a = Buffer.from(String(left), 'utf8');
    const b = Buffer.from(String(right), 'utf8');
    return a.length === b.length && crypto.timingSafeEqual(a, b);
}

// The raw body. In n8n's default (memory) binary mode the Webhook node hands
// it over base64-encoded under binary.data.data; the filesystem mode hands a
// file id instead, which the helper below resolves when it exists (it reads
// this node's input, so the Config node must keep "Include Binary" on).
async function rawBody() {
    const helpers = typeof this === 'object' && this !== null ? this.helpers : null;
    if (helpers && typeof helpers.getBinaryDataBuffer === 'function') {
        try {
            const buffer = await helpers.getBinaryDataBuffer(0, 'data');
            if (buffer && buffer.length) return buffer.toString('utf8');
        } catch (error) {
            // fall through to the in-memory shape
        }
    }
    const binary = item.binary && item.binary.data;
    if (binary && binary.id) {
        return null;
    }
    if (binary && typeof binary.data === 'string' && binary.data !== '') {
        return Buffer.from(binary.data, 'base64').toString('utf8');
    }
    if (typeof json.rawBody === 'string') return json.rawBody;
    return null;
}

const raw = await rawBody.call(this);
if (raw === null) {
    throw new Error(
        '[verify] the raw request body is unavailable - turn on "Raw Body" in the Webhook node options',
    );
}

const key = header('x-arabut-key');
const timestamp = header('x-arabut-timestamp');
const eventId = header('x-arabut-event');
const signature = header('x-arabut-signature');

if (!equal(key, config.N8N_CUSTOMER_NOTIFY_KEY)) {
    throw new Error('[verify] X-ArabUT-Key does not match N8N_CUSTOMER_NOTIFY_KEY');
}
if (!/^\d{10}$/.test(timestamp) || Math.abs(Math.floor(Date.now() / 1000) - Number(timestamp)) > 300) {
    throw new Error('[verify] X-ArabUT-Timestamp is outside the five-minute window');
}
const expected = crypto
    .createHmac('sha256', config.N8N_CUSTOMER_NOTIFY_SECRET)
    .update(`${timestamp}\n${eventId}\n${raw}`)
    .digest('hex');
if (!equal(signature, expected)) {
    throw new Error('[verify] X-ArabUT-Signature does not match the request body');
}

let event;
try {
    event = JSON.parse(raw);
} catch (error) {
    throw new Error('[verify] the request body is not JSON');
}

if (!event || event.eventType !== 'customer.notify') {
    throw new Error(`[verify] unexpected eventType ${event && event.eventType}`);
}
if (Number(event.schemaVersion) !== 1) {
    throw new Error(`[verify] unexpected schemaVersion ${event && event.schemaVersion}; this workflow reads schema 1`);
}
if (event.eventId !== eventId) {
    throw new Error('[verify] X-ArabUT-Event does not match the body eventId');
}

const data = event.data || {};

// Whapi takes international digits without the `+`; the store already
// normalised it. Checking the shape here means a malformed number fails
// loudly at our door rather than as an opaque Whapi 4xx ten attempts later.
if (!/^\d{9,15}$/.test(String(data.to ?? ''))) {
    throw new Error('[verify] data.to is not international digits without the +');
}
if (typeof data.body !== 'string' || data.body.trim() === '') {
    throw new Error('[verify] data.body is empty; there is no message to send');
}
if (typeof data.idempotency_key !== 'string' || data.idempotency_key === '') {
    throw new Error('[verify] data.idempotency_key is missing; a send cannot be de-duplicated');
}

// The number and the body go no further than the Whapi call. Everything else
// here is what an operator may read in an execution list or an alert - which
// is why the order number travels and the recipient does not.
return [
    {
        json: {
            eventId,
            idempotencyKey: data.idempotency_key,
            notificationPublicId: data.notification_public_id ?? null,
            orderNumber: data.order_number ?? null,
            orderItemPublicId: data.order_item_public_id ?? null,
            template: data.template ?? null,
            locale: data.locale ?? 'ar',
            to: String(data.to),
            body: data.body,
            receivedAt: new Date().toISOString(),
        },
    },
];
