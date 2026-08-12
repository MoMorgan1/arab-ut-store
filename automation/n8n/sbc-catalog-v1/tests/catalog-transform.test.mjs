import assert from 'node:assert/strict';
import { test } from 'node:test';

import {
    approvedBaseline,
    config,
    pricingRead,
    publishedItems,
    runNode,
    sourceRecord,
    sourceRecords,
    translations,
} from './helpers.mjs';

async function prepare(
    records,
    {
        settings = {},
        pricing = pricingRead(),
        staticData = {
            sbcCatalogV1: { translations: translations(records) },
        },
    } = {},
) {
    const configSettings = {
        approvedBaseline: approvedBaseline(records),
        ...settings,
    };

    return (
        await runNode('prepare-snapshot', {
            named: {
                Config: config(configSettings),
                'Evaluate Pricing Read': { valid: true, pricing },
            },
            items: records,
            staticData,
        })
    )[0].json;
}

test('a complete EasySBC source maps to an exact, stable PS and PC catalog snapshot', async () => {
    const records = sourceRecords(20).map((record, index) => ({
        ...record,
        categoryId: [1, 2, 3, 4, 5, 6][index % 6],
        repeatable: index % 2 === 1,
        repeatabilityMode: index % 2 === 1 ? 'REFRESH' : 'NON_REPEATABLE',
        repeats: index % 2 === 1 ? 3 : undefined,
    }));

    const prepared = await prepare(records);
    assert.equal(prepared.valid, true, prepared.failureReason);
    assert.deepEqual(Object.keys(prepared.snapshot), [
        'schemaVersion',
        'eventId',
        'runId',
        'generatedAt',
        'completeSnapshot',
        'categories',
        'products',
    ]);
    assert.deepEqual(
        prepared.snapshot.categories.map(({ externalId }) => externalId),
        [
            'easysbc-category-players',
            'easysbc-category-upgrades',
            'easysbc-category-icons',
            'easysbc-category-foundations',
        ],
    );
    assert.equal(prepared.snapshot.products.length, 20);
    assert.equal(
        new Set(prepared.snapshot.products.map(({ externalId }) => externalId))
            .size,
        20,
    );
    assert.equal(
        new Set(prepared.snapshot.products.map(({ slug }) => slug)).size,
        20,
    );

    const first = prepared.snapshot.products[0];
    assert.equal(first.externalId, 'easysbc-sbc-1000');
    assert.equal(first.slug, 'sbc-player-challenge-0-1000');
    assert.equal(first.name.ar, 'تحدي اللاعب 1');
    assert.equal(first.name.en, 'Player Challenge 0');
    assert.deepEqual(
        first.variants.map(({ platform, market }) => ({ platform, market })),
        [
            { platform: 'playstation', market: 'console' },
            { platform: 'pc', market: 'pc' },
        ],
    );
    assert.deepEqual(
        first.variants.map(({ priceMinor }) => priceMinor),
        [12_300, 17_300],
    );
    assert.deepEqual(first.variants[0].configuration, {
        source: 'easysbc',
        sourceId: '1000',
        sbcCategory: 'players',
        sourceCategoryId: 1,
        sourceSlug: 'player-challenge-0',
        challengeCount: 3,
        completionCount: 1,
        repeatable: false,
        repeatabilityMode: 'NON_REPEATABLE',
        maxRepeats: 1,
        sourceCoins: 100_000,
        expiresAt: '2026-08-19T04:00:00.000Z',
        pricingVersion: 7,
        pricingBase: 'playstation_fast',
        formulaVersion: 'legacy-sbc-one-completion-v1',
    });
    assert.deepEqual(first.media, [
        {
            url: 'https://assets.easysbc.io/fc26/sbcs/sets/icons/1000.png',
            alt: {
                ar: 'تحدي اللاعب 1',
                en: 'Player Challenge 0',
            },
            sortOrder: 0,
        },
    ]);
});

