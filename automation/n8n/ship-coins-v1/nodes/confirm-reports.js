/* eslint-disable */
// ship-coins v1 — every placement report must have landed, then the answer
// for the store.
//
// The report HTTP node runs with neverError + fullResponse, so a refused
// report reaches this node as data rather than as an unhandled node error.
// Anything but 200 with acknowledged:true throws: the placement happened at
// the supplier and the store does not know - the exact case the ops alert is
// for, and the case the store's retry then repairs, since the report endpoint
// is idempotent and a second run reports the same reference again.

const shipment = $('Shipment').first().json;
const orderId = shipment.orderId;
const responses = $input.all();

if (responses.length !== shipment.itemIds.length) {
    throw new Error(`[report] order ${orderId}: ${responses.length} report(s) answered for ${shipment.itemIds.length} item(s)`);
}

function decode(body) {
    if (body == null) return {};
    if (typeof body === 'string') { try { return JSON.parse(body); } catch (e) { return { raw: body }; } }
    return body;
}

const placed = [];
for (const { json } of responses) {
    const status = Number(json.statusCode || 0);
    const body = decode(json.body);
    const data = body && body.data;
    if (status === 200 && data && data.acknowledged === true) {
        placed.push({
            order_item_public_id: data.order_item_public_id,
            supplier: data.supplier,
            supplier_order_id: data.supplier_order_id,
            job_public_id: data.job_public_id,
        });
        continue;
    }
    const error = (body && body.error) || {};
    throw new Error(`[report] order ${orderId}: the store refused the placement report (HTTP ${status} ${error.code || ''} ${error.message || body.message || ''})`.trim());
}

return [{
    json: {
        // Not acknowledged while other shipments remain: the store retries with
        // what is still unplaced, and the next run places the next group.
        acknowledged: Number(shipment.remainingGroups || 0) === 0,
        placed,
        remaining: Number(shipment.remainingGroups || 0),
        orderId,
    },
}];
