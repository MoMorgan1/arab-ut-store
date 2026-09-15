import { Buffer } from 'node:buffer';
import { createHmac } from 'node:crypto';
import { readFile } from 'node:fs/promises';
import { createRequire } from 'node:module';

const root = new URL('../', import.meta.url);
const require = createRequire(import.meta.url);

export const NOW = '2026-09-15T12:00:00.000Z';
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
 * earlier nodes produced and top-level `await` works.
 */
export function pipeline({ config = {}, now = NOW } = {}) {
    const outputs = new Map();
    // What the Config node (Edit Fields) produced: the pasted keys.
    outputs.set('Config', toItems(config));
    const FixedDate = fixedDate(now);

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
        const runner = new AsyncFunction('$', '$input', 'require', 'Date', source);
        const produced = toItems(
            await runner.call(
                { helpers: {} },
                lookup,
                {
                    first: () => items[0] ?? { json: {} },
                    last: () => items[items.length - 1] ?? { json: {} },
                    all: () => items,
                },
                require,
                FixedDate,
            ),
        );

        outputs.set(nodeName, produced);

        return produced;
    }

    return {
        run,
        set: (nodeName, value) => outputs.set(nodeName, toItems(value)),
        get: (nodeName) => outputs.get(nodeName),
        json: (nodeName) => outputs.get(nodeName)[0].json,
    };
}

export const SOLVE_SECRET = 'solve-challenge-secret-solve-challenge-1';
export const FULFILLMENT_SECRET = 'fulfillment-secret-fulfillment-secret-01';

export function config(overrides = {}) {
    return {
        N8N_SOLVE_CHALLENGE_KEY: 'challenge-publisher',
        N8N_SOLVE_CHALLENGE_SECRET: SOLVE_SECRET,
        N8N_FULFILLMENT_KEY: 'fulfillment-key',
        N8N_FULFILLMENT_SECRET: FULFILLMENT_SECRET,
        FFT_API_USER: 'fixture@example.com',
        FFT_API_KEY: 'fixture-key',
        ...overrides,
    };
}

export function account(overrides = {}) {
    return {
        ea_email: 'Fahad@example.test',
        ea_password: 'safe password',
        backup_codes: ['11111111', '22222222', '33333333'],
        current_balance: 350000,
        credential_version: 3,
        ...overrides,
    };
}

export function challengeItem(overrides = {}) {
    return {
        order_item_public_id: '01K52J0V3D5S9F2G6H8J1K4L7Z',
        service: 'sbc',
        platform: 'playstation',
        supplier_platform: 'PS',
        quantity: 1,
        sbc: { set_id: 412, times_to_solve: 2 },
        funding: { supplier: 'utt', supplier_order_id: '574339' },
        account: account(),
        ...overrides,
    };
}

/** The store's challenge.ready (schema 1) event, as PublishChallengeReadyEvent composes it. */
export function challengeReady(item = challengeItem(), overrides = {}) {
    return {
        eventId: '01K5A2Q7M3R8X1YQ6H2Z7N9P3C5',
        eventType: 'challenge.ready',
        schemaVersion: 1,
        occurredAt: NOW,
        data: {
            order_public_id: '01K52J0V3B7X2Y9Q5H8N1M4R6T',
            order_number: 'AUT-7K2MQ4',
            order_item_public_id: item.order_item_public_id,
            customer_name: 'فهد العتيبي',
            item,
            ...overrides,
        },
    };
}

/**
 * What the Webhook node hands Verify Request: lower-cased headers, the parsed
 * body, and the raw bytes base64-encoded under binary.data - n8n's in-memory
 * binary shape.
 */
export function webhookRequest(event, { key = 'challenge-publisher', secret = SOLVE_SECRET, timestamp = String(NOW_SECONDS), eventHeader, raw, signature } = {}) {
    // PHP's json_encode: slashes unescaped, unicode escaped - the bytes only
    // match when they are read raw.
    const body = raw ?? JSON.stringify(event).replace(/[-￿]/g, (char) => `\\u${char.charCodeAt(0).toString(16).padStart(4, '0')}`);
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

/** Verify Request, with the given item. */
export async function verified(item = challengeItem(), options = {}) {
    const flow = pipeline({ config: config(), ...options });
    await flow.run('Verify Request', 'verify-request', webhookRequest(challengeReady(item)));

    return flow;
}

/** Verify Request → Validate Set against FFT's list. */
export async function validated(item = challengeItem(), sbcs = fftSbcs()) {
    const flow = await verified(item);
    await flow.run('Validate Set', 'validate-set', sbcs);

    return flow;
}

export function fftSbcs() {
    return [
        { setID: 412, sbcName: 'Team of the Week Upgrade', challengeAmount: 2, expiry: '2035-07-30 19:00:00', consolePrice: 145000, pcPrice: 132000 },
        { setID: 413, sbcName: 'Unpriced', challengeAmount: 1, expiry: '2035-07-30 19:00:00', consolePrice: 0, pcPrice: '' },
    ];
}

/** newSBCAPI's answer: one entry per solve, as a JSON string under data (n8n's autodetect of a text body). */
export function solveAccepted(ids = ['7d0b7f2e-6a1c-4d3f-9b8e-1c2d3e4f5a61', '7d0b7f2e-6a1c-4d3f-9b8e-1c2d3e4f5a62']) {
    return { data: JSON.stringify(ids.map((sbcSolveID) => ({ status: 'processed', sbcSolveID }))) };
}
