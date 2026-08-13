/* eslint-disable */
const config = $('Config').first().json;
const pricingState = $('Evaluate Pricing Read').first().json;

function fail(reason) {
    return [{ json: { ...config, valid: false, failureReason: reason } }];
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

function catalogImage(record) {
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

    return playerReward?.rewardImgURL ?? record.imageURL ?? null;
}

if (!pricingState.valid || !pricingState.pricing) {
    return fail(
        pricingState.failureReason ||
            'Authoritative SBC pricing bases are unavailable',
    );
}

let records = $input.all().map((item) => item.json);
if (records.length === 1 && Array.isArray(records[0]?.sourceRecords))
    records = records[0].sourceRecords;
if (records.length === 1 && Array.isArray(records[0]?.body))
    records = records[0].body;
if (records.length === 1 && Array.isArray(records[0]?.data))
    records = records[0].data;

const settings = config.settings;
if (records.length < settings.sourceMinCount) {
    return fail(
        `EasySBC source is below the minimum complete record count of ${settings.sourceMinCount}`,
    );
}
if (records.length >= settings.sourceLimit) {
    return fail(
        `EasySBC pagination is ambiguous at the configured limit of ${settings.sourceLimit}`,
    );
}

const allowedCategories = new Set([1, 2, 3, 4, 5, 6]);
const allowedModes = new Set(['NON_REPEATABLE', 'UNLIMITED', 'REFRESH']);
const ids = new Set();

for (let index = 0; index < records.length; index += 1) {
    const record = records[index];
    const label = `EasySBC record ${index}`;
    if (!record || typeof record !== 'object' || Array.isArray(record))
        return fail(`${label} is not an object`);
    if (!Number.isInteger(record.id) || record.id <= 0)
        return fail(`${label} id is invalid`);
    if (ids.has(record.id))
        return fail(`EasySBC source contains duplicate id ${record.id}`);
    ids.add(record.id);
    if (
        typeof record.name !== 'string' ||
        !record.name.trim() ||
        record.name.length > 220
    )
        return fail(`${label} name is invalid`);
    if (!allowedCategories.has(record.categoryId))
        return fail(`${label} categoryId is invalid`);
    if (
        record.description != null &&
        (typeof record.description !== 'string' ||
            record.description.length > 4800)
    )
        return fail(`${label} description is invalid`);
    if (!Number.isInteger(record.sbcsCount) || record.sbcsCount <= 0)
        return fail(`${label} sbcsCount is invalid`);
    if (typeof record.repeatable !== 'boolean')
        return fail(`${label} repeatable is invalid`);
    if (!allowedModes.has(record.repeatabilityMode))
        return fail(`${label} repeatabilityMode is invalid`);
    if (!Number.isInteger(record.endTime) || record.endTime <= 0)
        return fail(`${label} endTime is invalid`);
    if (typeof record.active !== 'boolean')
        return fail(`${label} active is invalid`);
    if (!Number.isFinite(Number(record.psPrice)) || Number(record.psPrice) <= 0)
        return fail(`${label} psPrice is invalid`);
    if (!Number.isFinite(Number(record.pcPrice)) || Number(record.pcPrice) <= 0)
        return fail(`${label} pcPrice is invalid`);
    if (
        record.repeats != null &&
        (!Number.isInteger(record.repeats) || record.repeats <= 0)
    )
        return fail(`${label} repeats is invalid`);
    if (record.imageURL != null) {
        if (!isApprovedEasySbcImage(record.imageURL))
            return fail(`${label} imageURL is not an approved EasySBC asset`);
    }
    if (record.rewards != null && !Array.isArray(record.rewards))
        return fail(`${label} rewards is invalid`);
    for (const reward of record.rewards ?? []) {
        if (
            reward &&
            typeof reward === 'object' &&
            !Array.isArray(reward) &&
            reward.type === 'player' &&
            reward.rewardImgURL != null &&
            !isApprovedEasySbcImage(reward.rewardImgURL)
        ) {
            return fail(
                `${label} player rewardImgURL is not an approved EasySBC asset`,
            );
        }
    }
}

const now = Math.floor(new Date(config.generatedAt).getTime() / 1000);
const expiryCutoff = now + settings.minimumExpiryLeadSeconds;
function ineligibilityReason(record) {
    if (!record.active) return 'inactive';
    if (record.endTime <= expiryCutoff) return 'inside_expiry_lead';
    if (/\b(?:bronze|silver)\b/i.test(record.name)) return 'excluded_name';
    if (Number(record.psPrice) < 1500) return 'ps_below_minimum';
    if (!record.repeatable && Number(record.psPrice) < 20_000)
        return 'nonrepeatable_ps_below_minimum';
    if (Number(record.pcPrice) <= 0) return 'pc_not_positive';
    return null;
}

const eligible = records.filter((record) => !ineligibilityReason(record));

if (eligible.length === 0)
    return fail('EasySBC source produced no eligible challenges');

const baseline = settings.approvedBaseline;
if (
    !baseline ||
    !Number.isInteger(baseline.sourceCount) ||
    baseline.sourceCount <= 0 ||
    !Number.isInteger(baseline.eligibleCount) ||
    baseline.eligibleCount <= 0 ||
    baseline.approvedBy !== 'operator' ||
    typeof baseline.observedAt !== 'string' ||
    !baseline.observedAt ||
    typeof baseline.approvedAt !== 'string' ||
    !baseline.approvedAt ||
    !Array.isArray(baseline.eligibleItems) ||
    baseline.eligibleItems.length !== baseline.eligibleCount
) {
    return fail(
        'A manually approved operator bootstrap baseline is required before SBC catalog apply',
    );
}

const globalData = $getWorkflowStaticData('global');
const workflowState = globalData.sbcCatalogV1 ?? {};
const lastCounts = workflowState.lastSuccessfulCounts;
const previousItems = Array.isArray(workflowState.lastSuccessfulItems)
    ? workflowState.lastSuccessfulItems
    : baseline.eligibleItems;
const sourceSafetyFloor = Math.max(
    settings.sourceMinCount,
    lastCounts?.sourceCount
        ? Math.floor(Number(lastCounts.sourceCount) * 0.85)
        : Math.floor(baseline.sourceCount * 0.85),
);
if (records.length < sourceSafetyFloor) {
    return fail(
        `EasySBC source count ${records.length} is below source safety floor of ${sourceSafetyFloor}`,
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
for (const previous of previousItems) {
    if (
        !previous ||
        typeof previous.sourceId !== 'string' ||
        !previous.sourceId ||
        typeof previous.sourceName !== 'string' ||
        !previous.sourceName ||
        typeof previous.expiresAt !== 'string' ||
        !previous.expiresAt ||
        previousIds.has(previous.sourceId)
    ) {
        return fail('SBC safety baseline contains an invalid prior item');
    }
    previousIds.add(previous.sourceId);
    const eligibleRecord = eligibleById.get(previous.sourceId);
    if (eligibleRecord) {
        if (eligibleRecord.name.trim() !== previous.sourceName) {
            return fail(
                `EasySBC source name changed for prior id ${previous.sourceId}`,
            );
        }
        continue;
    }

    const sourceRecord = sourceById.get(previous.sourceId);
    if (sourceRecord) {
        const reason = ineligibilityReason(sourceRecord);
        if (!reason) {
            unexpectedMissing.push(previous.sourceId);
        } else {
            expectedDepartures.push({ sourceId: previous.sourceId, reason });
        }
        continue;
    }

    const previousExpiry = Date.parse(previous.expiresAt);
    if (
        Number.isFinite(previousExpiry) &&
        previousExpiry <= expiryCutoff * 1000
    ) {
        expectedDepartures.push({
            sourceId: previous.sourceId,
            reason: 'expired_or_inside_expiry_lead',
        });
    } else {
        unexpectedMissing.push(previous.sourceId);
    }
}
if (unexpectedMissing.length) {
    return fail(
        `EasySBC has unexpected missing prior IDs: ${unexpectedMissing.join(', ')}`,
    );
}

const priorEligibleAfterExpectedDepartures = Math.max(
    0,
    previousItems.length - expectedDepartures.length,
);
const eligibleSafetyFloor = Math.max(
    1,
    Math.floor(priorEligibleAfterExpectedDepartures * 0.8),
);
if (eligible.length < eligibleSafetyFloor) {
    return fail(
        `EasySBC eligible count ${eligible.length} is below identity-adjusted safety floor of ${eligibleSafetyFloor}`,
    );
}
const newSourceIds = eligible
    .map((record) => String(record.id))
    .filter((sourceId) => !previousIds.has(sourceId));

const translationCache = workflowState.translations ?? {};
function approvedArabicName(record) {
    const sourceName = record.name.trim();
    const cached = translationCache[`${record.id}\u0000${sourceName}`];
    if (!cached)
        throw new Error(
            `Approved Arabic translation is missing for EasySBC id ${record.id}`,
        );
    if (cached.sourceName !== sourceName)
        throw new Error(
            `Approved Arabic translation source name mismatch for EasySBC id ${record.id}`,
        );
    if (
        typeof cached.nameAr !== 'string' ||
        cached.nameAr.length < 2 ||
        cached.nameAr.length > 120 ||
        !/[\u0600-\u06ff]/.test(cached.nameAr) ||
        /[A-Za-z]/.test(cached.nameAr)
    ) {
        throw new Error(
            `Approved translation for EasySBC id ${record.id} must be Arabic-only and at most 120 characters`,
        );
    }
    return cached.nameAr.trim();
}

for (const record of eligible) {
    try {
        approvedArabicName(record);
    } catch (error) {
        return fail(error.message);
    }
}

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

function multiplier(coins) {
    if (coins < 50_000) return 1.15;
    if (coins < 900_000) return 1.1;
    if (coins <= 1_000_000) return 1.0;
    return 1.025;
}

function priceMinor(coins, challengeCount, quote) {
    const baseSar = quote.totalHalalah / 100;
    const sar =
        Math.round(
            challengeCount * 2 +
                coins * multiplier(coins) * 1.02 * (baseSar / 1_000_000) +
                2,
        ) + 3;
    return sar * 100;
}

function variant(record, platform) {
    const isPs = platform === 'playstation';
    const quoteKey = isPs ? 'playstation_fast' : 'pc';
    const coins = Number(isPs ? record.psPrice : record.pcPrice);
    return {
        externalId: `easysbc-sbc-${record.id}-${isPs ? 'ps' : 'pc'}`,
        sku: `SBC-EASYSBC-${record.id}-${isPs ? 'PS' : 'PC'}`,
        platform,
        market: isPs ? 'console' : 'pc',
        currency: 'SAR',
        name: {
            ar: isPs ? 'بلايستيشن' : 'كمبيوتر',
            en: isPs ? 'PlayStation / Xbox' : 'PC',
        },
        priceMinor: priceMinor(
            coins,
            record.sbcsCount,
            pricingState.pricing.quotes[quoteKey],
        ),
        salePriceMinor: null,
        priceVersion: 1,
        active: true,
        configuration: {
            source: 'easysbc',
            sourceId: String(record.id),
            sbcCategory: categoryKey[record.categoryId],
            sourceCategoryId: record.categoryId,
            sourceSlug:
                typeof record.slug === 'string' && record.slug.trim()
                    ? record.slug
                    : safeSlug(record),
            challengeCount: record.sbcsCount,
            completionCount: 1,
            repeatable: record.repeatable,
            repeatabilityMode: record.repeatabilityMode,
            maxRepeats: record.repeatable ? (record.repeats ?? null) : 1,
            sourceCoins: coins,
            expiresAt: new Date(record.endTime * 1000).toISOString(),
            pricingVersion: pricingState.pricing.pricingVersion,
            pricingBase: quoteKey,
            formulaVersion: 'legacy-sbc-one-completion-v1',
        },
    };
}

const products = eligible.map((record, index) => {
    const nameAr = approvedArabicName(record);
    const imageUrl = catalogImage(record);
    return {
        externalId: `easysbc-sbc-${record.id}`,
        categoryExternalId: `easysbc-category-${categoryKey[record.categoryId]}`,
        slug: safeSlug(record),
        serviceType: 'sbc',
        name: { ar: nameAr, en: record.name.trim() },
        description: {
            ar: `أكمل تحدي بناء التشكيلة واحصل على المكافأة داخل حسابك.`,
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

return [
    {
        json: {
            ...config,
            valid: true,
            failureReason: null,
            sourceCount: records.length,
            eligibleCount: products.length,
            sourceSafetyFloor,
            eligibleSafetyFloor,
            expectedDepartures,
            unexpectedMissing: [],
            newSourceIds,
            snapshot: {
                schemaVersion: 1,
                eventId: config.eventId,
                runId: config.runId,
                generatedAt: config.generatedAt,
                completeSnapshot: true,
                categories,
                products,
            },
        },
    },
];
