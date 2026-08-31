/* eslint-disable */
// Confirms Laravel accepted the snapshot, then commits the safety baseline.
// v3 split this across "Evaluate Publish Result", a "Publish OK?" IF, and
// "Success Summary" -- three nodes to decide one boolean and write one object.

const response = $input.first().json;
const signed = $('Sign Catalog Snapshot').first().json;
const snapshot = signed.catalogSnapshot;
const statusCode = Number(response.statusCode || 0);

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

const completed =
    statusCode === 201 &&
    body?.data?.runId === snapshot.runId &&
    body?.data?.status === 'completed' &&
    Number.isInteger(body?.data?.applied) &&
    Number.isInteger(body?.data?.archived);

// A replay means this exact eventId already landed; the catalog is in the state
// we intended, so it counts as success.
const replayed =
    statusCode === 409 && body?.error?.code === 'catalog_snapshot_replayed';

if (!completed && !replayed) {
    // Names the field that actually disagreed. The old message printed the raw
    // body, which for an undecoded byte array was several hundred integers and
    // said nothing about which check had failed.
    const observed = {
        statusCode: statusCode || 'unknown',
        runId: body?.data?.runId ?? null,
        status: body?.data?.status ?? null,
        applied: body?.data?.applied ?? null,
        archived: body?.data?.archived ?? null,
        bodyType: Array.isArray(body) ? 'array' : typeof body,
    };

    throw new Error(
        `[publish] Laravel did not confirm the catalog snapshot (HTTP ${statusCode || 'unknown'}); expected HTTP 201 for runId ${snapshot.runId}; observed ${JSON.stringify(observed)}; body: ${JSON.stringify(body).slice(0, 300)}`,
    );
}

const globalData = $getWorkflowStaticData('global');
const state = globalData.sbcCatalog ?? {};

const lastSuccessfulItems = snapshot.products.map((product) => ({
    sourceId: String(product.variants[0].configuration.sourceId),
    sourceName: product.name.en,
    expiresAt: product.variants[0].configuration.expiresAt,
}));

const lastPricingVersion = Number(
    snapshot.products?.[0]?.variants?.[0]?.configuration?.pricingVersion,
);

globalData.sbcCatalog = {
    ...state,
    lastSuccessfulCounts: {
        sourceCount: Number(signed.sourceCount),
        eligibleCount: Number(signed.eligibleCount),
        completedAt: new Date().toISOString(),
    },
    lastSuccessfulItems,
    lastPricingVersion: Number.isInteger(lastPricingVersion)
        ? lastPricingVersion
        : state.lastPricingVersion,
    lastSuccessfulSourceAudit: signed.sourceAudit ?? null,
    lastSuccessfulPricingAudit: signed.pricingAudit ?? null,
    bootstrapCompletedAt:
        state.bootstrapCompletedAt ?? new Date().toISOString(),
};

return [
    {
        json: {
            status: replayed ? 'replayed' : 'published',
            triggerSource: signed.triggerSource,
            runId: snapshot.runId,
            eventId: snapshot.eventId,
            products: snapshot.products.length,
            variants: snapshot.products.reduce(
                (total, product) => total + product.variants.length,
                0,
            ),
            applied: body?.data?.applied ?? null,
            archived: body?.data?.archived ?? null,
            bootstrapMode: signed.bootstrapMode ?? null,
            expectedDepartures: signed.expectedDepartures ?? [],
            renamedSourceIds: signed.renamedSourceIds ?? [],
            newSourceIds: signed.newSourceIds ?? [],
            sourceAudit: signed.sourceAudit ?? null,
            pricingAudit: signed.pricingAudit ?? null,
            publishResponse: body,
        },
    },
];
