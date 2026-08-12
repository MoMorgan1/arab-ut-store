/* eslint-disable */
const config = $('Config').first().json;

function result(fields) {
    return [{ json: { ...config, ...fields } }];
}

function fail(reason, records = []) {
    return result({
        translationPlanValid: false,
        translationReady: false,
        failureReason: reason,
        sourceRecords: records,
        missingTranslations: [],
        prompt: null,
    });
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
        records,
    );
}
if (records.length >= settings.sourceLimit) {
    return fail(
        `EasySBC pagination is ambiguous at the configured limit of ${settings.sourceLimit}`,
        records,
    );
}

const allowedCategories = new Set([1, 2, 3, 4, 5, 6]);
const allowedModes = new Set(['NON_REPEATABLE', 'UNLIMITED', 'REFRESH']);
const ids = new Set();
for (let index = 0; index < records.length; index += 1) {
    const record = records[index];
    const label = `EasySBC record ${index}`;
    if (!record || typeof record !== 'object' || Array.isArray(record))
        return fail(`${label} is not an object`, records);
    if (!Number.isInteger(record.id) || record.id <= 0)
        return fail(`${label} id is invalid`, records);
    if (ids.has(record.id))
        return fail(
            `EasySBC source contains duplicate id ${record.id}`,
            records,
        );
    ids.add(record.id);
    if (
        typeof record.name !== 'string' ||
        !record.name.trim() ||
        record.name.length > 220
    )
        return fail(`${label} name is invalid`, records);
    if (!allowedCategories.has(record.categoryId))
        return fail(`${label} categoryId is invalid`, records);
    if (!Number.isInteger(record.sbcsCount) || record.sbcsCount <= 0)
        return fail(`${label} sbcsCount is invalid`, records);
    if (typeof record.repeatable !== 'boolean')
        return fail(`${label} repeatable is invalid`, records);
    if (!allowedModes.has(record.repeatabilityMode))
        return fail(`${label} repeatabilityMode is invalid`, records);
    if (!Number.isInteger(record.endTime) || record.endTime <= 0)
        return fail(`${label} endTime is invalid`, records);
    if (typeof record.active !== 'boolean')
        return fail(`${label} active is invalid`, records);
    if (!Number.isFinite(Number(record.psPrice)) || Number(record.psPrice) <= 0)
        return fail(`${label} psPrice is invalid`, records);
    if (!Number.isFinite(Number(record.pcPrice)) || Number(record.pcPrice) <= 0)
        return fail(`${label} pcPrice is invalid`, records);
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
    return fail('EasySBC source produced no eligible challenges', records);

const globalData = $getWorkflowStaticData('global');
const cache = globalData.sbcCatalogV1?.translations ?? {};
const missingTranslations = [];

for (const record of eligible) {
    const sourceName = record.name.trim();
    const key = `${record.id}\u0000${sourceName}`;
    const cached = cache[key];
    if (!cached) {
        missingTranslations.push({ id: String(record.id), sourceName });
        continue;
    }
    if (cached.sourceName !== sourceName) {
        return fail(
            `Cached translation source name mismatch for EasySBC id ${record.id}`,
            records,
        );
    }
    if (
        typeof cached.nameAr !== 'string' ||
        cached.nameAr.length < 2 ||
        cached.nameAr.length > 120 ||
        !/[\u0600-\u06ff]/.test(cached.nameAr) ||
        /[A-Za-z]/.test(cached.nameAr)
    ) {
        return fail(
            `Cached translation for EasySBC id ${record.id} must be Arabic-only and at most 120 characters`,
            records,
        );
    }
}

const prompt = missingTranslations.length
    ? [
          'Translate these exact EA FC Squad Building Challenge names into concise natural Gulf gaming Arabic.',
          'Preserve meaning and transliterate player names. Translate pack, pick, upgrade, token, icon, hero, and evolution terms.',
          'Arabic script and digits only: no Latin letters, no generic prefix, no commentary.',
          'Keep every id and sourceName byte-for-byte exact. Do not add, remove, or reorder entries.',
          'Return only a JSON array with exactly these keys: id, sourceName, nameAr.',
          `INPUT:${JSON.stringify(missingTranslations)}`,
      ].join('\n')
    : null;

return result({
    translationPlanValid: true,
    translationReady: missingTranslations.length === 0,
    failureReason: null,
    sourceRecords: records,
    missingTranslations,
    prompt,
});
