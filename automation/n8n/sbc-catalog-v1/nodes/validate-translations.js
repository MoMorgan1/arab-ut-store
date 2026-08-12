/* eslint-disable */
const plan = $('Prepare Translations').first().json;

function result(fields) {
    return [{ json: { ...plan, ...fields } }];
}

function fail(reason) {
    return result({
        translationReady: false,
        failureReason: reason,
    });
}

function normalizeApprovedFcTerms(value) {
    return value
        .trim()
        .replace(/\bFUTTIES\b/gi, '\u0641\u0648\u062a\u064a\u0632')
        .replace(
            /\bFOF\b/gi,
            '\u0645\u0647\u0631\u062c\u0627\u0646 \u0643\u0631\u0629 \u0627\u0644\u0642\u062f\u0645',
        )
        .replace(
            /\bGOTG\b/gi,
            '\u0639\u0638\u0645\u0627\u0621 \u0627\u0644\u0644\u0639\u0628\u0629',
        )
        .replace(
            /\bTOTW\b/gi,
            '\u0641\u0631\u064a\u0642 \u0627\u0644\u0623\u0633\u0628\u0648\u0639',
        )
        .replace(/\bOVR\b/gi, '\u062a\u0642\u064a\u064a\u0645')
        .replace(/\bEVO\b/gi, '')
        .replace(
            /\bSBCs?\b/gi,
            '\u062a\u062d\u062f\u064a\u0627\u062a \u0628\u0646\u0627\u0621 \u0627\u0644\u062a\u0634\u0643\u064a\u0644\u0627\u062a',
        )
        .replace(/(\d+)\s*x\b/gi, '$1\u00d7')
        .replace(/\s{2,}/g, ' ')
        .trim();
}

if (!plan.translationPlanValid || !Array.isArray(plan.missingTranslations)) {
    return fail(plan.failureReason || 'Translation plan is invalid');
}
if (plan.missingTranslations.length === 0) {
    return result({ translationReady: true, failureReason: null });
}

const response = $input.first().json;
let raw = response.text ?? response.output ?? response.response ?? response;
if (typeof raw === 'object' && raw !== null) raw = JSON.stringify(raw);
if (typeof raw !== 'string')
    return fail('Gemini translation response is missing');
raw = raw
    .trim()
    .replace(/^```(?:json)?\s*/i, '')
    .replace(/\s*```$/, '');

let translated;
try {
    translated = JSON.parse(raw);
} catch {
    return fail('Gemini translation response is not valid JSON');
}
if (!Array.isArray(translated))
    return fail('Gemini translation response must be a JSON array');
if (translated.length !== plan.missingTranslations.length) {
    return fail(
        `Gemini translation count ${translated.length} does not match requested count ${plan.missingTranslations.length}`,
    );
}

const expected = new Map(
    plan.missingTranslations.map((pair) => [String(pair.id), pair.sourceName]),
);
const staged = {};
const seen = new Set();
for (const entry of translated) {
    if (!entry || typeof entry !== 'object' || Array.isArray(entry))
        return fail('Gemini translation entry is invalid');
    if (!Object.hasOwn(entry, 'id') || !Object.hasOwn(entry, 'sourceName'))
        return fail(
            'Gemini translation entry is missing exact identity fields',
        );
    const id = String(entry.id);
    if (!expected.has(id))
        return fail(`Gemini returned unexpected EasySBC id ${id}`);
    if (seen.has(id)) return fail(`Gemini returned duplicate EasySBC id ${id}`);
    seen.add(id);
    if (entry.sourceName !== expected.get(id))
        return fail(`Gemini source name mismatch for EasySBC id ${id}`);
    const nameAr =
        typeof entry.nameAr === 'string'
            ? normalizeApprovedFcTerms(entry.nameAr)
            : '';
    if (
        nameAr.length < 2 ||
        nameAr.length > 120 ||
        !/[\u0600-\u06ff]/.test(nameAr) ||
        /[A-Za-z]/.test(nameAr)
    ) {
        return fail(
            `Gemini translation for EasySBC id ${id} must be Arabic-only and at most 120 characters`,
        );
    }
    const sourceName = expected.get(id);
    staged[`${id}\u0000${sourceName}`] = {
        sourceName,
        nameAr,
    };
}
if (seen.size !== expected.size)
    return fail('Gemini translation response is incomplete');

const globalData = $getWorkflowStaticData('global');
const state = globalData.sbcCatalogV1 ?? {};
globalData.sbcCatalogV1 = {
    ...state,
    translations: { ...(state.translations ?? {}), ...staged },
};

return result({
    translationReady: true,
    failureReason: null,
    missingTranslations: [],
});
