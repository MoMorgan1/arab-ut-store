export function formatDocumentTitle(
    pageTitle: string,
    applicationName: string,
): string {
    if (!pageTitle || pageTitle === applicationName) {
        return applicationName;
    }

    return `${pageTitle} - ${applicationName}`;
}
