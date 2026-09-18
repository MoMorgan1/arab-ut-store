import { Buffer } from 'node:buffer';
import { createHmac } from 'node:crypto';
import { readFile } from 'node:fs/promises';
import { createRequire } from 'node:module';

const root = new URL('../', import.meta.url);
const require = createRequire(import.meta.url);

export const NOW = '2026-09-18T12:00:00.000Z';
export const NOW_SECONDS = Math.floor(new Date(NOW).getTime() / 1000);
export const NOTIFY_SECRET = 'customer-notify-secret-customer-notify-01';

export async function nodeSource(name) {
    return readFile(new URL(`nodes/${name}.js`, root), 'utf8');
}

function fixedDate(now) {
    return class FixedDate extends Date {
        constructor(value) {
            super(value ?? now);
        }

        static now() {
            return new Date(now).getTime();
        }
    };
}

function toItems(value) {
    const list = Array.isArray(value) ? value : [value];

    return list.map((entry) => (entry && Object.hasOwn(entry, 'json') ? entry : { json: entry }));
}

const AsyncFunction = Object.getPrototypeOf(async function () {}).constructor;

/**
 * Runs the Code nodes the way n8n does: `$('Node')` resolves against what
 * earlier nodes produced, and `$getWorkflowStaticData` hands back one object
 * that survives across runs of the same pipeline - which is the whole point of
 * the de-duplication tests, so it is real state rather than a stub.
 */
export function pipeline({ config = {}, now = NOW, staticData = {} } = {}) {
    const outputs = new Map();
    outputs.set('Config', toItems(config));
    const FixedDate = fixedDate(now);
    const memory = staticData;

    async function run(nodeName, sourceName, input) {
        const items = toItems(input ?? []);

        if (nodeName === 'Verify Request') {
            // In the workflow the Webhook item reaches Verify Request through
            // the Config node; the node reads it back by name.
            outputs.set('Webhook', items);
        }

        const source = await nodeSource(sourceName);
        const lookup = (name) => {
            if (!outputs.has(name)) {
                throw new Error(`node "${name}" has not executed`);
            }

            const produced = outputs.get(name);

            return {
                first: () => produced[0],
                last: () => produced[produced.length - 1],
                all: () => produced,
            };
        };
        const runner = new AsyncFunction(
            '$',
            '$input',
            '$getWorkflowStaticData',
            'require',
            'Date',
            source,
        );
        const produced = toItems(
            await runner.call(
                { helpers: {} },
                lookup,
                {
                    first: () => items[0] ?? { json: {} },
                    last: () => items[items.length - 1] ?? { json: {} },
                    all: () => items,
                },
                () => memory,
                require,
                FixedDate,
            ),
        );

        outputs.set(nodeName, produced);

        return produced;
    }

    return {
        run,
        memory,
        set: (nodeName, value) => outputs.set(nodeName, toItems(value)),
        get: (nodeName) => outputs.get(nodeName),
        json: (nodeName) => outputs.get(nodeName)[0].json,
    };
}

export function config(overrides = {}) {
    return {
        N8N_CUSTOMER_NOTIFY_KEY: 'notify-publisher',
        N8N_CUSTOMER_NOTIFY_SECRET: NOTIFY_SECRET,
        WHAPI_TOKEN: 'whapi-fixture-token',
        WHAPI_BASE_URL: 'https://gate.whapi.cloud',
        ...overrides,
    };
}

/** The store's customer.notify (schema 1) event, as the publisher composes it. */
export function customerNotify(overrides = {}) {
    return {
        eventId: '01K5F8Q2M3R8X1YQ6H2Z7N9P3C5',
        eventType: 'customer.notify',
        schemaVersion: 1,
        occurredAt: NOW,
        data: {
            notification_public_id: '01K5F8Q2N1A8X1YQ6H2Z7N9P3C5',
            order_number: 'AUT-7K2MQ4',
            order_item_public_id: '01K52J0V3C1A8W4E7R2T9Y6U3I',
            template: 'credentials',
            locale: 'ar',
            idempotency_key: 'customer-notify:item:881:credentials:12043',
            to: '966512345678',
            body: 'أهلاً فهد 👋\n\n❌ ما قدرنا ندخل على حساب EA — طلب #AUT-7K2MQ4',
            ...overrides,
        },
    };
}

/**
 * What the Webhook node hands Verify Request: lower-cased headers, the parsed
 * body, and the raw bytes base64-encoded under binary.data - n8n's in-memory
 * binary shape. PHP escapes the Arabic that fills every one of these bodies,
 * which is exactly why the signature is checked over the raw bytes.
 */
export function webhookRequest(
    event,
    { key = 'notify-publisher', secret = NOTIFY_SECRET, timestamp = String(NOW_SECONDS), eventHeader, raw, signature } = {},
) {
    const body =
        raw ??
        JSON.stringify(event).replace(
            /[-￿]/g,
            (char) => `\\u${char.charCodeAt(0).toString(16).padStart(4, '0')}`,
        );
    const eventId = eventHeader ?? event.eventId;
    const computed = createHmac('sha256', secret).update(`${timestamp}\n${eventId}\n${body}`).digest('hex');

    return {
        json: {
            headers: {
                'content-type': 'application/json',
                'x-arabut-key': key,
                'x-arabut-timestamp': timestamp,
                'x-arabut-event': eventId,
                'x-arabut-signature': signature ?? computed,
            },
            params: {},
            query: {},
            body: JSON.parse(body),
        },
        binary: {
            data: {
                data: Buffer.from(body, 'utf8').toString('base64'),
                mimeType: 'application/json',
                fileName: 'body',
            },
        },
    };
}

/** Verify Request -> Already Sent?, against a memory the caller can inspect. */
export async function considered(event = customerNotify(), options = {}) {
    const flow = pipeline({ config: config(), ...options });
    await flow.run('Verify Request', 'verify-request', webhookRequest(event, options));
    await flow.run('Already Sent?', 'already-sent', flow.get('Verify Request'));

    return flow;
}

/** Already Sent? -> Send WhatsApp (faked) -> Record Result. */
export async function delivered(flow, whapiResponse) {
    flow.set('Send WhatsApp', whapiResponse);

    return flow.run('Record Result', 'record-result', whapiResponse);
}
