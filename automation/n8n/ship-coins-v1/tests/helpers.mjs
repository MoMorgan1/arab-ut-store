import { Buffer } from 'node:buffer';
import { createHmac } from 'node:crypto';
import { readFile } from 'node:fs/promises';
import { createRequire } from 'node:module';

const root = new URL('../', import.meta.url);
const require = createRequire(import.meta.url);

export const NOW = '2026-09-14T12:00:00.000Z';
export const NOW_SECONDS = Math.floor(new Date(NOW).getTime() / 1000);

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

    return list.map((entry) =>
        entry && Object.hasOwn(entry, 'json') ? entry : { json: entry },
    );
}

const AsyncFunction = Object.getPrototypeOf(async function () {}).constructor;

/**
 * Runs the Code nodes the way n8n does: `$('Node')` resolves against what
 * earlier nodes produced, `this.helpers.httpRequest` is whatever the test
 * supplies, and top-level `await` works. The decision engine calls suppliers
 * from inside its Code node, so `helpers.httpRequest` is where a test scripts
 * UTT's predictions and FFT's preview.
 */
export function pipeline({ env = {}, now = NOW, httpRequest } = {}) {
    const outputs = new Map();
    const FixedDate = fixedDate(now);
    const calls = [];
    const helpers = {
        httpRequest: async (options) => {
            calls.push(options);

            if (!httpRequest) {
                throw new Error(`unexpected httpRequest to ${options.url}`);
            }

            return httpRequest(options, calls.length);
        },
    };

    async function run(nodeName, sourceName, input) {
        const items = toItems(input ?? []);
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
        const runner = new AsyncFunction('$', '$input', '$env', 'require', 'Date', source);
        const produced = toItems(
            await runner.call(
                { helpers },
                lookup,
                {
                    first: () => items[0] ?? { json: {} },
                    last: () => items[items.length - 1] ?? { json: {} },
                    all: () => items,
                },
                env,
                require,
                FixedDate,
            ),
        );

        outputs.set(nodeName, produced);

        return produced;
    }

    return {
        run,
        calls,
        set: (nodeName, value) => outputs.set(nodeName, toItems(value)),
        get: (nodeName) => outputs.get(nodeName),
        json: (nodeName) => outputs.get(nodeName)[0].json,
    };
}

export const ORDER_PAID_SECRET = 'order-paid-secret-order-paid-secret-0001';
export const FULFILLMENT_SECRET = 'fulfillment-secret-fulfillment-secret-01';

export function env(overrides = {}) {
    return {
        N8N_ORDER_PAID_KEY: 'checkout-publisher',
        N8N_ORDER_PAID_SECRET: ORDER_PAID_SECRET,
        N8N_FULFILLMENT_KEY: 'fulfillment-key',
        N8N_FULFILLMENT_SECRET: FULFILLMENT_SECRET,
        FFT_API_USER: 'fixture@example.com',
        FFT_API_KEY: 'fixture-key',
        UTT_API_KEY: 'utt-fixture-key',
        ...overrides,
    };
}

export function account(overrides = {}) {
    return {
        ea_email: 'Fahad@example.test',
        ea_password: 'safe password',
        backup_codes: ['11111111', '22222222', '33333333'],
        current_balance: 350000,
        credential_version: 1,
        ...overrides,
    };
}

export function coinsItem(overrides = {}) {
    return {
        order_item_public_id: '01K52J0V3C1A8W4E7R2T9Y6U3I',
        service: 'coins',
        platform: 'playstation',
        supplier_platform: 'PS',
        quantity: 1,
        coins: { quantity: 1250000, delivery: 'fast' },
        budget: { max_eur_per_100k: 1.0, basis: 'console_fast:2000K', pricing_version: 12 },
        account: account(),
        ...overrides,
    };
}

export function sbcItem(overrides = {}) {
    return {
        order_item_public_id: '01K52J0V3D5S9F2G6H8J1K4L7Z',
        service: 'sbc',
        platform: 'playstation',
        supplier_platform: 'PS',
        quantity: 1,
        sbc: { set_id: 412, times_to_solve: 2 },
        budget: { max_eur_per_100k: 1.0, basis: 'console_fast:2000K', pricing_version: 12 },
        account: account(),
        ...overrides,
    };
}

/** The store's order.paid (schema 2) event, as PublishOrderPaidEvent composes it. */
export function orderPaid(items = [coinsItem()], overrides = {}) {
    return {
        eventId: '01K52J0V4M8R1YQ6H2Z7N9P3C5',
        eventType: 'order.paid',
        schemaVersion: 2,
        occurredAt: NOW,
        data: {
            order_public_id: '01K52J0V3B7X2Y9Q5H8N1M4R6T',
            order_number: 'AUT-7K2MQ4',
            channel: 'store',
            locale: 'ar',
            currency: 'SAR',
            total_halalah: 61500,
            item_count: items.length,
            customer_name: 'فهد العتيبي',
            items,
            ...overrides,
        },
    };
}

/**
 * What the Webhook node hands Verify Request: lower-cased headers, the parsed
 * body, and the raw bytes base64-encoded under binary.data - n8n's in-memory
 * binary shape.
 */
export function webhookRequest(event, { key = 'checkout-publisher', secret = ORDER_PAID_SECRET, timestamp = String(NOW_SECONDS), eventHeader, raw, signature } = {}) {
    // PHP's json_encode: slashes unescaped, unicode escaped, 1.0 kept - the
    // bytes only match when they are read raw.
    const body = raw ?? JSON.stringify(event).replace(/[\u0080-\uffff]/g, (char) => `\\u${char.charCodeAt(0).toString(16).padStart(4, '0')}`).replace(/"max_eur_per_100k":1,/g, '"max_eur_per_100k":1.0,');
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

/** Verify Request → Plan Shipment, with the given items. */
export async function planned(items, options = {}) {
    const flow = pipeline({ env: env(), ...options });
    await flow.run('Verify Request', 'verify-request', webhookRequest(orderPaid(items)));
    await flow.run('Plan Shipment', 'plan-shipment', flow.get('Verify Request'));

    return flow;
}

export function uttStocks(stocks) {
    return {
        ratioEuroUsd: '1.15',
        stocks: stocks.map(([numberStock, price, averageCoinsAvailable]) => ({
            numberStock,
            price: String(price),
            averageCoinsAvailable: String(averageCoinsAvailable),
        })),
    };
}

export function fftSbcs() {
    return [
        { setID: 412, sbcName: 'Team of the Week Upgrade', challengeAmount: 2, expiry: '2035-07-30 19:00:00', consolePrice: 145000, pcPrice: 132000 },
        { setID: 413, sbcName: 'Unpriced', challengeAmount: 1, expiry: '2035-07-30 19:00:00', consolePrice: 0, pcPrice: '' },
    ];
}