test('approved EasySBC images validate in the restricted n8n Code sandbox', async () => {
    const records = sourceRecords(20);
    const prepared = (
        await runNode('prepare-snapshot', {
            named: {
                Config: config({ approvedBaseline: approvedBaseline(records) }),
                'Evaluate Pricing Read': {
                    valid: true,
                    pricing: pricingRead(),
                },
            },
            items: records,
            staticData: {
                sbcCatalogV1: { translations: translations(records) },
            },
            urlConstructor: null,
        })
    )[0].json;

    assert.equal(prepared.valid, true, prepared.failureReason);
    const validated = (
        await runNode('validate-snapshot', {
            items: [prepared],
            urlConstructor: null,
        })
    )[0].json;
    assert.equal(validated.valid, true, validated.failureReason);
});

test('player SBCs publish the approved reward player art instead of the challenge icon', async () => {
    const records = sourceRecords(20);
    records[0] = {
        ...records[0],
        rewards: [
            {
                type: 'player',
                rewardImgURL:
                    'https://assets.easysbc.io/fc26/sbcs/sets/rewards/1000_0.png',
            },
        ],
    };

    const prepared = await prepare(records);

    assert.equal(prepared.valid, true, prepared.failureReason);
    assert.equal(
        prepared.snapshot.products[0].media[0].url,
        'https://assets.easysbc.io/fc26/sbcs/sets/rewards/1000_0.png',
    );
});

test('non-player SBCs keep the challenge icon even when another reward image exists', async () => {
    const records = sourceRecords(20);
    records[0] = {
        ...records[0],
        rewards: [
            {
                type: 'pack',
                rewardImgURL:
                    'https://assets.easysbc.io/fc26/packs/pack-reward.png',
            },
        ],
    };

    const prepared = await prepare(records);

    assert.equal(prepared.valid, true, prepared.failureReason);
    assert.equal(
        prepared.snapshot.products[0].media[0].url,
        records[0].imageURL,
    );
});

test('player SBCs fail closed when their declared reward art is not an approved EasySBC asset', async () => {
    const records = sourceRecords(20);
    records[0] = {
        ...records[0],
        rewards: [
            {
                type: 'player',
                rewardImgURL: 'https://example.com/player.png',
            },
        ],
    };

    const prepared = await prepare(records);

    assert.equal(prepared.valid, false);
    assert.match(prepared.failureReason, /rewardImgURL/i);
    assert.equal(prepared.snapshot, undefined);
});

test('legacy one-completion pricing uses every audited multiplier boundary', async () => {
    const quantities = [49_999, 50_000, 899_999, 900_000, 1_000_000, 1_000_001];
    const expectedPsSar = [70, 67, 1021, 929, 1031, 1057];
    const records = quantities.map((psPrice, index) =>
        sourceRecord(index, {
            psPrice,
            pcPrice: psPrice,
            repeatable: true,
            repeatabilityMode: 'UNLIMITED',
        }),
    );

    const prepared = await prepare(records, {
        settings: {
            sourceMinCount: 1,
            approvedBaseline: approvedBaseline(records),
        },
    });
    assert.equal(prepared.valid, true, prepared.failureReason);
    assert.deepEqual(
        prepared.snapshot.products.map(
            (product) => product.variants[0].priceMinor / 100,
        ),
        expectedPsSar,
    );
    assert.equal(
        prepared.snapshot.products[0].variants[1].priceMinor,
        81 * 100,
    );
});

test('source validation fails closed on truncation, duplicate IDs, malformed records, or ambiguous pagination', async (t) => {
    const cases = [
        [
            'fewer than the minimum source records',
            sourceRecords(19),
            /minimum/i,
        ],
        [
            'a duplicate source ID',
            [...sourceRecords(20).slice(0, 19), sourceRecord(0)],
            /duplicate/i,
        ],
        [
            'an invalid supplied image host',
            sourceRecords(20).map((item, index) =>
                index === 4
                    ? { ...item, imageURL: 'https://example.com/a.png' }
                    : item,
            ),
            /image/i,
        ],
        [
            'a malformed complete source record',
            sourceRecords(20).map((item, index) =>
                index === 4 ? { ...item, pcPrice: null } : item,
            ),
            /pcPrice/i,
        ],
        [
            'a full page that makes pagination ambiguous',
            sourceRecords(200),
            /pagination/i,
        ],
    ];

    for (const [name, records, pattern] of cases) {
        await t.test(name, async () => {
            const prepared = await prepare(records);
            assert.equal(prepared.valid, false);
            assert.match(prepared.failureReason, pattern);
            assert.equal(prepared.snapshot, undefined);
        });
    }
});

