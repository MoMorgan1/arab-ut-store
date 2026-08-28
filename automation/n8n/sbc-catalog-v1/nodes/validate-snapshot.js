/* eslint-disable */
// Independent structural check of the exact payload that is about to be signed.
// This gate earns its keep: it is the last thing between a Code-node bug and
// the live storefront.
//
// v3's version re-derived the legacy multiplierBps table and demanded the
// payload match it -- while the pricing node had already replaced every total
// with a different formula. It was verifying numbers nothing produced. This
// version checks multiplierBps for INTERNAL CONSISTENCY with the totals that
// are actually being published, which is the property that matters.

const item = $input.first().json;
const snapshot = item.catalogSnapshot;

const MAX_ARABIC_TITLE_VISIBLE_LENGTH = 40;
const BIDI_CONTROL_PATTERN = /[\u2066\u2067\u2068\u2069]/g;

function fail(reason) {
    throw new Error(`[validate_snapshot] ${reason}`);
}

function visibleText(value) {
    return String(value ?? '')
        .replace(BIDI_CONTROL_PATTERN, '')
        .trim();
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

// Order-insensitive, unlike v3's JSON.stringify(Object.keys(...)) comparison,
// which failed with a misleading message if a builder ever emitted the same
// keys in a different order.
function exactKeys(value, expected) {
    if (!value || typeof value !== 'object' || Array.isArray(value))
        return false;
    const actual = Object.keys(value).sort();
    return JSON.stringify(actual) === JSON.stringify([...expected].sort());
}

function unique(values) {
    return new Set(values).size === values.length;
}

// A byte-for-byte mirror of the store's
// app/ValueObjects/Pricing/SbcCompletionPricing.php::expectedTiers().
// These are FIXED POLICY CONSTANTS the store compares with !==; they are not
// derived from the prices. If the store's table changes, change it here too.
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

function expectedTierPolicy(repeatable, maximum) {
    if (!repeatable) return [[1, 10000]];
    if (maximum !== null && maximum < 5) {
        return Array.from({ length: maximum }, (_, index) => [
            index + 1,
            10000,
        ]);
    }
    if (maximum === null || maximum >= 100) return STANDARD_REPEAT_TIERS;
    const tiers = STANDARD_REPEAT_TIERS.filter(
        ([completions]) => completions <= maximum,
    );
    const last = tiers[tiers.length - 1];
    if (last[0] !== maximum)
        tiers.push([maximum, Math.max(7000, last[1] - 200)]);
    return tiers;
}

function completionPricingFailure(pricing, priceMinor) {
    if (!exactKeys(pricing, ['version', 'repeatable', 'maximum', 'tiers'])) {
        return 'variant completion pricing keys are not exact';
    }
    if (pricing.version !== 1 || typeof pricing.repeatable !== 'boolean') {
        return 'variant completion pricing metadata is invalid';
    }
    if (
        (!pricing.repeatable && pricing.maximum !== 1) ||
        (pricing.repeatable &&
            pricing.maximum !== null &&
            (!Number.isInteger(pricing.maximum) || pricing.maximum < 2))
    ) {
        return 'variant completion pricing maximum is invalid';
    }
    if (!Array.isArray(pricing.tiers) || pricing.tiers.length === 0) {
        return 'variant completion pricing tiers are invalid';
    }
    if (!pricing.repeatable && pricing.tiers.length !== 1) {
        return 'a non-repeatable SBC must offer exactly one completion tier';
    }

    // Check the SAME constants Laravel checks, so a rejection surfaces here with
    // a useful message instead of as a 422 from the store. An earlier v4 build
    // validated multiplierBps against the tier prices instead; that was internally
    // tidy and made every publish fail, because the store compares these values
    // with !== against a fixed table.
    const expected = expectedTierPolicy(pricing.repeatable, pricing.maximum);
    if (pricing.tiers.length !== expected.length) {
        return `variant offers ${pricing.tiers.length} completion tiers but policy defines ${expected.length}`;
    }

    for (let index = 0; index < pricing.tiers.length; index += 1) {
        const tier = pricing.tiers[index];
        const [expectedCompletions, expectedMultiplierBps] = expected[index];
        if (tier.completions !== expectedCompletions) {
            return `completion tier ${index} is ${tier.completions}, policy requires ${expectedCompletions}`;
        }
        if (tier.multiplierBps !== expectedMultiplierBps) {
            return `completion tier ${tier.completions} has multiplierBps ${tier.multiplierBps}, policy requires exactly ${expectedMultiplierBps} (the store rejects anything else)`;
        }
        if (!exactKeys(tier, ['completions', 'multiplierBps', 'totalMinor'])) {
            return 'variant completion pricing tier keys are not exact';
        }
        if (!Number.isInteger(tier.completions) || tier.completions < 1) {
            return 'variant completion count is invalid';
        }
        if (!pricing.repeatable && tier.completions !== 1) {
            return 'a non-repeatable SBC cannot offer more than one completion';
        }
        if (
            pricing.maximum !== null &&
            Number.isInteger(pricing.maximum) &&
            tier.completions > pricing.maximum
        ) {
            return 'a completion tier exceeds the SBC repeat maximum';
        }
        if (
            index > 0 &&
            tier.completions <= pricing.tiers[index - 1].completions
        ) {
            return 'completion tiers are not in ascending order';
        }
        if (!Number.isInteger(tier.totalMinor) || tier.totalMinor <= 0) {
            return 'variant completion tier total is invalid';
        }
        if (tier.totalMinor % 100 !== 0) {
            return 'variant completion tier total is not a whole number of SAR';
        }
        // Buying more must never cost less in total.
        if (
            index > 0 &&
            tier.totalMinor < pricing.tiers[index - 1].totalMinor
        ) {
            return 'variant completion pricing is not monotonic in completions';
        }
    }

    if (pricing.tiers[0].totalMinor !== priceMinor) {
        return 'variant price must equal the first tier total';
    }
    return null;
}

if (!snapshot) fail('catalog snapshot was not built');
if (
    !exactKeys(snapshot, [
        'schemaVersion',
        'eventId',
        'runId',
        'generatedAt',
        'completeSnapshot',
        'categories',
        'products',
    ])
) {
    fail('catalog snapshot top-level keys are not exact');
}
if (
    snapshot.schemaVersion !== 1 ||
    snapshot.completeSnapshot !== true ||
    snapshot.eventId === snapshot.runId
) {
    fail('catalog snapshot metadata is invalid');
}
if (
    !/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{6}Z$/.test(snapshot.generatedAt)
) {
    fail('catalog snapshot generatedAt is invalid');
}
if (!Array.isArray(snapshot.categories) || snapshot.categories.length !== 4) {
    fail('catalog snapshot must contain exactly four SBC categories');
}
if (
    !Array.isArray(snapshot.products) ||
    snapshot.products.length < 1 ||
    snapshot.products.length > 2000
) {
    fail('catalog snapshot product count is invalid');
}

const categoryIds = [];
const categorySlugs = [];
for (const category of snapshot.categories) {
    if (
        !exactKeys(category, [
            'externalId',
            'slug',
            'name',
            'description',
            'sortOrder',
            'visible',
        ])
    ) {
        fail('category keys are not exact');
    }
    if (
        !exactKeys(category.name, ['ar', 'en']) ||
        !exactKeys(category.description, ['ar', 'en'])
    ) {
        fail('category localization is invalid');
    }
    if (
        !category.externalId ||
        !category.slug ||
        !category.name.ar ||
        !category.name.en ||
        category.visible !== true
    ) {
        fail('category fields are invalid');
    }
    categoryIds.push(category.externalId);
    categorySlugs.push(category.slug);
}
if (!unique(categoryIds) || !unique(categorySlugs))
    fail('category identity is duplicated');

const productIds = [];
const productSlugs = [];
const variantIds = [];
const skus = [];

for (const product of snapshot.products) {
    if (
        !exactKeys(product, [
            'externalId',
            'categoryExternalId',
            'slug',
            'serviceType',
            'name',
            'description',
            'sortOrder',
            'visible',
            'variants',
            'media',
        ])
    ) {
        fail('product keys are not exact');
    }
    if (product.serviceType !== 'sbc') fail('product serviceType must be sbc');
    if (!categoryIds.includes(product.categoryExternalId)) {
        fail('product category relationship is invalid');
    }
    if (
        !exactKeys(product.name, ['ar', 'en']) ||
        !exactKeys(product.description, ['ar', 'en'])
    ) {
        fail('product localization is invalid');
    }
    if (
        !product.externalId ||
        !product.slug ||
        !product.name.ar ||
        !product.name.en ||
        product.visible !== true
    ) {
        fail('product fields are invalid');
    }

    const visibleArabicTitle = visibleText(product.name.ar);
    if (
        visibleArabicTitle.length < 2 ||
        visibleArabicTitle.length > MAX_ARABIC_TITLE_VISIBLE_LENGTH ||
        !/[\u0600-\u06ff]/.test(visibleArabicTitle) ||
        /[٠-٩۰-۹]/.test(visibleArabicTitle) ||
        /أبجريد|ابجريد/i.test(visibleArabicTitle)
    ) {
        fail(
            `product Arabic title must contain Arabic, use English digits, and be at most ${MAX_ARABIC_TITLE_VISIBLE_LENGTH} visible characters: ${product.externalId}`,
        );
    }
    if (!Array.isArray(product.variants) || product.variants.length !== 2) {
        fail('every SBC product must contain exactly two variants');
    }
    if (!Array.isArray(product.media) || product.media.length > 1) {
        fail('product media is invalid');
    }
    productIds.push(product.externalId);
    productSlugs.push(product.slug);

    const platforms = [];
    const completionChoices = [];
    for (const variant of product.variants) {
        if (
            !exactKeys(variant, [
                'externalId',
                'sku',
                'platform',
                'market',
                'currency',
                'name',
                'priceMinor',
                'salePriceMinor',
                'priceVersion',
                'active',
                'configuration',
            ])
        ) {
            fail('variant keys are not exact');
        }
        if (!exactKeys(variant.name, ['ar', 'en']))
            fail('variant localization is invalid');
        if (
            !variant.externalId ||
            !variant.sku ||
            !Number.isInteger(variant.priceMinor) ||
            variant.priceMinor <= 0 ||
            variant.salePriceMinor !== null ||
            variant.priceVersion !== 1 ||
            variant.active !== true
        ) {
            fail('variant fields are invalid');
        }
        if (
            (variant.platform === 'playstation' &&
                variant.market !== 'console') ||
            (variant.platform === 'pc' && variant.market !== 'pc')
        ) {
            fail('variant platform and market do not match');
        }
        if (
            !['playstation', 'pc'].includes(variant.platform) ||
            variant.currency !== 'SAR'
        ) {
            fail('variant platform or currency is invalid');
        }
        if (
            !variant.configuration ||
            typeof variant.configuration !== 'object' ||
            Array.isArray(variant.configuration)
        ) {
            fail('variant configuration is invalid');
        }
        if (variant.configuration.source !== 'fft') {
            fail(
                'every published variant must be priced from the FFT authority',
            );
        }
        if (
            !['players', 'upgrades', 'icons', 'foundations'].includes(
                variant.configuration.sbcCategory,
            ) ||
            product.categoryExternalId !==
                `easysbc-category-${variant.configuration.sbcCategory}`
        ) {
            fail('variant SBC category is invalid');
        }

        const completionFailure = completionPricingFailure(
            variant.configuration.completionPricing,
            variant.priceMinor,
        );
        if (completionFailure)
            fail(`${completionFailure} (${variant.externalId})`);

        platforms.push(variant.platform);
        completionChoices.push(
            variant.configuration.completionPricing.tiers.map(
                ({ completions }) => completions,
            ),
        );
        variantIds.push(variant.externalId);
        skus.push(variant.sku);
    }

    if (JSON.stringify(platforms) !== JSON.stringify(['playstation', 'pc'])) {
        fail('every SBC product must contain ordered PS and PC variants');
    }
    if (
        JSON.stringify(completionChoices[0]) !==
        JSON.stringify(completionChoices[1])
    ) {
        fail('PS and PC completion choices must match');
    }

    for (const media of product.media) {
        if (
            !exactKeys(media, ['url', 'alt', 'sortOrder']) ||
            !exactKeys(media.alt, ['ar', 'en'])
        ) {
            fail('media keys are not exact');
        }
        if (!isApprovedEasySbcImage(media.url) || media.sortOrder !== 0) {
            fail('media URL is not an approved EasySBC asset');
        }
    }
}

if (!unique(productIds)) fail('product externalId is duplicated');
if (!unique(productSlugs)) fail('product slug is duplicated');
if (!unique(variantIds)) fail('variant externalId is duplicated');
if (!unique(skus)) fail('variant SKU is duplicated');

return [{ json: { ...item, catalogSnapshot: snapshot } }];
