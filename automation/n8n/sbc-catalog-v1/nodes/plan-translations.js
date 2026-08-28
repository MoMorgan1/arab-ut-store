/* eslint-disable */
// Decides which SBC names still need an Arabic title from the LLM.
//
// v3 re-ran the whole source-record validation loop here, a near-copy of the
// one in Prepare SBC Snapshot -- and the two copies had already drifted (this
// one tested record.repeatable, the other tested isRepeatableBundle()). Record
// validation now happens once, in Build & Price Snapshot.
//
// The filter below is deliberately a SUPERSET of that node's eligibility rule:
// active and not already inside the expiry lead. Anything the snapshot will
// actually publish is contained in it, so a rule change over there can never
// leave a product without a translation. Over-translating a handful of names is
// cheap and the results are cached.

const config = $('Config').first().json;
const settings = config.settings;

const MAX_NAME_LENGTH = 40;
const TRANSLATION_SCHEMA_VERSION = 4;
const BIDI_CONTROL_PATTERN = /[\u2066\u2067\u2068\u2069]/g;

function stripBidiControls(value) {
    return String(value ?? '').replace(BIDI_CONTROL_PATTERN, '');
}

function toEnglishDigits(value) {
    return String(value ?? '')
        .replace(/[٠-٩]/g, (digit) => String(digit.charCodeAt(0) - 0x0660))
        .replace(/[۰-۹]/g, (digit) => String(digit.charCodeAt(0) - 0x06f0));
}

function normalizeCachedName(value) {
    return toEnglishDigits(stripBidiControls(value))
        .replace(/\bUpgrade\b/gi, 'ترقية')
        .replace(/(\d{2,3})\s*\+/g, '+$1')
        .replace(/\s+/g, ' ')
        .trim();
}

let records = $input.all().map((item) => item.json);
if (records.length === 1 && Array.isArray(records[0]?.body)) {
    records = records[0].body;
}

if (records.length < settings.sourceMinCount) {
    throw new Error(
        `[translations] merged source holds ${records.length} records; minimum is ${settings.sourceMinCount}`,
    );
}

// Same clock guard as Build & Price Snapshot. Without it an unparseable
// generatedAt makes `now` NaN, every `endTime > NaN` comparison is false, and
// this node reports "no translatable challenges" -- which sends whoever is on
// call looking at the providers instead of at the clock.
const generatedAtMs = Date.parse(config.generatedAt);
if (!Number.isFinite(generatedAtMs)) {
    throw new Error(
        `[translations] config.generatedAt is not a parseable timestamp: ${JSON.stringify(config.generatedAt)}`,
    );
}
if (
    !Number.isInteger(settings.minimumExpiryLeadSeconds) ||
    settings.minimumExpiryLeadSeconds < 0
) {
    throw new Error(
        `[translations] settings.minimumExpiryLeadSeconds must be a non-negative integer; got ${JSON.stringify(settings.minimumExpiryLeadSeconds)}`,
    );
}

const now = Math.floor(generatedAtMs / 1000);
const candidates = records.filter(
    (record) =>
        record &&
        record.active === true &&
        Number.isInteger(record.endTime) &&
        record.endTime > now + settings.minimumExpiryLeadSeconds &&
        typeof record.name === 'string' &&
        record.name.trim(),
);

if (candidates.length === 0) {
    throw new Error(
        '[translations] merged source produced no translatable challenges',
    );
}

const globalData = $getWorkflowStaticData('global');
const cache = globalData.sbcCatalog?.translations ?? {};
const missingTranslations = [];

for (const record of candidates) {
    const sourceName = record.name.trim();
    const key = `${record.id}\u0000${sourceName}`;
    const cached = cache[key];

    if (!cached || cached.sourceName !== sourceName) {
        missingTranslations.push({ id: String(record.id), sourceName });
        continue;
    }

    const normalized = normalizeCachedName(cached.nameAr);
    const stale =
        cached.schemaVersion !== TRANSLATION_SCHEMA_VERSION ||
        typeof cached.nameAr !== 'string' ||
        normalized.length < 2 ||
        normalized.length > MAX_NAME_LENGTH ||
        !/[\u0600-\u06ff]/.test(normalized);

    if (stale) missingTranslations.push({ id: String(record.id), sourceName });
}

const prompt = missingTranslations.length
    ? [
          'Translate the following EA FC Squad Building Challenge names into short, clear and attractive Arabic storefront titles.',
          'Use simple Gulf-leaning Arabic. Never use Egyptian slang.',
          'Translate freely and naturally. Keep only the information a customer needs to identify the reward or challenge.',
          'Maximum nameAr length is 40 visible characters INCLUDING spaces. Aim for 25-35 characters whenever possible.',
          'English gaming abbreviations and terms are allowed when shorter or clearer, including SBC, FUTTIES, TOTW, FOF, GOTG, Icon and Hero.',
          'Use ترقية for Upgrade. Never use أبجريد.',
          'Keep all numbers as English digits 0-9. Never use Arabic-Indic or Persian digits.',
          'Write ratings in the compact form +85, +87, +95 rather than 85+.',
          'Prefer direct explanatory names instead of literal formulas.',
          'Examples:',
          '10x 85+ Upgrade -> ترقية 10 لاعبين +85',
          '3x 87-90 Upgrade -> ترقية 3 لاعبين 87-90',
          '1 of 3 87+ Player Pick -> اختيار 1 من 3 لاعبين +87',
          '1 of 3 95+ FOF or FUTTIES T1-T3 Player Pick -> اختيار 1 من 3 +95 FOF/FUTTIES T1-3',
          '94+ GOTG or FUTTIES Icon or Hero Player Pick -> اختيار +94 GOTG/FUTTIES Icon/Hero',
          'Player names should normally be transliterated into Arabic.',
          'Do not add a generic word such as تحدي unless it is necessary.',
          'Keep every id and sourceName byte-for-byte exact. Do not add, remove, merge or reorder entries.',
          'Return ONLY a valid JSON array. Every object must contain exactly: id, sourceName, nameAr.',
          `INPUT:${JSON.stringify(missingTranslations)}`,
      ].join('\n')
    : null;

return [
    {
        json: {
            ...config,
            translationReady: missingTranslations.length === 0,
            sourceRecords: records,
            missingTranslations,
            prompt,
            translationSchemaVersion: TRANSLATION_SCHEMA_VERSION,
            translationMaxVisibleLength: MAX_NAME_LENGTH,
            sourceAudit:
                $('Merge Provider Sources').first().json.sourceAudit ?? null,
        },
    },
];
