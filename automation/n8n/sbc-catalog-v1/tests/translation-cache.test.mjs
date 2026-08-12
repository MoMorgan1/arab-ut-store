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

test('translation validation normalizes approved FC abbreviations before caching', async () => {
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
        nameAr: `\u062a\u062d\u062f\u064a \u0627\u0644\u0644\u0627\u0639\u0628 ${index + 1}`,
    }));
    translated[0].nameAr =
        '1 \u0645\u0646 4 \u0627\u062e\u062a\u064a\u0627\u0631 \u0644\u0627\u0639\u0628 95+ FOF \u0623\u0648 FUTTIES \u0641\u0631\u064a\u0642 1-3';
    translated[1].nameAr = '7x \u062a\u0631\u0642\u064a\u0629 87+';
    translated[2].nameAr =
        '\u062a\u0631\u0642\u064a\u0629 94+ GOTG \u0648 FUTTIES \u0641\u0631\u064a\u0642 1 \u0648 2';
    translated[3].nameAr =
        '\u062d\u0632\u0645\u0629 TOTW \u0628\u062a\u0642\u064a\u064a\u0645 84+';
    translated[4].nameAr = '\u0645\u0628\u0627\u062f\u0644\u0629 90 OVR';
    translated[5].nameAr =
        '\u062a\u0637\u0648\u064a\u0631 EVO \u0644\u0644\u0645\u0647\u0627\u062c\u0645';
    translated[6].nameAr =
        '\u0645\u0642\u062f\u0645\u0629 \u0625\u0644\u0649 SBCs';

    const result = (
        await runNode('validate-translations', {
            named: { 'Prepare Translations': plan },
            items: [{ text: JSON.stringify(translated) }],
            staticData,
        })
    )[0].json;

    assert.equal(result.translationReady, true, result.failureReason);
    assert.equal(
        staticData.sbcCatalogV1.translations[
            `${records[0].id}\u0000${records[0].name}`
        ].nameAr,
        '1 \u0645\u0646 4 \u0627\u062e\u062a\u064a\u0627\u0631 \u0644\u0627\u0639\u0628 95+ \u0645\u0647\u0631\u062c\u0627\u0646 \u0643\u0631\u0629 \u0627\u0644\u0642\u062f\u0645 \u0623\u0648 \u0641\u0648\u062a\u064a\u0632 \u0641\u0631\u064a\u0642 1-3',
    );
    assert.equal(
        staticData.sbcCatalogV1.translations[
            `${records[1].id}\u0000${records[1].name}`
        ].nameAr,
        '7\u00d7 \u062a\u0631\u0642\u064a\u0629 87+',
    );
    assert.equal(
        staticData.sbcCatalogV1.translations[
            `${records[2].id}\u0000${records[2].name}`
        ].nameAr,
        '\u062a\u0631\u0642\u064a\u0629 94+ \u0639\u0638\u0645\u0627\u0621 \u0627\u0644\u0644\u0639\u0628\u0629 \u0648 \u0641\u0648\u062a\u064a\u0632 \u0641\u0631\u064a\u0642 1 \u0648 2',
    );
    assert.equal(
        staticData.sbcCatalogV1.translations[
            `${records[3].id}\u0000${records[3].name}`
        ].nameAr,
        '\u062d\u0632\u0645\u0629 \u0641\u0631\u064a\u0642 \u0627\u0644\u0623\u0633\u0628\u0648\u0639 \u0628\u062a\u0642\u064a\u064a\u0645 84+',
    );
    assert.equal(
        staticData.sbcCatalogV1.translations[
            `${records[4].id}\u0000${records[4].name}`
        ].nameAr,
        '\u0645\u0628\u0627\u062f\u0644\u0629 90 \u062a\u0642\u064a\u064a\u0645',
    );
    assert.equal(
        staticData.sbcCatalogV1.translations[
            `${records[5].id}\u0000${records[5].name}`
        ].nameAr,
        '\u062a\u0637\u0648\u064a\u0631 \u0644\u0644\u0645\u0647\u0627\u062c\u0645',
    );
    assert.equal(
        staticData.sbcCatalogV1.translations[
            `${records[6].id}\u0000${records[6].name}`
        ].nameAr,
        '\u0645\u0642\u062f\u0645\u0629 \u0625\u0644\u0649 \u062a\u062d\u062f\u064a\u0627\u062a \u0628\u0646\u0627\u0621 \u0627\u0644\u062a\u0634\u0643\u064a\u0644\u0627\u062a',
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
