/* eslint-disable */
const response = $input.first().json;
const statusCode = Number(response.statusCode || 200);

// n8n does not always hand back parsed JSON. One HTTP body has been seen to
// arrive as a parsed object, a JSON string, a real Buffer, n8n's serialised
// { type: 'Buffer', data: [ ... ] }, that same shape with `data` flattened to
// an array-like { '0': 123, ... }, and -- the shape that broke a live run --
// a BARE array of bytes with the Buffer tag stripped off entirely.
//
// The previous guard excluded arrays outright, so the bare form passed straight
// through: a publish that returned HTTP 201 with status "completed" was
// reported as a failure, the alert fired, and the safety baseline was never
// written because the throw lands before the static-data write.
function bytesToString(bytes) {
    let out = '';
    // Chunked so a large body cannot blow the argument limit on String.fromCharCode.
    for (let index = 0; index < bytes.length; index += 8192) {
        out += String.fromCharCode.apply(
            null,
            bytes.slice(index, index + 8192),
        );
    }
    // The payload is UTF-8; decodeURIComponent/escape turns the raw bytes back
    // into correct characters so Arabic names survive the round trip.
    try {
        return decodeURIComponent(escape(out));
    } catch {
        return out;
    }
}

// An array that lost its prototype in serialisation arrives as an array-like
// { '0': 123, '1': 34, ... }, sometimes still carrying a length.
function toByteArray(value) {
    if (Array.isArray(value)) return value;
    if (!value || typeof value !== 'object') return null;

    const keys = Object.keys(value).filter((key) => key !== 'length');

    if (keys.length === 0 || !keys.every((key) => /^\d+$/.test(key))) {
        return null;
    }

    return keys
        .map(Number)
        .sort((left, right) => left - right)
        .map((index) => value[index]);
}

function looksLikeBytes(bytes) {
    return (
        Array.isArray(bytes) &&
        bytes.length > 0 &&
        bytes.every(
            (byte) => Number.isInteger(byte) && byte >= 0 && byte <= 255,
        )
    );
}

// n8n's task runner can hand back the raw Node readable stream instead of a
// body. It is neither a Buffer nor an array, so every tag-based check misses
// it, and the payload sits unread in the stream's internal buffer as chunks:
//   { _readableState: { buffer: [ { type: 'Buffer', data: [ ... ] } ] } }
// A live run failed exactly this way -- HTTP 201, status "completed", and a
// body the workflow could not see.
function bytesFromStream(value) {
    const chunks = value?._readableState?.buffer;

    if (!chunks) return null;

    // Recent Node exposes a plain array; older releases use a linked BufferList.
    let list = chunks;

    if (!Array.isArray(list)) {
        list = [];

        for (let node = chunks.head; node; node = node.next) {
            list.push(node.data);
        }
    }

    const bytes = [];

    for (const chunk of list) {
        const chunkBytes = toByteArray(
            chunk && chunk.type === 'Buffer' ? chunk.data : chunk,
        );

        if (!looksLikeBytes(chunkBytes)) return null;

        // push(...chunkBytes) would blow the argument limit on a large body.
        for (const byte of chunkBytes) bytes.push(byte);
    }

    return bytes.length > 0 ? bytes : null;
}

function decodeHttpBody(raw) {
    if (raw == null) return raw;

    if (typeof raw === 'string') {
        const trimmed = raw.trim();

        if (!trimmed) return raw;

        try {
            return JSON.parse(trimmed);
        } catch {
            return raw;
        }
    }

    if (typeof raw !== 'object') return raw;

    // A real Buffer instance.
    if (
        raw.constructor?.name === 'Buffer' &&
        typeof raw.toString === 'function'
    ) {
        return decodeHttpBody(raw.toString('utf8'));
    }

    // n8n's serialised Buffer. The tag is required, not optional: an ordinary
    // { data: [...] } response would otherwise be mistaken for bytes.
    if (raw.type === 'Buffer') {
        const tagged = toByteArray(raw.data);

        if (looksLikeBytes(tagged)) {
            return decodeHttpBody(bytesToString(tagged));
        }
    }

    // The untagged form, which is what actually reached production. Accepted
    // only when the bytes decode to JSON, so a genuine array response -- every
    // provider list is one -- is handed back untouched rather than destroyed.
    if (looksLikeBytes(raw)) {
        try {
            const parsed = JSON.parse(bytesToString(raw).trim());

            if (parsed && typeof parsed === 'object') return parsed;
        } catch {
            // Not bytes after all. Fall through and return it unchanged.
        }
    }

    // The unconsumed stream. Only claimed when the buffered chunks parse as
    // complete JSON -- a stream holds only what has been read so far, so a body
    // larger than the buffer would otherwise be silently truncated.
    const streamed = bytesFromStream(raw);

    if (looksLikeBytes(streamed)) {
        try {
            const parsed = JSON.parse(bytesToString(streamed).trim());

            if (parsed && typeof parsed === 'object') return parsed;
        } catch {
            // Truncated or not JSON. Fall through and return it unchanged.
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
