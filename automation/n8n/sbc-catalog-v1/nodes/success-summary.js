/* eslint-disable */
const item = $input.first().json;
const snapshot = item.catalogSnapshot;

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
