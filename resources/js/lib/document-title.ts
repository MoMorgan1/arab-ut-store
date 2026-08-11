export const DEFAULT_APPLICATION_NAME = 'Arab UT';

export function formatDocumentTitle(
    pageTitle: string,
    applicationName = DEFAULT_APPLICATION_NAME,
): string {
    if (!pageTitle || pageTitle === applicationName) {
        return applicationName;
    }

    if (pageTitle.includes(applicationName)) {
        return pageTitle;
    }

    return `${pageTitle} - ${applicationName}`;
}
