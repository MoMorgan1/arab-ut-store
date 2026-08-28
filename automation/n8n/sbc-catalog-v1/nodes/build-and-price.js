/* eslint-disable */
// Builds the catalog snapshot AND prices it, in one pass.
//
// v3 split this across "Prepare SBC Snapshot" and "Apply FFT Pricing Policy".
// Prepare computed a full legacy price for every tier, then Apply overwrote
// every one of those totals -- but left the legacy `multiplierBps` in the
// payload. The store was therefore told, at the 100-completion tier, that it
// was giving a 24% bulk discount (7600 bps) while the price it actually
// charged discounted 12.4%. There is now exactly one formula, and multiplierBps
// is derived from the prices that are really charged.
//
// Pricing policy:
//   1) The FFT coin requirement already contains its provider-side cushion.
//   2) Add Arab UT's own configurable coin buffer (approved default 5%).
//   3) Convert buffered coins with the signed retail 1M quote.
//   4) Add the automation cost for every submitted squad.
//   5) Add service margin and one fixed order fee.
//   6) Round up to the next whole SAR.
// Commercial and platform adjustments scale SERVICE MARGIN only; they can never
// reduce retail coin value, automation cost, or the fixed order fee.

const config = $('Config').first().json;
const pricingState = $('Evaluate Pricing Read').first().json;
const settings = config.settings;
const policyInput = settings.pricingPolicy;

const MAX_ARABIC_TITLE_VISIBLE_LENGTH = 40;
const TRANSLATION_SCHEMA_VERSION = 4;
const LRI = '\u2066';
const PDI = '\u2069';
const BIDI_CONTROL_PATTERN = /[\u2066\u2067\u2068\u2069]/g;

function fail(reason) {
    throw new Error(`[snapshot] ${reason}`);
}

function stripBidiControls(value) {
    return String(value ?? '').replace(BIDI_CONTROL_PATTERN, '');
}

function toEnglishDigits(value) {
    return String(value ?? '')
        .replace(/[٠-٩]/g, (digit) => String(digit.charCodeAt(0) - 0x0660))
        .replace(/[۰-۹]/g, (digit) => String(digit.charCodeAt(0) - 0x06f0));
}

function isolateDirectionalRuns(value) {
    return stripBidiControls(value).replace(
        /(?:[A-Za-z]+[A-Za-z0-9+&/.\-]*|\+\d+(?:[-–]\d+)?|\d+(?:[-–]\d+)?)/g,
        (token) => `${LRI}${token}${PDI}`,
    );
}

function isApprovedEasySbcImage(url) {
    const prefix = 'https://assets.easysbc.io/';
    return (
        typeof url === 'string' &&
        url.length > prefix.length &&
        url.length <= 2048 &&
        url.startsWith(prefix) &&
        !/[\s\\]/.test(url)
    );
}

/* ------------------------------------------------------- policy validation */

function integerInRange(value, minimum, maximum, label) {
    const number = Number(value);
    if (
        value == null ||
        value === '' ||
        !Number.isInteger(number) ||
        number < minimum ||
        number > maximum
    ) {
        fail(
            `policy ${label} must be an integer between ${minimum} and ${maximum}`,
        );
    }
    return number;
}

function numberInRange(value, minimum, maximum, label) {
    const number = Number(value);
    if (
        value == null ||
        value === '' ||
        !Number.isFinite(number) ||
        number < minimum ||
        number > maximum
    ) {
        fail(
            `policy ${label} must be a number between ${minimum} and ${maximum}`,
        );
    }
    return number;
}

// v3 passed this table through raw and only validated it lazily, inside the
// first repeatable tier it happened to price. A malformed table therefore blew
// up mid-run, after partial work, or never got checked at all.
function validateRepeatTable(raw) {
    if (!raw || typeof raw !== 'object' || Array.isArray(raw)) {
        fail('policy repeatServiceMarginPerRunMinor is missing');
    }
    const table = new Map();
    for (const [key, value] of Object.entries(raw)) {
        const threshold = Number(key);
        if (!Number.isInteger(threshold) || threshold < 1) {
            fail(
                `policy repeat margin threshold "${key}" is not a positive integer`,
            );
        }
        table.set(
            threshold,
            numberInRange(value, 0, 10000, `repeat margin at ${threshold}`),
        );
    }
    if (!table.size) fail('policy repeat service-margin table is empty');
    // Without a tier 1 the smallest defined tier becomes the fallback, so a
    // single-run order would silently receive volume pricing.
    if (!table.has(1))
        fail('policy repeat service-margin table must define tier 1');
    return table;
}

