import assert from 'node:assert/strict';
import { test } from 'node:test';

import {
    config,
    pricingRead,
    runNode,
    sourceRecord,
    sourceRecords,
} from './helpers.mjs';

async function prepare(
    records,
    { settings = {}, pricing = pricingRead() } = {},
) {
    return (
        await runNode('prepare-snapshot', {
            named: {
                Config: config(settings),
                'Evaluate Pricing Read': { valid: true, pricing },
            },
            items: records,
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
    assert.equal(first.name.ar, 'تحدي SBC: Player Challenge 0');
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
                ar: 'تحدي SBC: Player Challenge 0',
                en: 'Player Challenge 0',
            },
            sortOrder: 0,
        },
    ]);
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
        settings: { sourceMinCount: 1 },
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
