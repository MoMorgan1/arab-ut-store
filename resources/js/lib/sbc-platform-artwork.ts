/**
 * Platform artwork for SBC surfaces. The PlayStation variant covers the
 * combined Sony/Xbox market, so it shows both logos.
 */
export function sbcPlatformIconUrls(platform: string): string[] {
    if (platform === 'playstation') {
        return [
            '/images/store/platforms/ps-logo-white-80.webp',
            '/images/store/platforms/xbox-logo-white-80.webp',
        ];
    }

    if (platform === 'xbox') {
        return ['/images/store/platforms/xbox-logo-white-80.webp'];
    }

    if (platform === 'pc') {
        return ['/images/store/platforms/pc-logo.svg'];
    }

    return [];
}
