/* eslint-disable */
// solve-challenge v1 — the solve ids FFT issued, as the placement report.
//
// v14's "SBC: Solve Accepted?" and the id-collecting half of
// "SBC Solve: Prepare Poll" in one place. newSBCAPI answers a list (one entry
// per solve), sometimes as a JSON string under `data`, each entry carrying
// `status` and `sbcSolveID`. Anything but a first entry with status
// "processed" is a rejection - v14's alert said the usual cause is that FFT
// has no customer under that email, which the corrected-email path now
// avoids - and it throws, so the ops alert names the order and the store
// retries the item later.
//
// One report row, challenge phase: the first solve id is the reference and
// every id is listed, the way the store's placement endpoint takes them.

const solve = $('Validate Set').first().json;
const orderId = solve.orderId;

const answer = $input.first().json || {};
let parsed;
try {
  parsed = typeof answer.data === 'string' ? JSON.parse(answer.data) : (answer.data || answer);
} catch (e) {
  throw new Error(`[solve] order ${orderId}: FFT رجّع رد غير مقروء: ${String(answer.data).slice(0, 200)}`);
}
const entries = Array.isArray(parsed) ? parsed : [parsed];
const first = entries[0];

if (!first || first.status !== 'processed') {
  const detail = (first && (first.error || first.message || first.status)) || (parsed && (parsed.error || parsed.message)) || JSON.stringify(parsed).slice(0, 300);
  throw new Error(`[solve] order ${orderId}: FFT رفض تقديم التحدي "${solve.sbcName}": ${detail} — غالباً ما فيه عميل بهذا الإيميل عند FFT`);
}

const ids = entries
  .map((entry) => entry && entry.sbcSolveID)
  .filter((id) => id !== undefined && id !== null && String(id) !== '')
  .map((id) => String(id));

if (ids.length === 0) {
  throw new Error(`[solve] order ${orderId}: FFT قبل التحدي بدون رقم حل (sbcSolveID)`);
}

return [{
  json: {
    orderId,
    order_item_public_id: solve.orderItemPublicId,
    supplier: 'fft',
    supplier_order_id: ids[0],
    delivery_phase: 'challenge',
    challenge_ids: ids,
  },
}];
