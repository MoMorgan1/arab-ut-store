/* eslint-disable */
// FFT is authoritative for availability and current console/PC coin requirements.
// EasySBC supplies metadata, repeatability, image, category, and a cross-source
// identity check.
//
// Tolerance policy: both providers are third parties that routinely serve a few
// partial rows. Every per-record problem is SKIPPED and counted; the run only
// fails when a ratio shows the feed itself is broken. v3 threw on the first
// invalid EasySBC record, which is what took the catalog down.

const FFT_NODE = 'Fetch FFT SBCs';
const META_NODE = 'Fetch EasySBC Sets';

const config = $('Config').first().json;
const limits = config.settings.source;
const eligibility = config.settings.eligibility;

function fail(reason) {
    throw new Error(`[merge_sources] ${reason}`);
}

function finiteNumber(value) {
    if (value && typeof value === 'object') {
        for (const key of ['amount', 'value', 'price']) {
            if (value[key] !== undefined) return finiteNumber(value[key]);
        }
        return null;
    }
    // null / '' / false must not read as 0 here: a missing price has to be
    // rejected, not silently priced at zero.
    if (value == null || value === '' || typeof value === 'boolean')
        return null;
    // Providers occasionally send thousands separators or padded strings.
    const normalized =
        typeof value === 'string' ? value.replace(/[\s,_]/g, '') : value;
    const number = Number(normalized);
    return Number.isFinite(number) ? number : null;
}

function timestampSeconds(value) {
    const numeric = finiteNumber(value);
    if (numeric && numeric > 0) {
        return numeric > 10000000000
            ? Math.floor(numeric / 1000)
            : Math.floor(numeric);
    }
    if (typeof value === 'string' && value.trim()) {
        const parsed = Date.parse(value);
        if (Number.isFinite(parsed)) return Math.floor(parsed / 1000);
    }
    return null;
}

function normalizeName(value) {
    return String(value ?? '')
        .normalize('NFKD')
        .replace(/[\u0300-\u036f]/g, '')
        .toLowerCase()
        .replace(/[^a-z0-9]+/g, ' ')
        .trim();
}

// Both providers key the same SBC by the same integer. Resolve to one canonical
// string so "007" and 7 join. v3 used a different key precedence per source, so
// a row carrying both an auxiliary `id` and a `setID` resolved to a different
// identity on each side and silently failed to join.
const ID_KEYS = ['setID', 'setId', 'id'];

function canonicalId(record) {
    for (const key of ID_KEYS) {
        const raw = record[key];
        if (raw == null) continue;
        const text = String(raw).trim();
        if (!text) continue;
        const numeric = Number(text);
        return Number.isSafeInteger(numeric) && numeric > 0
            ? String(numeric)
            : text;
    }
    return '';
}

// The provider bodies arrive through the same set of shapes the publish
// response does; left undecoded the harvester walks a byte array, finds
// nothing, and reports "0 unique usable records" while the provider was in
// fact answering perfectly. Applied once to the body in recordsFromNode
// rather than inside the recursive walk, so a nested numeric array deep in a
// provider record can never be mistaken for an encoded document.
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

function parseJsonString(value) {
    // The `type === 'Buffer'` tag is required: an ordinary { data: [...] }
    // provider response must not be mistaken for bytes.
    if (
        value &&
        typeof value === 'object' &&
        !Array.isArray(value) &&
        value.type === 'Buffer' &&
        Array.isArray(value.data)
    ) {
        return parseJsonString(bytesToString(value.data));
    }
    if (typeof value !== 'string') return value;
    const trimmed = value.trim();
    if (!trimmed || !['[', '{'].includes(trimmed[0])) return value;
    try {
        return JSON.parse(trimmed);
    } catch {
        return value;
    }
}

// Harvesting is deliberately STRUCTURAL: an id plus a price key present. It is
// not the place to judge whether the prices are good -- that happens below, so
// that a bad row is counted in the invalid ratio instead of vanishing before
// anyone can see it.
//
// The v3 bug here was not the loose test, it was that a match STOPPED the
// recursion (see collectMatchingRecords): a page envelope such as
// { id: 'page-3', pcPrice: null, sets: [...] } was harvested as one bogus
// record and its real children were never collected at all.
function isMatchingRecord(value, priceKeys) {
    if (!value || typeof value !== 'object' || Array.isArray(value))
        return false;
    if (!canonicalId(value)) return false;
    return priceKeys.some((key) => value[key] !== undefined);
}