// Each rate is range-checked on its own, but that says nothing about the total.
// The margin contribution is `completions * rate(completions)`, and because the
// rate STEPS DOWN at each threshold that product can fall as completions rise --
// e.g. {1:500, 50:100} gives 40 runs 20000 but 50 runs only 5000. Every rate
// there is individually valid. Catch it here, against the table alone, rather
// than discovering it mid-priced-catalog and aborting the run.
function assertMarginLadderIsSane(table, thresholds, ladder) {
    let previousContribution = -Infinity;
    let previousCompletions = null;
    for (const completions of ladder) {
        let selected = thresholds[0];
        for (const threshold of thresholds) {
            if (completions >= threshold) selected = threshold;
        }
        const contribution = completions * table.get(selected);
        if (contribution < previousContribution) {
            fail(
                `policy repeatServiceMarginPerRunMinor is not monotonic in total: ${previousCompletions} runs contribute ${previousContribution} but ${completions} runs contribute ${contribution}; a bigger bundle would earn less margin than a smaller one`,
            );
        }
        previousContribution = contribution;
        previousCompletions = completions;
    }
}

const policy = {
    formulaVersion:
        typeof policyInput?.formulaVersion === 'string' &&
        policyInput.formulaVersion.trim()
            ? policyInput.formulaVersion.trim()
            : fail('policy formulaVersion is missing'),
    ownerCoinBufferBps: integerInRange(
        policyInput?.ownerCoinBufferBps,
        0,
        2000,
        'ownerCoinBufferBps',
    ),
    automationCostPerSquadMinor: numberInRange(
        policyInput?.automationCostPerSquadMinor,
        0,
        10000,
        'automationCostPerSquadMinor',
    ),
    nonRepeatServiceMarginPerSquadMinor: numberInRange(
        policyInput?.nonRepeatServiceMarginPerSquadMinor,
        0,
        10000,
        'nonRepeatServiceMarginPerSquadMinor',
    ),
    fixedOrderFeeMinor: numberInRange(
        policyInput?.fixedOrderFeeMinor,
        0,
        100000,
        'fixedOrderFeeMinor',
    ),
    // Must be a whole number of SAR. Validate Snapshot rejects any tier total
    // that is not a multiple of 100, so a 250-halalah floor would price fine here
    // and then fail the run every time the floor actually binds.
    minimumPriceMinor: (() => {
        const value = numberInRange(
            policyInput?.minimumPriceMinor,
            0,
            1000000,
            'minimumPriceMinor',
        );
        if (!Number.isInteger(value) || value % 100 !== 0) {
            fail(
                `policy minimumPriceMinor must be a whole number of SAR (a multiple of 100); got ${value}`,
            );
        }
        return value;
    })(),
    // 10000 bps = 1.0x = neutral. These are multipliers on the service margin,
    // not surcharges; the 5000-20000 band brackets neutral on both sides. The two
    // adjustments compound: commercial x platform.
    commercialAdjustmentBps: integerInRange(
        policyInput?.commercialAdjustmentBps,
        5000,
        20000,
        'commercialAdjustmentBps',
    ),
    platformAdjustmentBps: {
        playstation: integerInRange(
            policyInput?.platformAdjustmentBps?.playstation,
            5000,
            20000,
            'platformAdjustmentBps.playstation',
        ),
        pc: integerInRange(
            policyInput?.platformAdjustmentBps?.pc,
            5000,
            20000,
            'platformAdjustmentBps.pc',
        ),
    },
    repeatMargin: validateRepeatTable(
        policyInput?.repeatServiceMarginPerRunMinor,
    ),
};

const repeatThresholds = [...policy.repeatMargin.keys()].sort((a, b) => a - b);

function repeatMarginPerRun(completions) {
    let selected = repeatThresholds[0];
    for (const threshold of repeatThresholds) {
        if (completions >= threshold) selected = threshold;
    }
    return policy.repeatMargin.get(selected);
}

// Read only the field the pricing contract defines. v3's generic probe would
// fall back to an `amount`/`value`/`price` key, which on a quote object could
// silently read a display price and undercharge by two orders of magnitude.
function quoteMinor(platform) {
    const quotes = pricingState.pricing?.quotes;
    const quote = platform === 'pc' ? quotes?.pc : quotes?.playstation_fast;
    const total = quote?.totalHalalah;
    if (!Number.isInteger(total) || total <= 0 || total > 100000) {
        fail(`signed one-million ${platform} quote is missing or out of band`);
    }
    if (Number(quote.quantity) !== 1000000) {
        fail(`signed ${platform} quote is not the one-million quote`);
    }
    return total;
}

const quoteByPlatform = {
    playstation: quoteMinor('playstation'),
    pc: quoteMinor('pc'),
};

// The completion counts offered per repeatable bundle.
const STANDARD_REPEAT_COMPLETIONS = [5, 10, 15, 20, 30, 40, 50, 75, 100];

assertMarginLadderIsSane(
    policy.repeatMargin,
    repeatThresholds,
    [1, 2, 3, 4].concat(STANDARD_REPEAT_COMPLETIONS),
);

