/* eslint-disable */
const item = $input.first().json;
const snapshot = item.catalogSnapshot;

if (
    !item.replayed &&
    item.publishResponse?.data?.runId === snapshot.runId &&
    item.publishResponse?.data?.status === 'completed'
) {
    const globalData = $getWorkflowStaticData('global');
    const state = globalData.sbcCatalogV1 ?? {};
    const lastSuccessfulItems = snapshot.products.map((product) => ({
        sourceId: String(product.variants[0].configuration.sourceId),
        sourceName: product.name.en,
        expiresAt: product.variants[0].configuration.expiresAt,
    }));
    globalData.sbcCatalogV1 = {
        ...state,
        lastSuccessfulCounts: {
            sourceCount: Number(item.sourceCount),
            eligibleCount: Number(item.eligibleCount),
            completedAt: new Date().toISOString(),
        },
        lastSuccessfulItems,
    };
}

return [
    {
        json: {
            status: item.replayed ? 'replayed' : 'published',
            publishAttempted: true,
            runId: snapshot.runId,
            products: snapshot.products.length,
            variants: snapshot.products.reduce(
                (total, product) => total + product.variants.length,
                0,
            ),
            publishResponse: item.publishResponse,
        },
    },
];