function hasNestedContainer(value) {
    return Object.values(value).some(
        (child) => child && typeof child === 'object',
    );
}

function collectMatchingRecords(value, priceKeys, output, seen, depth) {
    if (depth > 8) return output;
    const parsed = parseJsonString(value);

    if (Array.isArray(parsed)) {
        for (const child of parsed) {
            collectMatchingRecords(child, priceKeys, output, seen, depth + 1);
        }
        return output;
    }
    if (!parsed || typeof parsed !== 'object') return output;

    const matches = isMatchingRecord(parsed, priceKeys);
    if (matches && !seen.has(parsed)) {
        seen.add(parsed);
        output.push(parsed);
    }

    // Keep descending even after a match unless this really is a leaf record, so
    // an envelope that happens to look like a record cannot swallow its children.
    if (!matches || hasNestedContainer(parsed)) {
        for (const child of Object.values(parsed)) {
            if (child && typeof child === 'object') {
                collectMatchingRecords(
                    child,
                    priceKeys,
                    output,
                    seen,
                    depth + 1,
                );
            }
        }
    }
    return output;
}

function recordsFromNode(nodeName, priceKeys) {
    const nodeItems = $(nodeName).all();
    const records = [];
    const seen = new Set();
    for (const item of nodeItems) {
        const json = item.json ?? {};
        // Decode the body itself before harvesting, never the nested values.
        const source =
            json.body === undefined
                ? json
                : { ...json, body: decodeHttpBody(json.body) };

        collectMatchingRecords(source, priceKeys, records, seen, 0);
    }
    return { records, nodeItems };
}

function firstItemShape(nodeItems) {
    const firstJson = nodeItems[0]?.json;
    if (Array.isArray(firstJson)) return '(root array)';
    if (firstJson && typeof firstJson === 'object') {
        return Object.keys(firstJson).slice(0, 20).join(', ') || '(no keys)';
    }
    return String(typeof firstJson);
}

// Decode before previewing, and never let the preview itself throw. An
// undecoded body prints as a 200-character wall of integers, which is the
// noise this workflow already lost two debugging rounds to; a body that is
// still a live stream carries circular references, and a bare
// JSON.stringify on it throws and masks the status being reported.
function previewBody(raw) {
    const decoded = decodeHttpBody(raw ?? null);

    if (typeof decoded === 'string') return decoded.slice(0, 200);

    try {
        return JSON.stringify(decoded ?? null).slice(0, 200);
    } catch {
        return `[unserialisable ${typeof decoded}]`;
    }
}

// Both fetches run with neverError + fullResponse so a bad status reaches here
// as data instead of killing the node with n8n's own message. Check it FIRST:
// without this a provider 500 surfaces further down as "0 unique usable
// records", which points the on-call at the wrong system entirely.
function assertHttpOk(nodeName) {
    for (const item of $(nodeName).all()) {
        const json = item.json ?? {};
        if (json.statusCode === undefined) continue;
        const status = Number(json.statusCode);
        if (!Number.isFinite(status) || status < 200 || status >= 300) {
            const body = previewBody(json.body);
            fail(
                `${nodeName} returned HTTP ${json.statusCode}; body starts: ${body}`,
            );
        }
    }
}

assertHttpOk(FFT_NODE);
assertHttpOk(META_NODE);

const fftSource = recordsFromNode(FFT_NODE, ['consolePrice', 'pcPrice']);
// EasySBC's FC27 API dropped psPrice/pcPrice entirely - a set now carries
// remainingCost instead - so a marker list of price keys alone harvested zero
// records the day the season turned over, and the run died three nodes later
// claiming there was nothing to translate. sbcsCount is the honest marker: it
// is the squad count this node joins on, so a record without it is unusable
// anyway, and no page envelope carries one.
const metadataSource = recordsFromNode(META_NODE, [
    'sbcsCount',
    'remainingCost',
    'psPrice',
    'pcPrice',
]);

