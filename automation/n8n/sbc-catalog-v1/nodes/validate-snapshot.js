/* eslint-disable */
const item = $input.first().json;
const snapshot = item.snapshot;

function fail(reason) {
    return [{ json: { ...item, valid: false, failureReason: reason } }];
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

function exactKeys(value, expected) {
    return (
        value &&
        typeof value === 'object' &&
        !Array.isArray(value) &&
        JSON.stringify(Object.keys(value)) === JSON.stringify(expected)
    );
}

function unique(values) {
    return new Set(values).size === values.length;
}

if (!item.valid || !snapshot)
    return fail(item.failureReason || 'Catalog snapshot was not built');
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
    return fail('Catalog snapshot top-level keys are not exact');
}
if (
    snapshot.schemaVersion !== 1 ||
    snapshot.completeSnapshot !== true ||
    snapshot.eventId === snapshot.runId
) {
    return fail('Catalog snapshot metadata is invalid');
}
if (
    !/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{6}Z$/.test(snapshot.generatedAt)
) {
    return fail('Catalog snapshot generatedAt is invalid');
}
if (!Array.isArray(snapshot.categories) || snapshot.categories.length !== 4) {
    return fail('Catalog snapshot must contain exactly four SBC categories');
}
if (
    !Array.isArray(snapshot.products) ||
    snapshot.products.length < 1 ||
    snapshot.products.length > 2000
) {
    return fail('Catalog snapshot product count is invalid');
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
    )
        return fail('Category keys are not exact');
    if (
        !exactKeys(category.name, ['ar', 'en']) ||
        !exactKeys(category.description, ['ar', 'en'])
    )
        return fail('Category localization is invalid');
    if (
        !category.externalId ||
        !category.slug ||
        !category.name.ar ||
        !category.name.en ||
        category.visible !== true
    )
        return fail('Category fields are invalid');
    categoryIds.push(category.externalId);
    categorySlugs.push(category.slug);
}
if (!unique(categoryIds) || !unique(categorySlugs))
    return fail('Category identity is duplicated');

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
    )
        return fail('Product keys are not exact');
    if (product.serviceType !== 'sbc')
        return fail('Product serviceType must be sbc');
    if (!categoryIds.includes(product.categoryExternalId))
        return fail('Product category relationship is invalid');
    if (
        !exactKeys(product.name, ['ar', 'en']) ||
        !exactKeys(product.description, ['ar', 'en'])
    )
        return fail('Product localization is invalid');
    if (
        !product.externalId ||
        !product.slug ||
        !product.name.ar ||
        !product.name.en ||
        product.visible !== true
    )
        return fail('Product fields are invalid');
    if (!Array.isArray(product.variants) || product.variants.length !== 2)
        return fail('Every SBC product must contain exactly two variants');
    if (!Array.isArray(product.media) || product.media.length > 1)
        return fail('Product media is invalid');
    productIds.push(product.externalId);
    productSlugs.push(product.slug);

    const platforms = [];
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
        )
            return fail('Variant keys are not exact');
        if (!exactKeys(variant.name, ['ar', 'en']))
            return fail('Variant localization is invalid');
        if (
            !variant.externalId ||
            !variant.sku ||
            !Number.isInteger(variant.priceMinor) ||
            variant.priceMinor <= 0 ||
            variant.salePriceMinor !== null ||
            variant.priceVersion !== 1 ||
            variant.active !== true
        )
            return fail('Variant fields are invalid');
        if (
            (variant.platform === 'playstation' &&
                variant.market !== 'console') ||
            (variant.platform === 'pc' && variant.market !== 'pc')
        )
            return fail('Variant platform and market do not match');
        if (
            !['playstation', 'pc'].includes(variant.platform) ||
            variant.currency !== 'SAR'
        )
            return fail('Variant platform or currency is invalid');
        if (
            !variant.configuration ||
            typeof variant.configuration !== 'object' ||
            Array.isArray(variant.configuration)
        )
            return fail('Variant configuration is invalid');
        platforms.push(variant.platform);
        variantIds.push(variant.externalId);
        skus.push(variant.sku);
    }
    if (JSON.stringify(platforms) !== JSON.stringify(['playstation', 'pc']))
        return fail(
            'Every SBC product must contain ordered PS and PC variants',
        );

    for (const media of product.media) {
        if (
            !exactKeys(media, ['url', 'alt', 'sortOrder']) ||
            !exactKeys(media.alt, ['ar', 'en'])
        )
            return fail('Media keys are not exact');
        if (!isApprovedEasySbcImage(media.url) || media.sortOrder !== 0)
            return fail('Media URL is not an approved EasySBC asset');
    }
}

if (!unique(productIds)) return fail('Product externalId is duplicated');
if (!unique(productSlugs)) return fail('Product slug is duplicated');
if (!unique(variantIds)) return fail('Variant externalId is duplicated');
if (!unique(skus)) return fail('Variant SKU is duplicated');

return [
    {
        json: {
            ...item,
            valid: true,
            failureReason: null,
            catalogSnapshot: snapshot,
        },
    },
];
