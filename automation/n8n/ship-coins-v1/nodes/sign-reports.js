/* eslint-disable */
// ship-coins v1 — one signed placement report per funded item.
//
// The store verifies HMAC-SHA256 over `timestamp\neventId\nrawBody` with
// N8N_FULFILLMENT_SECRET (VerifyN8nFulfillmentSignature). rawBody is the
// exact byte sequence that is signed AND the exact byte sequence the HTTP
// node sends raw, so the signature cannot drift from the payload.

const crypto = require('crypto');
const secret = $('Config').first().json.N8N_FULFILLMENT_SECRET;
if (!secret || /^CONFIGURE_/.test(String(secret)) || String(secret).length < 32) {
    throw new Error('[report] N8N_FULFILLMENT_SECRET is not set in the Config node or shorter than 32 characters');
}

const ALPHABET = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';
function ulid() {
    let time = Date.now();
    let timestamp = '';
    for (let index = 0; index < 10; index += 1) {
        timestamp = ALPHABET[time % 32] + timestamp;
        time = Math.floor(time / 32);
    }
    let random = '';
    for (let index = 0; index < 16; index += 1) {
        random += ALPHABET[Math.floor(Math.random() * ALPHABET.length)];
    }
    return timestamp + random;
}

return $input.all().map(({ json }) => {
    const report = {
        order_item_public_id: json.order_item_public_id,
        supplier: json.supplier,
        supplier_order_id: json.supplier_order_id,
        delivery_phase: json.delivery_phase,
    };
    const rawBody = JSON.stringify(report);
    const timestamp = String(Math.floor(Date.now() / 1000));
    const event = ulid();
    const signature = crypto
        .createHmac('sha256', secret)
        .update(`${timestamp}\n${event}\n${rawBody}`)
        .digest('hex');

    return { json: { ...json, rawBody, timestamp, event, signature } };
});