/* --------------------------------------------------------------- FFT side */

const fftById = new Map();
const fftInvalidIds = [];
const fftDuplicateIds = [];

for (const record of fftSource.records) {
    const id = canonicalId(record);
    const consolePrice = finiteNumber(record.consolePrice ?? record.psPrice);
    const pcPrice = finiteNumber(record.pcPrice);
    const challengeAmount = finiteNumber(
        record.challengeAmount ?? record.sbcsCount ?? record.challengeCount,
    );

    if (
        !id ||
        !(consolePrice > 0) ||
        !(pcPrice > 0) ||
        !(challengeAmount > 0)
    ) {
        fftInvalidIds.push(id || '(no id)');
        continue;
    }
    if (fftById.has(id)) {
        fftDuplicateIds.push(id);
        continue;
    }
    fftById.set(id, record);
}
// Duplicate FFT ids resolve first-wins, which is arbitrary for the price
// AUTHORITY. v3 counted them and then never looked at the number. A handful is
// provider noise; a lot means the feed is repeating pages and whichever copy
// arrived first is setting prices./* ----------------------------------------------------------- EasySBC side */

const metadataById = new Map();
const metadataInvalidIds = [];
const metadataDuplicateIds = [];

// EasySBC supplies IDENTITY AND METADATA. Its prices are not used for any SBC
// that FFT lists -- those take consolePrice/pcPrice from FFT, which is the price
// authority. EasySBC's own prices are read in exactly one place, the
// `fft_missing` archive branch below, and that branch checks them itself.
//
// So requiring a price here was rejecting perfectly sellable products over a
// field this node never reads for them. It cost the catalog two ~1M-coin player
// SBCs (Marcelo, Rafael Leao) that carry psPrice but no pcPrice.
for (const record of metadataSource.records) {
    const id = canonicalId(record);
    const name = typeof record.name === 'string' ? record.name.trim() : '';

    if (!id || !name) {
        metadataInvalidIds.push(id || '(no id)');
        continue;
    }
    if (metadataById.has(id)) {
        metadataDuplicateIds.push(id);
        continue;
    }
    metadataById.set(id, record);
}

// Check the RAW parsed count, not the post-validation unique count. v3 tested
// metadataById.size, so a provider returning a full page of 200 that included
// one bad row read as 199 and the silent truncation went unnoticed.
if (metadataSource.records.length >= limits.metadataLimit) {
    fail(
        `EasySBC returned ${metadataSource.records.length} records at the configured limit of ${limits.metadataLimit}; pagination is ambiguous`,
    );
}

// THE v3.2.6 OUTAGE: this was `if (invalid > 0 || duplicate > 0) throw`.
// Three cosmetic metadata rows stopped every price in the store. Invalid rows
// are already quarantined above and can no longer reach the merge, so the only
// thing worth failing on is a ratio that says the whole feed is broken./* -------------------------------------------------------------- Merge */

const mergedRecords = [];
const identityMismatchIds = [];
const priceDisagreementIds = [];
// Which source set the coin basis for each eligible set. A run where these
// swap is a run where the store's prices moved for a reason nobody chose.
const marketBasisIds = [];
const fftBasisIds = [];
const priceDisagreements = [];
const challengeMismatchIds = [];
const missingFftIds = [];
const missingFftUnusableIds = [];
const droppedNoChallengeIds = [];
const droppedNoPriceIds = [];
const droppedNoExpiryIds = [];

// Distinguish "the provider did not tell us" from "the provider said 1". v3
// coalesced both to 1 via Math.max(1, ...), so its cross-source squad check
// silently passed on rows where neither side had supplied a count at all.
function challengeCount(record, keys) {
    for (const key of keys) {
        const value = finiteNumber(record[key]);
        if (value != null && value > 0) return Math.max(1, Math.round(value));
    }
    return null;
}

