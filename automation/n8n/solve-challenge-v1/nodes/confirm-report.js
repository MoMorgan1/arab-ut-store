/* eslint-disable */
// solve-challenge v1 — the placement report must have landed, then the
// answer for the store.
//
// The report HTTP node runs with neverError + fullResponse, so a refused
// report reaches this node as data rather than as an unhandled node error.
// Anything but 200 with acknowledged:true throws: the solve was submitted at
// FFT and the store does not know - the exact case the ops alert is for, and
// the case the store's retry then repairs, since the report endpoint is
// idempotent and a second run reports the same solve ids again.

const solve = $('Validate Set').first().json;
const orderId = solve.orderId;
const responses = $input.all();

if (responses.length !== 1) {
    throw new Error(`[report] order ${orderId}: ${responses.length} report(s) answered for one challenge`);
}

function decode(body) {
    if (body == null) return {};
    if (typeof body === 'string') { try { return JSON.parse(body); } catch (e) { return { raw: body }; } }
    return body;
}

const { json } = responses[0];
const status = Number(json.statusCode || 0);
const body = decode(json.body);
const data = body && body.data;

if (!(status === 200 && data && data.acknowledged === true)) {
    const error = (body && body.error) || {};
    throw new Error(`[report] order ${orderId}: the store refused the placement report (HTTP ${status} ${error.code || ''} ${error.message || body.message || ''})`.trim());
}

return [{
    json: {
        acknowledged: true,
        placed: {
            order_item_public_id: data.order_item_public_id,
            supplier: data.supplier,
            supplier_order_id: data.supplier_order_id,
            job_public_id: data.job_public_id,
        },
        orderId,
    },
}];