// The coin term is a float division, so a total that is mathematically an exact
// whole SAR can land one ulp above it and get ceiled to the next whole riyal --
// a silent 1 SAR overcharge. Snap to 6 decimal places of a halalah first; that
// is far finer than any real price and far coarser than the float noise.
function ceilWholeSar(minor) {
    const snapped = Math.round(minor * 1e6) / 1e6;
    return Math.ceil(snapped / 100) * 100;
}

function calculatePrice({
    rawFftCoins,
    squads,
    completions,
    platform,
    repeatable,
}) {
    if (!(rawFftCoins > 0)) fail(`FFT ${platform} coin requirement is missing`);
    if (!Number.isInteger(squads) || squads < 1) fail('squad count is invalid');
    if (!Number.isInteger(completions) || completions < 1)
        fail('completion count is invalid');

    const effectiveCoinsPerCompletion = Math.ceil(
        rawFftCoins * (1 + policy.ownerCoinBufferBps / 10000),
    );
    const retailMillionMinor = quoteByPlatform[platform];
    const bufferedCoinRetailMinor =
        (effectiveCoinsPerCompletion * completions * retailMillionMinor) /
        1000000;
    const automationMinor =
        squads * completions * policy.automationCostPerSquadMinor;
    const baseServiceMarginMinor = repeatable
        ? completions * repeatMarginPerRun(completions)
        : squads * policy.nonRepeatServiceMarginPerSquadMinor;

    const protectedBaseMinor =
        bufferedCoinRetailMinor + automationMinor + policy.fixedOrderFeeMinor;
    const adjustedServiceMarginMinor =
        baseServiceMarginMinor *
        (policy.commercialAdjustmentBps / 10000) *
        (policy.platformAdjustmentBps[platform] / 10000);

    // The service margin is always >= 0, so protectedBase is always contained in
    // commercialPrice. v3 also took the max against protectedBase separately,
    // which could never bind.
    // The configured floor is applied as configured. v3 ceiled it to the next
    // whole SAR, silently turning a 2.50 SAR minimum into a 3.00 SAR one.
    const finalPriceMinor = Math.max(
        policy.minimumPriceMinor,
        ceilWholeSar(protectedBaseMinor + adjustedServiceMarginMinor),
    );

    return {
        finalPriceMinor,
        effectiveCoinsPerCompletion,
        bufferedCoinRetailMinor,
        automationMinor,
        adjustedServiceMarginMinor,
        protectedBaseMinor,
    };
}

/* --------------------------------------------------------- source records */

let records = $input.all().map((item) => item.json);
if (records.length === 1 && Array.isArray(records[0]?.sourceRecords)) {
    records = records[0].sourceRecords;
}
if (records.length === 1 && Array.isArray(records[0]?.body)) {
    records = records[0].body;
}

if (records.length < settings.sourceMinCount) {
    fail(
        `merged source holds ${records.length} records; minimum is ${settings.sourceMinCount}`,
    );
}

const allowedCategories = new Set([1, 2, 3, 4, 5, 6]);
const allowedModes = new Set(['NON_REPEATABLE', 'UNLIMITED', 'REFRESH']);

// One record-validation pass. v3 ran this same loop twice, in Prepare
// Translations and again in Prepare SBC Snapshot, and the two copies had already
// drifted apart on the repeatable check.
//
// It SKIPS AND COUNTS rather than throwing on the first offender. Merge Provider
// Sources only ratio-screens id, price, squad count and expiry; every field
// below is one this node is the first to look at. Throwing here on a single
// cosmetic value -- a category id EA has not used before, a new repeatability
// mode -- would recreate the exact v3.2.6 outage one node further down the line.
function recordRejection(record) {
    if (!record || typeof record !== 'object' || Array.isArray(record))
        return 'not_an_object';
    if (!Number.isInteger(record.id) || record.id <= 0) return 'bad_id';
    if (
        typeof record.name !== 'string' ||
        !record.name.trim() ||
        record.name.length > 220
    ) {
        return 'bad_name';
    }
    if (!allowedCategories.has(record.categoryId)) return 'bad_category';
    if (
        record.description != null &&
        (typeof record.description !== 'string' ||
            record.description.length > 4800)
    ) {
        return 'bad_description';
    }
    if (!Number.isInteger(record.sbcsCount) || record.sbcsCount <= 0)
        return 'bad_sbcs_count';
    if (typeof record.repeatable !== 'boolean') return 'bad_repeatable';
    if (!allowedModes.has(record.repeatabilityMode))
        return 'bad_repeatability_mode';
    if (!Number.isInteger(record.endTime) || record.endTime <= 0)
        return 'bad_end_time';
    if (typeof record.active !== 'boolean') return 'bad_active';
    if (!Number.isFinite(Number(record.psPrice)) || Number(record.psPrice) <= 0)
        return 'bad_ps_price';
    if (!Number.isFinite(Number(record.pcPrice)) || Number(record.pcPrice) <= 0)
        return 'bad_pc_price';
    if (
        record.repeats != null &&
        (!Number.isInteger(record.repeats) || record.repeats <= 0)
    ) {
        return 'bad_repeats';
    }
    if (record.imageURL != null && !isApprovedEasySbcImage(record.imageURL))
        return 'bad_image_url';
    if (record.rewards != null && !Array.isArray(record.rewards))
        return 'bad_rewards';
    for (const reward of record.rewards ?? []) {
        if (
            reward &&
            typeof reward === 'object' &&
            !Array.isArray(reward) &&
            reward.type === 'player' &&
            reward.rewardImgURL != null &&
            !isApprovedEasySbcImage(reward.rewardImgURL)
        ) {
            return 'bad_reward_image_url';
        }
    }
    return null;
}