/**
 * How many times a set may be completed, or null for "no stated cap".
 *
 * FC27 sends `repeats: 0` on every set, where FC26 left the field absent on the
 * ones with no limit. Downstream, 0 is not a smaller cap - it is the same thing
 * as absent, and reading it literally makes an UNLIMITED set look like one that
 * may be completed zero times. The catalogue node then rejects the record as
 * corrupt and the whole season goes unpublished, which is exactly what happened
 * on 2026-09-16: all eight FC27 sets were dropped as `bad_repeats`.
 */
function repeatCap(value) {
    const repeats = finiteNumber(value);

    return repeats != null && repeats > 0 ? Math.round(repeats) : null;
}

/**
 * Whether FFT's coin price and EasySBC's are too far apart to both be prices.
 *
 * Returns null when they agree well enough, or the evidence when they do not.
 * An absent or zero EasySBC price is not evidence of anything - plenty of sets
 * carry no market figure - so those pass straight through on FFT's word.
 *
 * The two bounds stopped meaning the same thing when the basis moved to
 * EasySBC. FFT far ABOVE the market is still the dangerous case: we pay FFT,
 * so it means the solve costs more than the customer is charged - that is the
 * 100,700,000-coin Gold Upgrade. FFT far BELOW is now margin rather than
 * risk, and the lower bound is only here to catch a zero-ish typo, which is
 * why it sits an order of magnitude under the widest real spread seen.
 */
function priceDisagreement(consolePrice, meta) {
    const reference = finiteNumber(meta.psPrice ?? meta.pcPrice);

    if (reference == null || reference <= 0) return null;

    const ratio = consolePrice / reference;
    const { maxProviderPriceRatio: max, minProviderPriceRatio: min } =
        eligibility;

    if (ratio <= max && ratio >= min) return null;

    return { fft: consolePrice, metadata: Math.round(reference), ratio };
}

