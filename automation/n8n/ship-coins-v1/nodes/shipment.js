/* eslint-disable */
// ship-coins v1 — the one shipment this run places, whichever branch built it.
//
// v14's HTTP nodes read `$('Coins Item')` or `$('SBC: Match & Validate')`
// depending on the path; here both paths end in this node so every later
// expression reads `$('Shipment')`. Nothing is computed: this is the named
// hand-off point, and the last place a bad shipment can be refused before a
// supplier is asked anything.

const shipment = $input.first().json;
const orderId = shipment.orderId;

if (!(Number(shipment.amountK) > 0)) {
    throw new Error(`[shipment] order ${orderId}: the shipment carries no coins`);
}
if (!(Number(shipment.calculatedMaxPrice) > 0)) {
    throw new Error(`[shipment] order ${orderId}: the shipment carries no budget`);
}
if (!Array.isArray(shipment.itemIds) || shipment.itemIds.length === 0) {
    throw new Error(`[shipment] order ${orderId}: the shipment names no order items`);
}

return [{ json: shipment }];
