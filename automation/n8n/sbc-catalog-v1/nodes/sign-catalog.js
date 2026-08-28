/* eslint-disable */
const item = $input.first().json;
const secret = $env.N8N_SBC_CATALOG_SECRET;

if (!secret) {
    throw new Error(
        '[sign] N8N_SBC_CATALOG_SECRET is not configured on the n8n host',
    );
}
if (!item.catalogSnapshot) {
    throw new Error('[sign] there is no catalog snapshot to sign');
}

const crypto = require('crypto');
const timestamp = String(Math.floor(Date.now() / 1000));
const event = item.catalogSnapshot.eventId;

// rawBody is the exact byte sequence that is HMAC'd AND the exact byte sequence
// the HTTP node sends, so the signature cannot drift from the payload.
const rawBody = JSON.stringify(item.catalogSnapshot);
const canonical = `${timestamp}\n${event}\nn8n-sbc\n${rawBody}`;
const signature = crypto
    .createHmac('sha256', secret)
    .update(canonical)
    .digest('hex');

return [{ json: { ...item, timestamp, event, rawBody, signature } }];
