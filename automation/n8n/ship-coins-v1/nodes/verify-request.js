/* eslint-disable */
// ship-coins v1 — the store's order.paid (schema 2) request, verified before
// anything reads it. Every failure in this workflow THROWS: the Error Workflow
// sends the Telegram alert and the store, seeing no acknowledgement, retries
// with whatever is still unplaced. There is no in-flow failure rail.
//
// The signature covers the exact bytes the store sent (timestamp, event id and
// raw body joined by newlines, HMAC-SHA256 with N8N_ORDER_PAID_SECRET), so the
// Webhook node must run with "Raw Body" on. Verification reads those bytes and
// never a re-serialised copy: PHP writes 1.0 where JavaScript writes 1, and
// escapes Arabic where JavaScript does not.
//
// Keys and secrets live in the Config node (Edit Fields) that sits between
// the Webhook and this node - the instance has neither environment variables
// nor $vars (owner decision, 2026-09-14). A value still reading CONFIGURE_...
// is a value nobody pasted.

const crypto = require('crypto');

const config = $('Config').first().json || {};
const requiredKeys = [
    'N8N_ORDER_PAID_KEY',
    'N8N_ORDER_PAID_SECRET',
    'N8N_FULFILLMENT_KEY',
    'N8N_FULFILLMENT_SECRET',
    'FFT_API_USER',
    'FFT_API_KEY',
    'UTT_API_KEY',
];
const missingKeys = requiredKeys.filter((name) => !config[name] || /^CONFIGURE_/.test(String(config[name])));
if (missingKeys.length) {
    throw new Error(
        `[verify] not set in the Config node: ${missingKeys.join(', ')}`,
    );
}
if (String(config.N8N_ORDER_PAID_SECRET).length < 32) {
    throw new Error('[verify] N8N_ORDER_PAID_SECRET is shorter than 32 characters');
}

// Read from the Webhook node by name: the Config node sits in between and
// passes the request through, but the raw bytes are authoritative here.
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
        // Stored outside memory and the helper could not read it: the
        // in-memory field holds a mode marker, not the bytes.
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

if (!equal(key, config.N8N_ORDER_PAID_KEY)) {
    throw new Error('[verify] X-ArabUT-Key does not match N8N_ORDER_PAID_KEY');
}
if (!/^\d{10}$/.test(timestamp) || Math.abs(Math.floor(Date.now() / 1000) - Number(timestamp)) > 300) {
    throw new Error('[verify] X-ArabUT-Timestamp is outside the five-minute window');
}
const expected = crypto
    .createHmac('sha256', config.N8N_ORDER_PAID_SECRET)
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

if (!event || event.eventType !== 'order.paid') {
    throw new Error(`[verify] unexpected eventType ${event && event.eventType}`);
}
if (Number(event.schemaVersion) !== 2) {
    throw new Error(`[verify] unexpected schemaVersion ${event && event.schemaVersion}; this workflow reads schema 2`);
}
if (event.eventId !== eventId) {
    throw new Error('[verify] X-ArabUT-Event does not match the body eventId');
}
const order = event.data;
if (!order || typeof order.order_number !== 'string' || !Array.isArray(order.items) || order.items.length === 0) {
    throw new Error('[verify] the request carries no items to place');
}

return [{ json: { eventId, order, receivedAt: new Date().toISOString() } }];