const ids = new Set();
const rejectedRecords = [];
const usableRecords = [];

for (const record of records) {
    const rejection = recordRejection(record);
    if (rejection) {
        rejectedRecords.push({ id: record?.id ?? null, reason: rejection });
        continue;
    }
    // A duplicate id is a different class of problem: it means the merge produced
    // two rows for one SBC, which would publish two products for one identity.
    // That is a real defect upstream, not provider noise, so it still fails.
    if (ids.has(record.id))
        fail(`merged source contains duplicate id ${record.id}`);
    ids.add(record.id);
    usableRecords.push(record);
}

const rejectedRatio = rejectedRecords.length / Math.max(1, records.length);
if (rejectedRatio > settings.source.maxInvalidMetadataRatio) {
    const sample = rejectedRecords
        .slice(0, 10)
        .map((r) => `${r.id ?? '?'}:${r.reason}`)
        .join(', ');
    fail(
        `${(rejectedRatio * 100).toFixed(1)}% of merged records failed catalog validation (max ${(settings.source.maxInvalidMetadataRatio * 100).toFixed(0)}%); sample: ${sample}`,
    );
}

records = usableRecords;

/* ------------------------------------------------------------ eligibility */

// config.generatedAt is the ELIGIBILITY CLOCK: the instant the run started, so
// every expiry decision in this run is made against one consistent moment.
// Validate the clock BEFORE using it. An unparseable generatedAt makes `now`
// NaN, and every `endTime <= NaN` comparison is false -- so the expiry filter
// silently passes everything and the store sells challenges that can no longer
// be completed. It fails nothing and looks like a normal run.
const generatedAtMs = Date.parse(config.generatedAt);
if (!Number.isFinite(generatedAtMs)) {
    fail(
        `config.generatedAt is not a parseable timestamp: ${JSON.stringify(config.generatedAt)}`,
    );
}
if (
    !Number.isInteger(settings.minimumExpiryLeadSeconds) ||
    settings.minimumExpiryLeadSeconds < 0
) {
    fail(
        `settings.minimumExpiryLeadSeconds must be a non-negative integer; got ${JSON.stringify(settings.minimumExpiryLeadSeconds)}`,
    );
}

const now = Math.floor(generatedAtMs / 1000);
const expiryCutoff = now + settings.minimumExpiryLeadSeconds;
const excludedNamePattern = new RegExp(
    settings.eligibility.excludedNamePattern,
    'i',
);

// Canary. This pattern lives in Config as a STRING, so a lost backslash turns
// \b into a backspace character and the whole exclusion silently matches
// nothing -- the filter appears to run and quietly passes every bronze and
// silver SBC through to the storefront. Prove it works before trusting it.
if (
    !excludedNamePattern.test('Bronze Challenge') ||
    !excludedNamePattern.test('10x Silver Upgrade') ||
    excludedNamePattern.test('87+ Player Pick')
) {
    fail(
        `eligibility.excludedNamePattern is not behaving as a word-boundary filter (got ${JSON.stringify(settings.eligibility.excludedNamePattern)}); check for a lost backslash in Config`,
    );
}

function isRepeatableBundle(record) {
    return (
        record.repeatable === true &&
        (record.repeats == null || record.repeats > 1)
    );
}

function ineligibilityReason(record) {
    if (!record.active) return 'inactive';
    if (record.endTime <= expiryCutoff) return 'inside_expiry_lead';
    if (excludedNamePattern.test(record.name)) return 'excluded_name';
    if (Number(record.psPrice) < settings.eligibility.minConsoleCoins)
        return 'ps_below_minimum';
    if (
        !isRepeatableBundle(record) &&
        Number(record.psPrice) <
            settings.eligibility.minNonRepeatableConsoleCoins
    ) {
        return 'nonrepeatable_ps_below_minimum';
    }
    if (Number(record.pcPrice) <= 0) return 'pc_not_positive';
    return null;
}