test('eligibility is deterministic and keeps image omission non-fatal', async () => {
    const records = sourceRecords(20);
    records[0] = { ...records[0], active: false };
    records[1] = { ...records[1], endTime: 1_786_427_999 };
    records[2] = {
        ...records[2],
        name: 'Bronze Upgrade',
        repeatable: true,
        psPrice: 2_200,
    };
    records[3] = { ...records[3], psPrice: 1_499, repeatable: true };
    records[4] = { ...records[4], psPrice: 19_999 };
    records[5] = { ...records[5], imageURL: null };

    const prepared = await prepare(records);
    assert.equal(prepared.valid, true, prepared.failureReason);
    assert.equal(prepared.snapshot.products.length, 15);
    assert.deepEqual(prepared.snapshot.products[0].media, []);
});

test('approved and durable baselines reject partial or replacement sources before publish', async (t) => {
    await t.test(
        'source count cannot fall below 85% of the last successful count',
        async () => {
            const records = sourceRecords(84, {
                repeatable: true,
                repeatabilityMode: 'UNLIMITED',
            });
            const prepared = await prepare(records, {
                settings: {
                    sourceMinCount: 1,
                    approvedBaseline: approvedBaseline(records),
                },
                staticData: {
                    sbcCatalogV1: {
                        translations: translations(records),
                        lastSuccessfulCounts: {
                            sourceCount: 100,
                            eligibleCount: 20,
                        },
                        lastSuccessfulItems: publishedItems(records),
                    },
                },
            });

            assert.equal(prepared.valid, false);
            assert.match(prepared.failureReason, /source safety floor of 85/i);
            assert.equal(prepared.snapshot, undefined);
        },
    );

    await t.test(
        'same-count entire replacement fails identity validation',
        async () => {
            const previous = sourceRecords(20);
            const replacement = Array.from({ length: 20 }, (_, index) =>
                sourceRecord(index + 100),
            );
            const prepared = await prepare(replacement, {
                settings: {
                    sourceMinCount: 1,
                    approvedBaseline: approvedBaseline(previous),
                },
                staticData: {
                    sbcCatalogV1: { translations: translations(replacement) },
                },
            });

            assert.equal(prepared.valid, false);
            assert.match(prepared.failureReason, /unexpected missing.*1000/i);
            assert.equal(prepared.snapshot, undefined);
        },
    );
});

test('identity-aware safety permits only expected catalog departures and new arrivals', async (t) => {
    const previous = sourceRecords(20);
    const stateFor = (current) => ({
        sbcCatalogV1: {
            translations: translations(current),
            lastSuccessfulCounts: { sourceCount: 20, eligibleCount: 20 },
            lastSuccessfulItems: publishedItems(previous),
        },
    });
    const settings = {
        sourceMinCount: 1,
        approvedBaseline: approvedBaseline(previous),
    };

    await t.test(
        'an omitted prior item inside the expiry lead passes',
        async () => {
            const expiring = {
                ...previous[0],
                endTime: Math.floor(
                    new Date('2026-08-12T13:00:00.000Z') / 1000,
                ),
            };
            const previousWithExpiry = [expiring, ...previous.slice(1)];
            const current = previousWithExpiry.slice(1);
            const staticData = {
                sbcCatalogV1: {
                    translations: translations(current),
                    lastSuccessfulCounts: {
                        sourceCount: 20,
                        eligibleCount: 20,
                    },
                    lastSuccessfulItems: publishedItems(previousWithExpiry),
                },
            };
            const prepared = await prepare(current, {
                settings: {
                    ...settings,
                    approvedBaseline: approvedBaseline(previousWithExpiry),
                },
                staticData,
            });

            assert.equal(prepared.valid, true, prepared.failureReason);
            assert.equal(prepared.expectedDepartures.length, 1);
            assert.equal(prepared.expectedDepartures[0].sourceId, '1000');
        },
    );

    await t.test(
        'a present but deterministically ineligible prior item can leave',
        async () => {
            const current = previous.map((record, index) =>
                index === 0 ? { ...record, active: false } : record,
            );
            const prepared = await prepare(current, {
                settings,
                staticData: stateFor(current),
            });

            assert.equal(prepared.valid, true, prepared.failureReason);
            assert.equal(prepared.snapshot.products.length, 19);
            assert.equal(prepared.expectedDepartures[0].reason, 'inactive');
        },
    );

    await t.test('an unexpired omitted prior item fails closed', async () => {
        const current = previous.slice(1);
        const prepared = await prepare(current, {
            settings,
            staticData: stateFor(current),
        });

        assert.equal(prepared.valid, false);
        assert.match(prepared.failureReason, /unexpected missing.*1000/i);
    });

    await t.test('a new eligible source ID is allowed', async () => {
        const current = [...previous, sourceRecord(20)];
        const prepared = await prepare(current, {
            settings,
            staticData: stateFor(current),
        });

        assert.equal(prepared.valid, true, prepared.failureReason);
        assert.equal(prepared.snapshot.products.length, 21);
        assert.deepEqual(prepared.newSourceIds, ['1020']);
    });
});

