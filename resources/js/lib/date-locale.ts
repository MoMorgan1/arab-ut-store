/**
 * The locale every date and time in the store is formatted with.
 *
 * Dates read in English in both the Arabic and English interfaces. That is a
 * deliberate owner decision (2026-08-24), taken after an iPhone rendered a
 * conversation as "١٠ ربيع الأول": `ar-SA` carries the Umm al-Qura calendar as
 * its regional default and Safari honours it, so Arabic-locale dates were being
 * shown on the Hijri calendar. Node's ICU quietly falls back to Gregorian,
 * which is why the whole test suite passed while real phones did not.
 *
 * Pinning the locale rather than deriving it from the interface language is the
 * point: it cannot drift back to a calendar nobody asked for, and there is one
 * place to change if that decision is ever revisited.
 *
 * Currency is deliberately NOT covered here — prices keep their own locale in
 * `money.ts`, because number and currency formatting were never the problem.
 */
export const DATE_LOCALE = 'en-GB';
