/* eslint-disable */
const response = $input.first().json;
const statusCode = Number(response.statusCode || 200);
let body = response.body ?? response;

if (typeof body === 'string') {
    try {
        body = JSON.parse(body);
    } catch {}
}

function failure(reason) {
    return [{ json: { valid: false, failureReason: reason } }];
}

if (
    response.error ||
    response.errorMessage ||
    statusCode !== 200 ||
    !body ||
    typeof body !== 'object' ||
    Array.isArray(body)
) {
    return failure(`Laravel SBC pricing read failed with HTTP ${statusCode}`);
}

if (
    JSON.stringify(Object.keys(body)) !==
    JSON.stringify(['schemaVersion', 'pricingVersion', 'pricedAt', 'quotes'])
) {
    return failure('SBC pricing response has an unexpected top-level shape');
}

if (
    body.schemaVersion !== 1 ||
    !Number.isInteger(body.pricingVersion) ||
    body.pricingVersion < 1 ||
    typeof body.pricedAt !== 'string'
) {
    return failure('SBC pricing response metadata is invalid');
}

if (
    !body.quotes ||
    JSON.stringify(Object.keys(body.quotes)) !==
        JSON.stringify(['playstation_fast', 'pc'])
) {
    return failure(
        'SBC pricing response must contain exactly the PlayStation fast and PC quotes',
    );
}

const expected = {
    playstation_fast: { platform: 'playstation', delivery: 'fast' },
    pc: { platform: 'pc', delivery: null },
};

for (const [key, identity] of Object.entries(expected)) {
    const quote = body.quotes[key];
    if (
        !quote ||
        JSON.stringify(Object.keys(quote)) !==
            JSON.stringify(['platform', 'delivery', 'quantity', 'totalHalalah'])
    ) {
        return failure(`${key} quote has an unexpected shape`);
    }
    if (
        quote.platform !== identity.platform ||
        quote.delivery !== identity.delivery ||
        quote.quantity !== 1_000_000
    ) {
        return failure(`${key} is not the authoritative one-million quote`);
    }
    if (!Number.isInteger(quote.totalHalalah) || quote.totalHalalah <= 0) {
        return failure(`${key} totalHalalah is invalid`);
    }
}

return [{ json: { valid: true, failureReason: null, pricing: body } }];
