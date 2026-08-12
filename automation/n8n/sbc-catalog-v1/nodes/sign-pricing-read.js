/* eslint-disable */
const item = $input.first().json;
const secret = $env.N8N_SBC_PRICING_READ_SECRET;

if (!secret) {
    return [
        {
            json: {
                ...item,
                pricingReadSigned: false,
                failureReason:
                    'N8N_SBC_PRICING_READ_SECRET is not configured on the n8n host',
            },
        },
    ];
}

const crypto = require('crypto');
const timestamp = String(Math.floor(Date.now() / 1000));
const canonical = `${timestamp}\nGET\n${item.settings.pricingPath}\n`;
const signature = crypto
    .createHmac('sha256', secret)
    .update(canonical)
    .digest('hex');

return [{ json: { ...item, pricingReadSigned: true, timestamp, signature } }];