const eligible = records.filter((record) => !ineligibilityReason(record));
if (eligible.length === 0)
    fail('merged source produced no eligible challenges');

/* ---------------------------------------------------- safety baseline */

const globalData = $getWorkflowStaticData('global');
const workflowState = globalData.sbcCatalog ?? {};

// The baseline is ADVISORY and must never be load-bearing for liveness. It is
// written by whichever version of this workflow ran last, so any schema drift
// would otherwise fail every future run forever, with no way out but manually
// clearing n8n static data. A baseline that does not parse is treated as no
// baseline at all: the run drops to bootstrap and says so in the audit.
function readBaseline(raw) {
    if (!Array.isArray(raw) || raw.length === 0)
        return { items: [], reason: 'no prior run' };
    const seen = new Set();
    for (const entry of raw) {
        if (
            !entry ||
            typeof entry.sourceId !== 'string' ||
            !entry.sourceId ||
            typeof entry.sourceName !== 'string' ||
            !entry.sourceName ||
            typeof entry.expiresAt !== 'string' ||
            !entry.expiresAt ||
            seen.has(entry.sourceId)
        ) {
            return {
                items: [],
                reason: 'prior baseline was malformed and has been discarded',
            };
        }
        seen.add(entry.sourceId);
    }
    return { items: raw, reason: null };
}

const baseline = readBaseline(workflowState.lastSuccessfulItems);
const hasSuccessfulBaseline = baseline.items.length > 0;
const bootstrapMode = !hasSuccessfulBaseline;
const baselineDiscardReason = hasSuccessfulBaseline ? null : baseline.reason;
const lastCounts = workflowState.lastSuccessfulCounts;
const previousItems = baseline.items;

const priorSourceCount = Number(lastCounts?.sourceCount);
const sourceSafetyFloor =
    hasSuccessfulBaseline &&
    Number.isFinite(priorSourceCount) &&
    priorSourceCount > 0
        ? Math.max(settings.sourceMinCount, Math.floor(priorSourceCount * 0.85))
        : settings.sourceMinCount;

if (records.length < sourceSafetyFloor) {
    fail(
        `merged source count ${records.length} is below the safety floor of ${sourceSafetyFloor}`,
    );
}

const sourceById = new Map(
    records.map((record) => [String(record.id), record]),
);
const eligibleById = new Map(
    eligible.map((record) => [String(record.id), record]),
);
const previousIds = new Set();
const expectedDepartures = [];
const unexpectedMissing = [];
const renamedSourceIds = [];
const metadataOnlyMissing = [];

// Ids FFT still lists that EasySBC no longer describes. Merge Provider Sources
// cannot emit these as products, but they are NOT gone from the market, so the
// departure classifier below must not treat them as such.
const mergeAudit = $('Merge Provider Sources').first().json.sourceAudit ?? {};
const fftOnlyIds = new Set((mergeAudit.fftOnlyIds ?? []).map(String));

for (const previous of previousItems) {
    // Shape was already checked by readBaseline(); a bad baseline never gets here.
    previousIds.add(previous.sourceId);
    const eligibleRecord = eligibleById.get(previous.sourceId);
    if (eligibleRecord) {
        // v3 failed the whole run when a provider renamed an SBC. Providers fix
        // typos; the id is the identity, so record the rename and carry on.
        if (eligibleRecord.name.trim() !== previous.sourceName) {
            renamedSourceIds.push({
                sourceId: previous.sourceId,
                from: previous.sourceName,
                to: eligibleRecord.name.trim(),
            });
        }
        continue;
    }

    const sourceRecord = sourceById.get(previous.sourceId);
    if (sourceRecord) {
        const reason = ineligibilityReason(sourceRecord);
        if (!reason) unexpectedMissing.push(previous.sourceId);
        else expectedDepartures.push({ sourceId: previous.sourceId, reason });
        continue;
    }

    // FFT still lists it, only EasySBC lost the metadata. Archiving it would pull
    // a product we can still sell. The merge cannot emit it (no category, name or
    // image without metadata), so the honest thing is to flag it rather than
    // quietly call it a departure.
    if (fftOnlyIds.has(previous.sourceId)) {
        metadataOnlyMissing.push(previous.sourceId);
        continue;
    }

    // Gone from BOTH providers. EA pulls SBCs early, and Laravel archives anything
    // absent from a complete snapshot, so this is a real departure and not a
    // reason to freeze the catalog until the stored expiry passes.
    expectedDepartures.push({
        sourceId: previous.sourceId,
        reason: 'absent_from_both_providers',
    });
}

if (unexpectedMissing.length) {
    fail(
        `prior IDs vanished from the eligible set while still eligible at source: ${unexpectedMissing.join(', ')}`,
    );
}

// A trickle is normal provider lag. A flood means EasySBC is broken and we are
// about to archive a chunk of the live catalog on the strength of that.
const metadataOnlyMissingRatio =
    metadataOnlyMissing.length / Math.max(1, previousItems.length);