for (const [id, meta] of metadataById) {
    const fft = fftById.get(id);

    if (!fft) {
        const metaPsPrice = Math.round(finiteNumber(meta.psPrice) ?? 0);
        const metaPcPrice = Math.round(finiteNumber(meta.pcPrice) ?? 0);
        const metaChallenges = challengeCount(meta, [
            'sbcsCount',
            'challengeAmount',
        ]);
        const metaExpiry = timestampSeconds(meta.endTime ?? meta.expiry);

        if (
            metaPsPrice > 0 &&
            metaPcPrice > 0 &&
            metaChallenges &&
            metaExpiry
        ) {
            // Keep the identity in the complete snapshot but mark it unavailable.
            // Laravel archives it; a later trusted FFT return restores it.
            mergedRecords.push({
                ...meta,
                id: Number.isSafeInteger(Number(id)) ? Number(id) : id,
                name: String(meta.name ?? `SBC ${id}`).trim(),
                psPrice: metaPsPrice,
                pcPrice: metaPcPrice,
                sbcsCount: metaChallenges,
                challengeAmount: metaChallenges,
                endTime: metaExpiry,
                repeats: repeatCap(meta.repeats),
                active: false,
                source: 'fft_missing',
                fftSetID: id,
            });
            missingFftIds.push(id);
        } else {
            // FFT does not list it AND its metadata is too thin to archive it
            // properly. v3 counted this in missingFftIds too, mixing two different
            // exclusions into one number.
            missingFftUnusableIds.push(id);
        }
        continue;
    }

    const fftName = fft.sbcName ?? fft.name ?? fft.title;
    const metaName = meta.name;
    if (
        !fftName ||
        !metaName ||
        normalizeName(fftName) !== normalizeName(metaName)
    ) {
        identityMismatchIds.push(id);
        continue;
    }

    const fftChallenges = challengeCount(fft, [
        'challengeAmount',
        'sbcsCount',
        'challengeCount',
    ]);
    const metaChallenges = challengeCount(meta, [
        'sbcsCount',
        'challengeAmount',
    ]);
    // Only a genuine disagreement counts; an absent count on one side is not
    // evidence of drift.
    if (fftChallenges && metaChallenges && fftChallenges !== metaChallenges) {
        challengeMismatchIds.push(id);
        continue;
    }
    const challenges = fftChallenges ?? metaChallenges;
    if (!challenges) {
        droppedNoChallengeIds.push(id);
        continue;
    }

    const consolePrice = Math.round(
        finiteNumber(fft.consolePrice ?? fft.psPrice) ?? 0,
    );
    const pcPrice = Math.round(finiteNumber(fft.pcPrice) ?? 0);
    if (!(consolePrice > 0) || !(pcPrice > 0)) {
        droppedNoPriceIds.push(id);
        continue;
    }

    // Owner decision 2026-09-17: the COIN BASIS is EasySBC's, not FFT's.
    //
    // FFT stays the availability authority - a set it does not list, or lists
    // at zero, is one nobody can be sold - but the coin figure the storefront
    // charges for now comes from the market. The two are not measuring the
    // same thing: FFT quotes what it charges to solve a squad out of its own
    // card stock, EasySBC reads what the cards cost on the open market, and on
    // 2026-09-17 they were 21x apart on Intro to SBCs - 212 against 5,750,
    // with FUT.GG's independent 4,550 agreeing with EasySBC. Two market
    // sources against one service quote, and the service quote is the one that
    // can quietly put a challenge on sale below what the coins to fill it
    // cost. Where EasySBC carries no figure, FFT's stands: a price from the
    // party we actually pay beats no price at all.
    const marketConsole = Math.round(finiteNumber(meta.psPrice) ?? 0);
    const marketPc = Math.round(finiteNumber(meta.pcPrice) ?? 0);
    // Both platforms or neither. Half a basis is not a basis: a market
    // console figure beside an FFT PC one prices the two platforms off
    // different markets, and the result reads as a platform discount nobody
    // chose.
    const marketPrices = marketConsole > 0 && marketPc > 0;
    const basisConsole = marketPrices ? marketConsole : consolePrice;
    const basisPc = marketPrices ? marketPc : pcPrice;

    if (marketPrices) marketBasisIds.push(id);
    else fftBasisIds.push(id);

    // The price authority is FFT, and this does not second-guess it: EasySBC
    // is only asked whether FFT's figure is a price at all. The two describe
    // the same squad from different angles - FFT builds it, EasySBC reads the
    // market - so they never match, but they stay in the same order of
    // magnitude. When they are thousands of times apart, one of them has a
    // typo in it, and publishing a typo means either selling a real challenge
    // for nothing or listing one at a price no customer would ever pay.
    //
    // Skipped for this run only, and counted: the next hour republishes it the
    // moment the provider corrects itself. Owner decision 2026-09-17, after
    // FFT priced a one-squad Gold Upgrade at 100,700,000 coins.
    const disagreement = priceDisagreement(consolePrice, meta);
    if (disagreement) {
        priceDisagreementIds.push(id);
        priceDisagreements.push({ id, ...disagreement });
        continue;
    }

    const fftExpiry = timestampSeconds(fft.expiry ?? fft.endTime);
    const metaExpiry = timestampSeconds(meta.endTime ?? meta.expiry);
    const expiries = [fftExpiry, metaExpiry].filter(
        (value) => value && value > 0,
    );
    const endTime = expiries.length ? Math.min(...expiries) : null;
    if (!endTime) {
        droppedNoExpiryIds.push(id);
        continue;
    }

    mergedRecords.push({
        ...meta,
        id: Number.isSafeInteger(Number(id)) ? Number(id) : id,
        name: String(metaName).trim(),
        psPrice: basisConsole,
        pcPrice: basisPc,
        sbcsCount: challenges,
        challengeAmount: challenges,
        endTime,
        repeats: repeatCap(meta.repeats),
        // FFT is the availability authority. EasySBC's active flag is metadata only.
        active: true,
        source: 'fft',
        fftSetID: id,
    });
}

// Cross-provider drift is normal when the two fetches land either side of an EA
// edit. A drifting id is already skipped by the join above, which is the whole
// protection: a challenge whose two providers disagree never reaches the store.
const mismatchCount = identityMismatchIds.length + challengeMismatchIds.length;const exactMatches = mergedRecords.filter(
    (record) => record.source === 'fft',
).length;
// v3 and early v4 had ONE metric here -- exactMatches / all EasySBC metadata --
// and used it to answer two unrelated questions at once. That conflation is why
// a healthy feed read as 77.4% and failed an 85% gate.
//
// FFT is a coin-farming service. It structurally does not sell daily freebie
// upgrades or OVR Token Swaps, because those are not bought with coins. EasySBC
// lists them anyway. Their absence is the correct answer, not a fault, and the
// count of them moves every time EA ships another token swap -- so it can never
// be a stable denominator for a safety threshold.

