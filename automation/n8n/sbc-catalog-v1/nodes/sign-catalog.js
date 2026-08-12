/* eslint-disable */
const item = $input.first().json;

if (!item.valid || !item.catalogSnapshot) {
    return [
        {
            json: {
                ...item,
                catalogSigned: false,
                failureReason:
                    item.failureReason ||
                    'Cannot sign an invalid catalog snapshot',
            },
        },
    ];
}

const secret = $env.N8N_SBC_CATALOG_SECRET;
if (!secret) {
    return [
        {
            json: {
                ...item,
                catalogSigned: false,
                failureReason:
                    'N8N_SBC_CATALOG_SECRET is not configured on the n8n host',
            },
        },
    ];
}

const crypto = require('crypto');
const timestamp = String(Math.floor(Date.now() / 1000));
const event = item.catalogSnapshot.eventId;
const rawBody = JSON.stringify(item.catalogSnapshot);
const canonical = `${timestamp}\n${event}\nn8n-sbc\n${rawBody}`;
const signature = crypto
    .createHmac('sha256', secret)
    .update(canonical)
    .digest('hex');

return [
    {
        json: {
            ...item,
            catalogSigned: true,
            timestamp,
            event,
            rawBody,
            signature,
        },
    },
];
