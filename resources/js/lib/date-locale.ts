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

/**
 * A timestamp for a header: the day, the month short, the year, then the time.
 *
 * `dateStyle: 'long'` with `timeStyle: 'short'` produces "13 September 2026 at
 * 01:59" — a spelled-out month and an English preposition sitting inside an
 * Arabic line. The pieces are the same; only the joinery changes, so the
 * Gregorian-calendar decision above is untouched.
 */
export function formatTimestamp(value: string | Date): string {
    const date = value instanceof Date ? value : new Date(value);

    // en-GB abbreviates September to "Sept", the one four-letter short month
    // it has. Three letters everywhere keeps the line the same length whatever
    // month it lands in, which matters because it sits at the end of the row.
    const parts = new Intl.DateTimeFormat(DATE_LOCALE, {
        day: 'numeric',
        month: 'short',
        year: 'numeric',
    }).formatToParts(date);

    const day = parts
        .map((part) =>
            part.type === 'month'
                ? part.value.replace(/\.$/, '').slice(0, 3)
                : part.value,
        )
        .join('');

    const time = new Intl.DateTimeFormat(DATE_LOCALE, {
        hour: '2-digit',
        minute: '2-digit',
    }).format(date);

    return `${day} · ${time}`;
}