// 1) JOIN INTEGRITY -- the safety property. Of the SBCs BOTH providers describe,
//    how many agree on name and squad count? This is the only thing verifying
//    that FFT's setID 412 and EasySBC's id 412 are the same challenge. It should
//    sit at ~100%; anything less means the id spaces are drifting apart and
//    prices could attach to the wrong product.
const joinable =
    exactMatches +
    mismatchCount +
    droppedNoChallengeIds.length +
    droppedNoPriceIds.length +
    droppedNoExpiryIds.length;
const joinIntegrity = exactMatches / Math.max(1, joinable);
// 2) FFT COVERAGE -- business reality, not integrity. What share of EasySBC's
//    catalog does FFT sell at all? Normal is well under 100% and drifts with
//    EA's content mix, so the floor here is deliberately loose: it exists to
//    catch FFT's feed collapsing, not to police the overlap.
const fftCoverage = joinable / Math.max(1, metadataById.size);
// Kept for the audit trail and for comparison against previous runs.
const matchRate = exactMatches / Math.max(1, metadataById.size);

const fftOnlyIds = [...fftById.keys()].filter((id) => !metadataById.has(id));

return [
    {
        json: {
            body: mergedRecords,
            // Every counter below is now reported honestly. v3 hardcoded the mismatch
            // fields to 0, which was only "true" because the sole way past that point
            // was for them to be 0.
            sourceAudit: {
                authority: 'fft',
                metadata: 'easysbc',
                fftN8nItems: fftSource.nodeItems.length,
                fftParsed: fftSource.records.length,
                fftUniqueUsable: fftById.size,
                fftInvalid: fftInvalidIds.length,
                fftDuplicates: fftDuplicateIds.length,
                fftOnlyCount: fftOnlyIds.length,
                // NOT truncated: Build & Price Snapshot uses this list to tell a real
                // departure from an SBC that FFT still sells but EasySBC stopped
                // describing. A truncated list would silently archive live products.
                fftOnlyIds,
                metadataParsed: metadataSource.records.length,
                metadataUniqueUsable: metadataById.size,
                metadataInvalid: metadataInvalidIds.length,
                metadataInvalidIds: metadataInvalidIds.slice(0, 50),
                metadataDuplicates: metadataDuplicateIds.length,
                sourceRecords: mergedRecords.length,
                exactMatches,
                identityMismatches: identityMismatchIds.length,
                identityMismatchIds: identityMismatchIds.slice(0, 50),
                challengeMismatches: challengeMismatchIds.length,
                challengeMismatchIds: challengeMismatchIds.slice(0, 50),
                droppedNoChallenge: droppedNoChallengeIds.length,
                droppedNoPrice: droppedNoPriceIds.length,
                priceDisagreements: priceDisagreementIds.length,
                priceDisagreementIds: priceDisagreementIds.slice(0, 50),
                marketBasisCount: marketBasisIds.length,
                fftBasisCount: fftBasisIds.length,
                fftBasisIds: fftBasisIds.slice(0, 50),
                priceDisagreementSamples: priceDisagreements.slice(0, 10),
                droppedNoExpiry: droppedNoExpiryIds.length,
                missingFftCount: missingFftIds.length,
                missingFftIds: missingFftIds.slice(0, 50),
                missingFftUnusableCount: missingFftUnusableIds.length,
                missingFftUnusableIds: missingFftUnusableIds.slice(0, 50),
                matchRate,
                matchRateBps: Math.round(matchRate * 10000),
                joinable,
                joinIntegrity,
                joinIntegrityBps: Math.round(joinIntegrity * 10000),
                fftCoverage,
                fftCoverageBps: Math.round(fftCoverage * 10000),
            },
        },
    },
];
