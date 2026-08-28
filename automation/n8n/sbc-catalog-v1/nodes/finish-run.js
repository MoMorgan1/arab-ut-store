/* eslint-disable */
// Confirms Laravel accepted the snapshot, then commits the safety baseline.
// v3 split this across "Evaluate Publish Result", a "Publish OK?" IF, and
// "Success Summary" -- three nodes to decide one boolean and write one object.

const response = $input.first().json;
const signed = $('Sign Catalog Snapshot').first().json;
const snapshot = signed.catalogSnapshot;
const statusCode = Number(response.statusCode || 0);

// n8n does not always hand back parsed JSON. Depending on the response headers
// it can return the body as a Buffer, which arrives here as
// { type: 'Buffer', data: [ ... ] }. That is an object, so a `typeof === string`
// check skips it -- and then `body.data` reads the BYTE ARRAY rather than the
// payload, `body.data.runId` is undefined, and a publish that actually
// succeeded is reported as a failure. Worse, the throw lands before the safety
// baseline is written, so the run publishes, alerts, and records nothing.
// Written without depending on the Buffer global, which is not guaranteed to be
// exposed inside an n8n Code node sandbox.
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

function decodeHttpBody(raw) {
    if (raw == null) return raw;

    if (typeof raw === 'object' && !Array.isArray(raw)) {
        // n8n's serialised Buffer. The `type === 'Buffer'` tag is required, not
        // optional: a perfectly ordinary JSON response of the form { data: [...] }
        // would otherwise be mistaken for bytes and destroyed.
        if (raw.type === 'Buffer' && Array.isArray(raw.data)) {
            return decodeHttpBody(bytesToString(raw.data));
        }
        // A real Buffer instance.
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
    throw new Error(
        `[publish] Laravel did not confirm the catalog snapshot (HTTP ${statusCode || 'unknown'}); response: ${JSON.stringify(body).slice(0, 400)}`,
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
