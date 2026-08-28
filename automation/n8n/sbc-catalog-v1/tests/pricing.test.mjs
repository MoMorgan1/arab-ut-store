import assert from 'node:assert/strict';
import { test } from 'node:test';

import {
    LARAVEL_STANDARD_TIERS,
    laravelWouldReject,
    metaRecords,
    runToSnapshot,
} from './helpers.mjs';

async function snapshot(options = {}) {
    const flow = await runToSnapshot(options);

    return flow.json('Build & Price Snapshot');
}

async function priceError(options) {
    try {
        await runToSnapshot(options);
    } catch (error) {
        return error.message;
    }

    return null;
}

test('the store would accept every published variant', async () => {
    // A transcription of SbcCompletionPricing::fromConfiguration. Getting this
    // wrong is a 422 on the live publish, which is how the v4 rollout first
    // failed: multiplierBps had been derived from the prices instead of sent
    // as the fixed policy constants the store compares with !==.
    const built = await snapshot();
    const rejections = [];

    for (const product of built.catalogSnapshot.products) {
        for (const variant of product.variants) {
            const reason = laravelWouldReject(
                variant.configuration,
                variant.priceMinor,
            );

            if (reason) {
                rejections.push(`${variant.externalId}: ${reason}`);
            }
        }
    }

    assert.deepEqual(rejections, []);
    assert.ok(built.catalogSnapshot.products.length > 0);
});

test('repeatable tiers are the exact policy constants', async () => {
    const built = await snapshot();
    const repeatable = built.catalogSnapshot.products
        .flatMap(({ variants }) => variants)
        .find(({ configuration }) => configuration.completionPricing.repeatable);

    assert.deepEqual(
        repeatable.configuration.completionPricing.tiers.map(
            ({ completions, multiplierBps }) => [completions, multiplierBps],
        ),
        LARAVEL_STANDARD_TIERS,
    );
});

test('a non-repeatable SBC offers exactly one completion', async () => {
    const built = await snapshot();
    const single = built.catalogSnapshot.products
        .flatMap(({ variants }) => variants)
        .find(
            ({ configuration }) => !configuration.completionPricing.repeatable,
        );

    assert.deepEqual(
        single.configuration.completionPricing.tiers.map(
            ({ completions, multiplierBps }) => [completions, multiplierBps],
        ),
        [[1, 10_000]],
    );
    assert.equal(single.configuration.completionPricing.maximum, 1);
});

test('every published price is a whole number of SAR and rises with volume', async () => {
    const built = await snapshot();

    for (const product of built.catalogSnapshot.products) {
        for (const variant of product.variants) {
            const tiers = variant.configuration.completionPricing.tiers;

            for (const [index, tier] of tiers.entries()) {
                assert.ok(
                    Number.isInteger(tier.totalMinor),
                    `${variant.externalId} tier ${tier.completions} is not an integer`,
                );
                assert.equal(
                    tier.totalMinor % 100,
                    0,
                    `${variant.externalId} tier ${tier.completions} is not whole SAR`,
                );

                if (index > 0) {
                    assert.ok(
                        tier.totalMinor >= tiers[index - 1].totalMinor,
                        `${variant.externalId} price falls at ${tier.completions} completions`,
                    );
                }
            }

            assert.equal(variant.priceMinor, tiers[0].totalMinor);
        }
    }
});

test('no price falls below the coin cost it is built from', async () => {
    const audit = (await snapshot()).pricingAudit;

    assert.ok(
        audit.minimumContributionMinor > 0,
        `minimum contribution was ${audit.minimumContributionMinor}`,
    );
    assert.equal(audit.repricedVariants, audit.repricedProducts * 2);
});

test('a margin table that pays less for a bigger bundle is rejected at config', async () => {
    // Every rate here is individually inside its validated band, but 40 runs
    // would contribute 20000 and 50 runs only 5000. Caught against the table
    // itself rather than discovered halfway through a priced catalog.
    const message = await priceError({
        poisonConfig: (config) => {
            config.settings.pricingPolicy.repeatServiceMarginPerRunMinor = {
                1: 500,
                50: 100,
            };
        },
    });

    assert.match(message, /not monotonic in total/);
});

test('a price floor that is not whole SAR is rejected at config', async () => {
    // The store rejects any tier total that is not a multiple of 100, so a
    // 2.50 SAR floor would price fine and then fail every run it bound on.
    const message = await priceError({
        poisonConfig: (config) => {
            config.settings.pricingPolicy.minimumPriceMinor = 250;
        },
    });

    assert.match(message, /whole number of SAR/);
});

test('a broken eligibility clock cannot silently sell expired SBCs', async () => {
    // An unparseable generatedAt makes `now` NaN, and every `endTime <= NaN`
    // comparison is false, so the expiry filter passes everything.
    const message = await priceError({
        poisonConfig: (config) => {
            config.generatedAt = 'not-a-date';
        },
    });

    assert.match(message, /not a parseable timestamp/);
});

test('the bronze and silver exclusion actually excludes', async () => {
    // The pattern lives in Config as a string, so a lost backslash turns \b
    // into a backspace and the filter silently matches nothing.
    const meta = metaRecords(120);
    meta[0].name = 'Bronze Upgrade';
    meta[1].name = '10x Silver Upgrade';

    const built = await snapshot({ meta });
    const names = built.catalogSnapshot.products.map(({ name }) => name.en);

    assert.equal(
        names.some((name) => /bronze|silver/i.test(name)),
        false,
    );
    // The rest of the catalog is untouched: this is a word-boundary filter,
    // not a substring ban.
    assert.ok(names.length > 50, `only ${names.length} products survived`);
});

test('a cosmetic metadata field the merge does not screen is skipped, not fatal', async () => {
    const meta = metaRecords(120);
    meta[0].categoryId = 99;
    meta[1].repeatabilityMode = 'WEEKLY';
    meta[2].imageURL = 'https://evil.example.com/x.png';

    const built = await snapshot({ meta });

    assert.equal(built.rejectedRecordCount, 3);
    assert.ok(built.eligibleCount > 50);
    assert.ok(
        built.rejectedRecords.some(({ reason }) => reason === 'bad_category'),
    );
});
