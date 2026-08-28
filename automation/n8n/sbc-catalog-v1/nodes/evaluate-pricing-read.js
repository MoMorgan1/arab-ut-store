/* eslint-disable */
const response = $input.first().json;
const statusCode = Number(response.statusCode || 200);

// n8n does not always hand back parsed JSON -- depending on the response
// headers it returns the body as a Buffer, which arrives here as
// { type: 'Buffer', data: [ ... ] }. That is an object, so a `typeof === string`
// check silently skips it and every field read below comes back undefined.
function bytesToString(bytes) {
    let out = '';
    for (let index = 0; index < bytes.length; index += 8192) {
        out += String.fromCharCode.apply(
            null,
            bytes.slice(index, index + 8192),
        );
    }
    try {
        return decodeURIComponent(escape(out));
    } catch {
        return out;
    }
}

function decodeHttpBody(raw) {
    if (raw == null) return raw;
    if (typeof raw === 'object' && !Array.isArray(raw)) {
        // The `type === 'Buffer'` tag is required: an ordinary { data: [...] }
        // response must not be mistaken for bytes.
        if (raw.type === 'Buffer' && Array.isArray(raw.data)) {
            return decodeHttpBody(bytesToString(raw.data));
        }
        if (
            raw.constructor?.name === 'Buffer' &&
            typeof raw.toString === 'function'
        ) {
            return decodeHttpBody(raw.toString('utf8'));
        }
    }
    if (typeof raw === 'string') {
        const trimmed = raw.trim();
        if (!trimmed) return raw;
        try {
            return JSON.parse(trimmed);
        } catch {
            return raw;
        }
    }
    return raw;
}

const body = decodeHttpBody(response.body ?? response);

function reject(reason) {
    throw new Error(`[pricing_read] ${reason}`);
}

// Order-insensitive: an object with the expected keys in any order is fine.
function exactKeys(value, expected) {
    if (!value || typeof value !== 'object' || Array.isArray(value))
        return false;
    const actual = Object.keys(value).sort();
    return JSON.stringify(actual) === JSON.stringify([...expected].sort());
}

if (
    response.error ||
    response.errorMessage ||
    statusCode !== 200 ||
    !body ||
    typeof body !== 'object' ||
    Array.isArray(body)
) {
    reject(`Laravel pricing read failed with HTTP ${statusCode}`);
}

if (
    !exactKeys(body, ['schemaVersion', 'pricingVersion', 'pricedAt', 'quotes'])
) {
    reject(
        `Pricing response shape is unexpected; keys: ${Object.keys(body).join(', ')}`,
    );
}

if (
    body.schemaVersion !== 1 ||
    !Number.isInteger(body.pricingVersion) ||
    body.pricingVersion < 1 ||
    typeof body.pricedAt !== 'string'
) {
    reject('Pricing response metadata is invalid');
}

const pricedAtMs = Date.parse(body.pricedAt);
const nowMs = Date.now();
if (!Number.isFinite(pricedAtMs)) reject('pricedAt is not a valid timestamp');
if (nowMs - pricedAtMs > 15 * 60 * 1000) {
    reject('Authoritative pricing is older than 15 minutes');
}
if (pricedAtMs - nowMs > 5 * 60 * 1000) {
    reject('Authoritative pricing timestamp is too far in the future');
}

const globalData = $getWorkflowStaticData('global');
const previousPricingVersion = globalData.sbcCatalog?.lastPricingVersion;
if (
    Number.isInteger(previousPricingVersion) &&
    body.pricingVersion < previousPricingVersion
) {
    reject(
        `Pricing version rollback detected (${body.pricingVersion} < ${previousPricingVersion})`,
    );
}

if (!exactKeys(body.quotes, ['playstation_fast', 'pc'])) {
    reject(
        'Pricing response must contain exactly the PlayStation fast and PC quotes',
    );
}

const expected = {
    playstation_fast: { platform: 'playstation', delivery: 'fast' },
    pc: { platform: 'pc', delivery: null },
};

for (const [key, identity] of Object.entries(expected)) {
    const quote = body.quotes[key];
    if (
        !exactKeys(quote, ['platform', 'delivery', 'quantity', 'totalHalalah'])
    ) {
        reject(`${key} quote has an unexpected shape`);
    }
    if (
        quote.platform !== identity.platform ||
        quote.delivery !== identity.delivery ||
        quote.quantity !== 1_000_000
    ) {
        reject(`${key} is not the authoritative one-million quote`);
    }
    if (!Number.isInteger(quote.totalHalalah) || quote.totalHalalah <= 0) {
        reject(`${key} totalHalalah is invalid`);
    }
}

return [
    {
        json: {
            pricing: body,
            pricingAgeSeconds: Math.max(
                0,
                Math.round((nowMs - pricedAtMs) / 1000),
            ),
        },
    },
];
