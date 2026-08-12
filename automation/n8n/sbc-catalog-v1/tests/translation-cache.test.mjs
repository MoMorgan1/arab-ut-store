import assert from 'node:assert/strict';
import { test } from 'node:test';

import { config, runNode, sourceRecords, translations } from './helpers.mjs';

test('an exact Arabic-only cache skips translation enrichment', async () => {
    const records = sourceRecords(20);
    const result = (
        await runNode('prepare-translations', {
            named: { Config: config() },
            items: records,
            staticData: {
                sbcCatalogV1: { translations: translations(records) },
            },
        })
    )[0].json;

    assert.equal(result.translationPlanValid, true, result.failureReason);
    assert.equal(result.translationReady, true);
    assert.equal(result.missingTranslations.length, 0);
    assert.equal(result.sourceRecords.length, 20);
});

test('an uncached source emits one deterministic exact translation request', async () => {
    const records = sourceRecords(20);
    const result = (
        await runNode('prepare-translations', {
            named: { Config: config() },
            items: records,
            staticData: {},
        })
    )[0].json;

    assert.equal(result.translationPlanValid, true, result.failureReason);
    assert.equal(result.translationReady, false);
    assert.deepEqual(result.missingTranslations[0], {
        id: '1000',
        sourceName: 'Player Challenge 0',
    });
    assert.equal(result.missingTranslations.length, 20);
    assert.match(result.prompt, /Return only a JSON array/i);
    assert.match(result.prompt, /Player Challenge 0/);
});

test('translation validation atomically caches an exact complete Arabic-only response', async () => {
    const records = sourceRecords(20);
    const staticData = {};
    const plan = (
        await runNode('prepare-translations', {
            named: { Config: config() },
            items: records,
            staticData,
        })
    )[0].json;
    const translated = plan.missingTranslations.map((pair, index) => ({
        ...pair,
        nameAr: `تحدي اللاعب ${index + 1}`,
    }));

    const result = (
        await runNode('validate-translations', {
            named: { 'Prepare Translations': plan },
            items: [{ text: JSON.stringify(translated) }],
            staticData,
        })
    )[0].json;

    assert.equal(result.translationReady, true, result.failureReason);
    assert.equal(result.sourceRecords.length, 20);
    assert.equal(
        staticData.sbcCatalogV1.translations[
            `${records[0].id}\u0000${records[0].name}`
        ].nameAr,
        'تحدي اللاعب 1',
    );
});

test('translation validation fails closed without partial cache mutation', async (t) => {
    const records = sourceRecords(20);
    const plan = (
        await runNode('prepare-translations', {
            named: { Config: config() },
            items: records,
            staticData: {},
        })
    )[0].json;
    const valid = plan.missingTranslations.map((pair, index) => ({
        ...pair,
        nameAr: `تحدي اللاعب ${index + 1}`,
    }));
    const cases = [
        ['missing pair', valid.slice(0, -1), /count/i],
        [
            'source mismatch',
            valid.map((item, index) =>
                index === 0 ? { ...item, sourceName: 'Wrong name' } : item,
            ),
            /source name/i,
        ],
        [
            'mixed language',
            valid.map((item, index) =>
                index === 0 ? { ...item, nameAr: 'تحدي Player' } : item,
            ),
            /Arabic-only/i,
        ],
        [
            'extra id',
            [...valid, { id: '9999', sourceName: 'Extra', nameAr: 'إضافي' }],
            /count|unexpected/i,
        ],
    ];

    for (const [name, response, pattern] of cases) {
        await t.test(name, async () => {
            const staticData = {};
            const result = (
                await runNode('validate-translations', {
                    named: { 'Prepare Translations': plan },
                    items: [{ text: JSON.stringify(response) }],
                    staticData,
                })
            )[0].json;

            assert.equal(result.translationReady, false);
            assert.match(result.failureReason, pattern);
            assert.equal(staticData.sbcCatalogV1, undefined);
        });
    }
});
