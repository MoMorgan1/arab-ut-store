export function catalogPlatformName(
    platform: string,
    fallback: string,
    locale: 'ar' | 'en',
): string {
    if (platform === 'playstation') {
        return locale === 'ar' ? 'سوني / إكس بوكس' : 'PlayStation / Xbox';
    }

    if (platform === 'pc') {
        return locale === 'ar' ? 'بي سي' : 'PC';
    }

    return fallback;
}