if (metadataOnlyMissingRatio > settings.source.maxMismatchRatio) {
    fail(
        `${(metadataOnlyMissingRatio * 100).toFixed(1)}% of previously published SBCs are still listed by FFT but lost their EasySBC metadata (max ${(settings.source.maxMismatchRatio * 100).toFixed(0)}%); ids: ${metadataOnlyMissing.slice(0, 20).join(', ')}`,
    );
}

const priorEligibleAfterExpectedDepartures = Math.max(
    0,
    previousItems.length - expectedDepartures.length,
);
const eligibleSafetyFloor = hasSuccessfulBaseline
    ? Math.max(1, Math.floor(priorEligibleAfterExpectedDepartures * 0.8))
    : Math.max(1, Number(settings.bootstrapMinimumEligibleCount) || 20);

if (eligible.length < eligibleSafetyFloor) {
    fail(
        `eligible SBC count ${eligible.length} is below the safety floor of ${eligibleSafetyFloor}`,
    );
}

// The floor above credits every departure, so it collapses as the catalog does:
// if EA expired 114 of 120 SBCs overnight, all 114 land in expectedDepartures,
// the floor drops to ~1, and a six-product catalog publishes with
// completeSnapshot:true -- telling Laravel to archive the rest. This second
// floor is deliberately NOT departure-credited, so a catastrophic shrink halts
// for a human even when every individual departure looks explainable.
const absoluteEligibleFloor = hasSuccessfulBaseline
    ? Math.max(1, Math.floor(previousItems.length * 0.5))
    : 1;
if (eligible.length < absoluteEligibleFloor) {
    fail(
        `eligible SBC count ${eligible.length} is less than half the ${previousItems.length} previously published; every departure was individually explainable, which is exactly why this needs a human before Laravel archives the difference`,
    );
}

const newSourceIds = eligible
    .map((record) => String(record.id))
    .filter((sourceId) => !previousIds.has(sourceId));

/* ------------------------------------------------------------ translation */

const translationCache = workflowState.translations ?? {};

function approvedArabicName(record) {
    const sourceName = record.name.trim();
    const cached = translationCache[`${record.id}\u0000${sourceName}`];
    if (!cached)
        fail(`approved Arabic translation is missing for SBC id ${record.id}`);
    if (cached.sourceName !== sourceName) {
        fail(
            `approved Arabic translation source name mismatch for SBC id ${record.id}`,
        );
    }

    const visible = toEnglishDigits(stripBidiControls(cached.nameAr))
        .replace(/\bUpgrade\b/gi, 'ترقية')
        .replace(/\s+/g, ' ')
        .trim();

    if (
        cached.schemaVersion !== TRANSLATION_SCHEMA_VERSION ||
        visible.length < 2 ||
        visible.length > MAX_ARABIC_TITLE_VISIBLE_LENGTH ||
        !/[\u0600-\u06ff]/.test(visible) ||
        /[٠-٩۰-۹]/.test(visible)
    ) {
        fail(
            `approved translation for SBC id ${record.id} must contain Arabic, use English digits, and be at most ${MAX_ARABIC_TITLE_VISIBLE_LENGTH} visible characters`,
        );
    }
    return isolateDirectionalRuns(visible);
}

/* ---------------------------------------------------------------- catalog */

const categoryKey = {
    1: 'players',
    2: 'upgrades',
    3: 'upgrades',
    4: 'icons',
    5: 'foundations',
    6: 'upgrades',
};
const categories = [
    ['players', 'اللاعبون', 'Players', 10],
    ['upgrades', 'التطويرات', 'Upgrades', 20],
    ['icons', 'الأيكونز', 'Icons', 30],
    ['foundations', 'الأساسيات', 'Foundations', 40],
].map(([key, ar, en, sortOrder]) => ({
    externalId: `easysbc-category-${key}`,
    slug: `sbc-${key}`,
    name: { ar, en },
    description: {
        ar: `تحديات بناء التشكيلات: ${ar}`,
        en: `${en} squad-building challenges`,
    },
    sortOrder,
    visible: true,
}));

function safeSlug(record) {
    const raw =
        typeof record.slug === 'string' && record.slug.trim()
            ? record.slug
            : record.name;
    const base =
        raw
            .normalize('NFKD')
            .replace(/[\u0300-\u036f]/g, '')
            .toLowerCase()
            .replace(/[^a-z0-9]+/g, '-')
            .replace(/^-+|-+$/g, '')
            .slice(0, 220) || 'challenge';
    return `sbc-${base}-${record.id}`;
}

