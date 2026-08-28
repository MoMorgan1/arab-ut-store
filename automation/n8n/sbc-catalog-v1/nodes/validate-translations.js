/* eslint-disable */
// The LLM is the one genuinely untrusted input in this workflow, so this gate
// earns its keep. Everything it returns is checked against the exact plan that
// was requested before any of it reaches the catalog.
//
// v3 had a subtler problem here: normalizeTranslatedName started with
// `structured || value`, so whenever the English name matched one of the
// titleFromStructuredSource patterns the LLM's answer was thrown away and a
// template used instead. Most SBC names match those patterns, so the workflow
// was paying for translations it discarded. The template is now a FALLBACK,
// used only when the model's own answer fails validation.

const plan = $('Plan Translations').first().json;

const MAX_NAME_LENGTH = 40;
const TRANSLATION_SCHEMA_VERSION = 4;
const LRI = '\u2066';
const PDI = '\u2069';
const BIDI_CONTROL_PATTERN = /[\u2066\u2067\u2068\u2069]/g;

function fail(reason) {
    throw new Error(`[translations] ${reason}`);
}

function stripBidiControls(value) {
    return String(value ?? '').replace(BIDI_CONTROL_PATTERN, '');
}

function toEnglishDigits(value) {
    return String(value ?? '')
        .replace(/[٠-٩]/g, (digit) => String(digit.charCodeAt(0) - 0x0660))
        .replace(/[۰-۹]/g, (digit) => String(digit.charCodeAt(0) - 0x06f0));
}

function compactEventText(value) {
    return String(value ?? '')
        .replace(/مهرجان كرة القدم/gi, 'FOF')
        .replace(/عظماء اللعبة/gi, 'GOTG')
        .replace(/فريق الأسبوع/gi, 'TOTW')
        .replace(/فوتيز/gi, 'FUTTIES')
        .replace(/آيكون/gi, 'Icon')
        .replace(/هيرو/gi, 'Hero')
        .replace(
            /\bFUTTIES\s+Team\s+(\d+)\s*(?:to|[-–])\s*(\d+)\b/gi,
            'FUTTIES T$1-$2',
        )
        .replace(/\bFUTTIES\s+T(\d+)\s*[-–]\s*T?(\d+)\b/gi, 'FUTTIES T$1-$2')
        .replace(/\bFUTTIES\s+Team\s+(\d+)\s*&\s*(\d+)\b/gi, 'FUTTIES T$1-$2')
        .replace(/\bFUTTIES\s+Team\s+(\d+)\b/gi, 'FUTTIES T$1')
        .replace(/\bFOF\s+(?:or|&)\s+FUTTIES\b/gi, 'FOF/FUTTIES')
        .replace(/\bGOTG\s+(?:or|&)\s+FUTTIES\b/gi, 'GOTG/FUTTIES')
        .replace(/\(?\bIcons?\s*(?:or|&)\s*Heroes?\b\)?/gi, 'Icon/Hero')
        .replace(/\s+أو\s+/g, '/')
        .replace(/\s+/g, ' ')
        .trim();
}

// Used only when the model's own answer cannot be made valid.
function titleFromStructuredSource(sourceName) {
    const source = String(sourceName ?? '').trim();
    let match = source.match(/^(\d+)x\s+(\d+)\+\s+Upgrade$/i);
    if (match) return `ترقية ${match[1]} لاعبين +${match[2]}`;

    match = source.match(/^(\d+)x\s+(\d+)-(\d+)\s+Upgrade$/i);
    if (match) return `ترقية ${match[1]} لاعبين ${match[2]}-${match[3]}`;

    match = source.match(/^(\d+)\s+of\s+(\d+)\s+(\d+)\+\s+Player Pick$/i);
    if (match) return `اختيار ${match[1]} من ${match[2]} لاعبين +${match[3]}`;

    match = source.match(
        /^(\d+)\s+of\s+(\d+)\s+(\d+)\+\s+(.+?)\s+Player Pick$/i,
    );
    if (match)
        return `اختيار ${match[1]} من ${match[2]} +${match[3]} ${compactEventText(match[4])}`.trim();

    match = source.match(/^(\d+)\s+of\s+(\d+)\s+(.+?)\s+Player Pick$/i);
    if (match)
        return `اختيار ${match[1]} من ${match[2]} ${compactEventText(match[3])}`.trim();

    match = source.match(/^(\d+)\+\s+(.+?)\s+Upgrade$/i);
    if (match) return `ترقية +${match[1]} ${compactEventText(match[2])}`.trim();

    if (/^Gold Upgrade$/i.test(source)) return 'ترقية ذهبية';
    return null;
}

function compactArabicTitle(value) {
    return compactEventText(value)
        .replace(/اختيار\s+لاعب\s+(\d+)\s+من\s+(\d+)/g, 'اختيار $1 من $2')
        .replace(/بتقييم\s*/g, '')
        .replace(/\bتقييم\s*/g, '')
        .replace(/الفرق?\s*(\d+)\s*(?:إلى|الى|[-–])\s*(\d+)/g, 'T$1-$2')
        .replace(/(\d{2,3})\s*\+/g, '+$1')
        .replace(/\s*\/\s*/g, '/')
        .replace(/\s+/g, ' ')
        .trim();
}

function isolateDirectionalRuns(value) {
    return stripBidiControls(value).replace(
        /(?:[A-Za-z]+[A-Za-z0-9+&/.\-]*|\+\d+(?:[-–]\d+)?|\d+(?:[-–]\d+)?)/g,
        (token) => `${LRI}${token}${PDI}`,
    );
}

