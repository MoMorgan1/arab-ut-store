/* eslint-disable */
const config = $('Config').first().json;
const pricingState = $('Evaluate Pricing Read').first().json;

function fail(reason) {
    return [{ json: { ...config, valid: false, failureReason: reason } }];
}

if (!pricingState.valid || !pricingState.pricing) {
    return fail(
        pricingState.failureReason ||
            'Authoritative SBC pricing bases are unavailable',
    );
}

let records = $input.all().map((item) => item.json);
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
        try {
            const image = new URL(record.imageURL);
            if (
                image.protocol !== 'https:' ||
                image.hostname !== 'assets.easysbc.io'
            )
                return fail(
                    `${label} imageURL is not an approved EasySBC asset`,
                );
        } catch {
            return fail(`${label} imageURL is invalid`);
        }
    }
}

const now = Math.floor(new Date(config.generatedAt).getTime() / 1000);
const eligible = records.filter((record) => {
    if (!record.active) return false;
    if (record.endTime <= now + settings.minimumExpiryLeadSeconds) return false;
    if (/\b(?:bronze|silver)\b/i.test(record.name)) return false;
    if (Number(record.psPrice) < 1500) return false;
    if (!record.repeatable && Number(record.psPrice) < 20_000) return false;
    return Number(record.pcPrice) > 0;
});

if (eligible.length === 0)
    return fail('EasySBC source produced no eligible challenges');

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

function localizedName(record) {
    return /[\u0600-\u06ff]/.test(record.name)
        ? record.name
        : `تحدي SBC: ${record.name}`;
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
    const nameAr = localizedName(record);
    return {
        externalId: `easysbc-sbc-${record.id}`,
        categoryExternalId: `easysbc-category-${categoryKey[record.categoryId]}`,
        slug: safeSlug(record),
        serviceType: 'sbc',
        name: { ar: nameAr, en: record.name.trim() },
        description: {
            ar: /[\u0600-\u06ff]/.test(record.description || '')
                ? record.description
                : `إكمال تحدي SBC: ${record.name}.`,
            en: record.description?.trim() || `Complete ${record.name}.`,
        },
        sortOrder: index + 1,
        visible: true,
        variants: [variant(record, 'playstation'), variant(record, 'pc')],
        media: record.imageURL
            ? [
                  {
                      url: record.imageURL,
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