// multiplierBps is a FIXED POLICY CONSTANT, not a computed discount.
//
// Laravel validates it with `!==` against this exact table
// (app/ValueObjects/Pricing/SbcCompletionPricing.php::expectedTiers), so any
// other value is rejected outright with "An SBC completion tier is outside the
// supported policy". An earlier v4 build derived these from the real prices;
// that made the numbers self-consistent and made the publish fail on every
// repeatable tier. The store owns this table, so the workflow mirrors it.
//
// The consequence is worth stating plainly: this percentage does NOT describe
// the price beside it. The admin price dialog renders it verbatim
// (admin-variant-price-dialog.tsx:424) while the price comes from the
// fft-plus-owner-buffer-v2 formula. No customer sees it. Reconciling the two is
// a store-side decision, not something the workflow can fix by sending
// different numbers.
const STANDARD_REPEAT_TIERS = [
    [5, 10000],
    [10, 9500],
    [15, 9200],
    [20, 9000],
    [30, 8700],
    [40, 8500],
    [50, 8200],
    [75, 7800],
    [100, 7600],
];

// Mirrors SbcCompletionPricing::expectedTiers() branch for branch.
function repeatTierDefinitions(maximum) {
    if (maximum == null || maximum >= 100) return STANDARD_REPEAT_TIERS;
    if (maximum < 5) {
        return Array.from({ length: maximum }, (_, index) => [
            index + 1,
            10000,
        ]);
    }
    const tiers = STANDARD_REPEAT_TIERS.filter(
        ([completions]) => completions <= maximum,
    );
    const last = tiers[tiers.length - 1];
    if (last[0] !== maximum)
        tiers.push([maximum, Math.max(7000, last[1] - 200)]);
    return tiers;
}

const audit = {
    repricedProducts: 0,
    repricedVariants: 0,
    repricedTiers: 0,
    minimumContributionMinor: null,
    maximumContributionMinor: null,
    samples: [],
};

function completionPricing(record, rawFftCoins, platform) {
    const squads = record.sbcsCount;
    const repeatable = isRepeatableBundle(record);
    const maximum = repeatable ? (record.repeats ?? null) : 1;
    const tierDefinitions = repeatable
        ? repeatTierDefinitions(record.repeats ?? null)
        : [[1, 10000]];

    const priced = tierDefinitions.map(([completions, multiplierBps]) => ({
        completions,
        multiplierBps,
        result: calculatePrice({
            rawFftCoins,
            squads,
            completions,
            platform,
            repeatable,
        }),
    }));

    const tiers = priced.map(({ completions, multiplierBps, result }) => {
        audit.repricedTiers += 1;
        // Rounded: the coin term is a float, so an unrounded difference publishes
        // artefacts like 1234.5600000000002 into the audit payload.
        const contributionMinor = Math.round(
            result.finalPriceMinor -
                result.bufferedCoinRetailMinor -
                result.automationMinor,
        );
        audit.minimumContributionMinor =
            audit.minimumContributionMinor == null
                ? contributionMinor
                : Math.min(audit.minimumContributionMinor, contributionMinor);
        audit.maximumContributionMinor =
            audit.maximumContributionMinor == null
                ? contributionMinor
                : Math.max(audit.maximumContributionMinor, contributionMinor);

        if (audit.samples.length < 12) {
            audit.samples.push({
                sourceId: String(record.id),
                product: record.name,
                platform,
                completions,
                rawFftCoins,
                effectiveCoins: result.effectiveCoinsPerCompletion,
                coinRetailMinor: Math.round(result.bufferedCoinRetailMinor),
                automationMinor: Math.round(result.automationMinor),
                serviceMarginMinor: Math.round(
                    result.adjustedServiceMarginMinor,
                ),
                fixedOrderFeeMinor: policy.fixedOrderFeeMinor,
                totalMinor: result.finalPriceMinor,
            });
        }

        return {
            completions,
            multiplierBps,
            totalMinor: result.finalPriceMinor,
        };
    });

    // Buying more must never cost less in total. This is a genuine commercial
    // invariant on the prices we charge, and it stays even though multiplierBps
    // is now a fixed constant rather than something derived from these numbers.
    for (let index = 1; index < tiers.length; index += 1) {
        if (tiers[index].totalMinor < tiers[index - 1].totalMinor) {
            fail(
                `SBC id ${record.id} ${platform} price is not monotonic: ${tiers[index - 1].completions} runs cost ${tiers[index - 1].totalMinor} but ${tiers[index].completions} runs cost ${tiers[index].totalMinor}`,
            );
        }
    }

    return { version: 1, repeatable, maximum, tiers };
}

