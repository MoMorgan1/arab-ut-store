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
    const previous = state.lastSuccessfulCounts ?? {};
    globalData.sbcCatalogV1 = {
        ...state,
        lastSuccessfulCounts: {
            sourceCount: Math.max(
                Number(previous.sourceCount) || 0,
                Number(item.sourceCount) || 0,
            ),
            eligibleCount: Math.max(
                Number(previous.eligibleCount) || 0,
                Number(item.eligibleCount) || 0,
            ),
            completedAt: new Date().toISOString(),
        },
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