test('eligible source names require an exact reviewed Arabic-only cached translation', async (t) => {
    const records = sourceRecords(20);

    await t.test('missing translation fails closed', async () => {
        const cache = translations(records);
        delete cache[`${records[0].id}\u0000${records[0].name}`];
        const prepared = await prepare(records, {
            staticData: { sbcCatalogV1: { translations: cache } },
        });

        assert.equal(prepared.valid, false);
        assert.match(prepared.failureReason, /translation is missing/i);
    });

    await t.test('source-name mismatch fails closed', async () => {
        const cache = translations(records);
        cache[`${records[0].id}\u0000${records[0].name}`].sourceName =
            'Different source name';
        const prepared = await prepare(records, {
            staticData: { sbcCatalogV1: { translations: cache } },
        });

        assert.equal(prepared.valid, false);
        assert.match(prepared.failureReason, /source name mismatch/i);
    });

    await t.test(
        'mixed Arabic and English translation fails closed',
        async () => {
            const cache = translations(records);
            cache[`${records[0].id}\u0000${records[0].name}`].nameAr =
                'تحدي Player';
            const prepared = await prepare(records, {
                staticData: { sbcCatalogV1: { translations: cache } },
            });

            assert.equal(prepared.valid, false);
            assert.match(prepared.failureReason, /Arabic-only/i);
        },
    );
});

test('snapshot validator rejects any undeclared key, duplicate identity, or non-SBC/PS-PC shape', async (t) => {
    const prepared = await prepare(sourceRecords(20));
    assert.equal(prepared.valid, true, prepared.failureReason);

    const cases = [
        [
            'undeclared top-level key',
            (snapshot) => {
                snapshot.extra = true;
            },
            /top-level/i,
        ],
        [
            'duplicate product slug',
            (snapshot) => {
                snapshot.products[1].slug = snapshot.products[0].slug;
            },
            /slug/i,
        ],
        [
            'non-SBC product',
            (snapshot) => {
                snapshot.products[0].serviceType = 'coins';
            },
            /serviceType/i,
        ],
        [
            'missing PC variant',
            (snapshot) => {
                snapshot.products[0].variants.pop();
            },
            /two variants/i,
        ],
        [
            'variant category does not match its product category',
            (snapshot) => {
                snapshot.products[0].variants[0].configuration.sbcCategory =
                    'icons';
            },
            /SBC category/i,
        ],
    ];

    for (const [name, mutate, pattern] of cases) {
        await t.test(name, async () => {
            const snapshot = structuredClone(prepared.snapshot);
            mutate(snapshot);
            const result = (
                await runNode('validate-snapshot', {
                    items: [{ valid: true, snapshot }],
                })
            )[0].json;
            assert.equal(result.valid, false);
            assert.match(result.failureReason, pattern);
        });
    }

    const validated = (
        await runNode('validate-snapshot', { items: [prepared] })
    )[0].json;
    assert.equal(validated.valid, true, validated.failureReason);
    assert.equal(validated.catalogSnapshot.products.length, 20);
});