function variant(record, platform) {
    const isPs = platform === 'playstation';
    const rawFftCoins = Number(isPs ? record.psPrice : record.pcPrice);
    const pricing = completionPricing(record, rawFftCoins, platform);
    const effectiveCoins = Math.ceil(
        rawFftCoins * (1 + policy.ownerCoinBufferBps / 10000),
    );

    audit.repricedVariants += 1;

    return {
        externalId: `easysbc-sbc-${record.id}-${isPs ? 'ps' : 'pc'}`,
        sku: `SBC-EASYSBC-${record.id}-${isPs ? 'PS' : 'PC'}`,
        platform,
        market: isPs ? 'console' : 'pc',
        currency: 'SAR',
        name: {
            ar: isPs ? 'سوني / إكس بوكس' : 'بي سي',
            en: isPs ? 'PlayStation / Xbox' : 'PC',
        },
        // The smallest offered bundle is the storefront's headline price; for a
        // repeatable SBC that is deliberately the 5-run bundle, and
        // configuration.completionCount records which bundle it is.
        priceMinor: pricing.tiers[0].totalMinor,
        salePriceMinor: null,
        priceVersion: 1,
        active: true,
        configuration: {
            source: 'fft',
            sourceId: String(record.id),
            sbcCategory: categoryKey[record.categoryId],
            sourceCategoryId: record.categoryId,
            sourceSlug:
                typeof record.slug === 'string' && record.slug.trim()
                    ? record.slug
                    : safeSlug(record),
            challengeCount: record.sbcsCount,
            completionCount: pricing.tiers[0].completions,
            repeatable: pricing.repeatable,
            repeatabilityMode: record.repeatabilityMode,
            maxRepeats: pricing.maximum,
            rawFftCoins: Math.round(rawFftCoins),
            ownerCoinBufferBps: policy.ownerCoinBufferBps,
            sourceCoins: effectiveCoins,
            expiresAt: new Date(record.endTime * 1000).toISOString(),
            pricingVersion: pricingState.pricing.pricingVersion,
            pricingBase: isPs ? 'playstation_fast' : 'pc',
            formulaVersion: policy.formulaVersion,
            completionPricing: pricing,
        },
    };
}

const products = eligible.map((record, index) => {
    const nameAr = approvedArabicName(record);
    const playerReward = Array.isArray(record.rewards)
        ? record.rewards.find(
              (reward) =>
                  reward &&
                  typeof reward === 'object' &&
                  !Array.isArray(reward) &&
                  reward.type === 'player' &&
                  typeof reward.rewardImgURL === 'string' &&
                  reward.rewardImgURL.length > 0,
          )
        : null;
    const imageUrl = playerReward?.rewardImgURL ?? record.imageURL ?? null;

    audit.repricedProducts += 1;

    return {
        externalId: `easysbc-sbc-${record.id}`,
        categoryExternalId: `easysbc-category-${categoryKey[record.categoryId]}`,
        slug: safeSlug(record),
        serviceType: 'sbc',
        name: { ar: nameAr, en: record.name.trim() },
        description: {
            ar: 'أكمل تحدي بناء التشكيلة واحصل على المكافأة داخل حسابك.',
            en: record.description?.trim() || `Complete ${record.name}.`,
        },
        sortOrder: index + 1,
        visible: true,
        variants: [variant(record, 'playstation'), variant(record, 'pc')],
        media: imageUrl
            ? [
                  {
                      url: imageUrl,
                      alt: { ar: nameAr, en: record.name.trim() },
                      sortOrder: 0,
                  },
              ]
            : [],
    };
});

if (!products.length) fail('catalog snapshot contains no products');

// Laravel requires microsecond precision; JS gives milliseconds.
const snapshotGeneratedAt = new Date()
    .toISOString()
    .replace(/\.(\d{3})Z$/, '.$1000Z');

return [
    {
        json: {
            ...config,
            sourceCount: records.length,
            eligibleCount: products.length,
            sourceSafetyFloor,
            eligibleSafetyFloor,
            bootstrapMode,
            baselineDiscardReason,
            expectedDepartures,
            renamedSourceIds,
            metadataOnlyMissing,
            rejectedRecordCount: rejectedRecords.length,
            rejectedRecords: rejectedRecords.slice(0, 50),
            newSourceIds,
            sourceAudit:
                $('Merge Provider Sources').first().json.sourceAudit ?? null,
            pricingAudit: {
                ...audit,
                policy: {
                    ...policy,
                    repeatMargin: Object.fromEntries(policy.repeatMargin),
                },
                providerPriceAssumption:
                    'FFT includes its displayed provider cushion; Arab UT adds the configured owner buffer on top',
            },
            catalogSnapshot: {
                schemaVersion: 1,
                eventId: config.eventId,
                runId: config.runId,
                // Stamped HERE, not copied from Config. Laravel rejects a stale
                // generatedAt at publish time and translation can add minutes before
                // this node runs. v3 restamped it in a later node for the same reason;
                // doing it at build time keeps the value honest, because it really is
                // when this snapshot was built, and validate/sign/publish follow at once.
                generatedAt: snapshotGeneratedAt,
                completeSnapshot: true,
                categories,
                products,
            },
        },
    },
];
