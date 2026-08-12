/* eslint-disable */
const item = $input.first().json;
const snapshot = item.catalogSnapshot;

return [
    {
        json: {
            status: 'dry_run',
            publishAttempted: false,
            categories: snapshot.categories.length,
            products: snapshot.products.length,
            variants: snapshot.products.reduce(
                (total, product) => total + product.variants.length,
                0,
            ),
        },
    },
];
