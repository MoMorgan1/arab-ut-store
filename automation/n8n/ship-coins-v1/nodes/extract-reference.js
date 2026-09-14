/* eslint-disable */
// ship-coins v1 — the supplier's reference for the shipment just placed.
//
// The first half of v14's "Coins: Prepare Poll" (the second half froze the
// poll context, and the poll now lives in the store). UTT answers
// `order.idOrder`; FFT answers `data.orderID`, sometimes as a JSON string.
// No id from either is an in-band rejection - HTTP 200 with an error body -
// and v14 flagged it `chargeFailed`; here it throws, so the ops alert names
// the order and the store retries the shipment later.
//
// One output item per order item the shipment funds: the placement report
// is filed once per item with the same reference (the store's shared-
// shipment rule).

const shipment = $('Shipment').first().json;
const orderId = shipment.orderId;

let supplier = null, reference = null, rawError = null;
try {
  const u = $('UTT: Add Order Public').first().json;
  if (u && u.order && u.order.idOrder) { supplier = 'utt'; reference = String(u.order.idOrder); }
  else if (u) rawError = u.error || u.message || JSON.stringify(u).slice(0, 300);
} catch (e) {}
if (!supplier) {
  try {
    const f = $('External: Buy Coins').first().json;
    const p = typeof f.data === 'string' ? JSON.parse(f.data) : (f.data || f);
    if (p && p.orderID) { supplier = 'fft'; reference = String(p.orderID); }
    else if (p) rawError = (p.error && p.error.message) || p.error || p.message || JSON.stringify(p).slice(0, 300);
  } catch (e) {}
}

if (!supplier) {
  const detail = rawError ? String(rawError) : 'لم يصدر رقم طلب';
  const hint = /429|[Tt]oo [Mm]any/.test(detail) ? ' — غالباً ضغط طلبات (rate limit)' : '';
  throw new Error(`[place] order ${orderId}: المورد رفض طلب الشحن: ${detail}${hint}`);
}

return shipment.itemIds.map((orderItemPublicId) => ({
  json: {
    orderId,
    order_item_public_id: orderItemPublicId,
    supplier,
    supplier_order_id: reference,
    delivery_phase: 'coins',
  },
}));