function tidy(value) {
    return compactArabicTitle(
        toEnglishDigits(stripBidiControls(value))
            .replace(/\bUpgrade\b/gi, 'ترقية')
            .replace(/أبجريد/gi, 'ترقية')
            .replace(/ابجريد/gi, 'ترقية')
            .replace(/\s+/g, ' ')
            .trim(),
    )
        .replace(/\s+([,.;:!?])/g, '$1')
        .replace(/\s{2,}/g, ' ')
        .trim();
}

function isAcceptable(name) {
    return (
        name.length >= 2 &&
        name.length <= MAX_NAME_LENGTH &&
        /[\u0600-\u06ff]/.test(name) &&
        !/[٠-٩۰-۹]/.test(name) &&
        !/أبجريد|ابجريد/i.test(name)
    );
}

if (plan.translationReady === true) {
    return [{ json: { ...plan, translationReady: true } }];
}
if (
    !Array.isArray(plan.missingTranslations) ||
    !plan.missingTranslations.length
) {
    fail('translation plan is invalid');
}

const response = $input.first().json;
let raw = response.text ?? response.output ?? response.response ?? response;
if (typeof raw === 'object' && raw !== null) raw = JSON.stringify(raw);
if (typeof raw !== 'string') fail('translation response is missing');

raw = raw
    .trim()
    .replace(/^```(?:json)?\s*/i, '')
    .replace(/\s*```$/, '');

let translated;
try {
    translated = JSON.parse(raw);
} catch {
    fail(
        `translation response is not valid JSON; first 200 chars: ${raw.slice(0, 200)}`,
    );
}
if (!Array.isArray(translated))
    fail('translation response must be a JSON array');
if (translated.length !== plan.missingTranslations.length) {
    fail(
        `translation count ${translated.length} does not match the ${plan.missingTranslations.length} requested`,
    );
}

const expected = new Map(
    plan.missingTranslations.map((pair) => [String(pair.id), pair.sourceName]),
);
const staged = {};
const seen = new Set();
const repairs = [];
const templateFallbacks = [];

for (const entry of translated) {
    if (!entry || typeof entry !== 'object' || Array.isArray(entry)) {
        fail('translation entry is invalid');
    }
    if (
        !Object.hasOwn(entry, 'id') ||
        !Object.hasOwn(entry, 'sourceName') ||
        !Object.hasOwn(entry, 'nameAr')
    ) {
        fail('translation entry is missing exact identity fields');
    }

    const id = String(entry.id);
    if (!expected.has(id)) fail(`translation returned unexpected SBC id ${id}`);
    if (seen.has(id)) fail(`translation returned duplicate SBC id ${id}`);
    seen.add(id);

    const sourceName = expected.get(id);
    if (entry.sourceName !== sourceName) {
        fail(`translation source name mismatch for SBC id ${id}`);
    }

    const original =
        typeof entry.nameAr === 'string' ? entry.nameAr.trim() : '';

    // The model's own answer first.
    let name = tidy(original);

    // Only if it is unusable do we fall back to the structured template, and only
    // then to a hard failure. v3 inverted this and always preferred the template.
    if (!isAcceptable(name)) {
        const template = titleFromStructuredSource(sourceName);
        const templated = template ? tidy(template) : '';
        if (templated && isAcceptable(templated)) {
            name = templated;
            templateFallbacks.push({
                id,
                model: original,
                template: templated,
            });
        } else {
            fail(
                `translation is unusable for SBC id ${id} and no template applies; model returned: ${original.slice(0, 80)}`,
            );
        }
    }

    if (name !== stripBidiControls(original).trim()) {
        repairs.push({ id, before: original, after: name });
    }

    staged[`${id}\u0000${sourceName}`] = {
        sourceName,
        nameAr: isolateDirectionalRuns(name),
        schemaVersion: TRANSLATION_SCHEMA_VERSION,
    };
}

if (seen.size !== expected.size) fail('translation response is incomplete');

const globalData = $getWorkflowStaticData('global');
const state = globalData.sbcCatalog ?? {};
const merged = { ...(state.translations ?? {}), ...staged };

// The cache is keyed by id + source name, so every renamed or retired SBC
// leaves an entry behind forever. v3 never pruned it, and n8n static data is
// loaded and saved on every single run. Keep only the names this run actually
// asked about, plus a bounded tail of recent ones so a transient source blip
// does not force a full re-translation next run.
const liveKeys = new Set(
    (plan.sourceRecords ?? [])
        .filter((record) => record && record.name)
        .map((record) => `${record.id}\u0000${String(record.name).trim()}`),
);
const MAX_STALE_CACHE_ENTRIES = 500;
const kept = {};
const stale = [];
for (const [key, value] of Object.entries(merged)) {
    if (liveKeys.has(key)) kept[key] = value;
    else stale.push([key, value]);
}
for (const [key, value] of stale.slice(-MAX_STALE_CACHE_ENTRIES))
    kept[key] = value;

globalData.sbcCatalog = {
    ...state,
    translations: kept,
};

return [
    {
        json: {
            ...plan,
            translationReady: true,
            missingTranslations: [],
            translationRepairCount: repairs.length,
            translationRepairs: repairs.slice(0, 25),
            templateFallbackCount: templateFallbacks.length,
            templateFallbacks: templateFallbacks.slice(0, 25),
            translationCacheSize: Object.keys(kept).length,
            translationCachePruned: Math.max(
                0,
                stale.length - MAX_STALE_CACHE_ENTRIES,
            ),
        },
    },
];
