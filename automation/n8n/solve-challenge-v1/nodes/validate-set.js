/* eslint-disable */
// solve-challenge v1 — the "set exists and is priced" half of v14's
// "SBC: Match & Validate", run again before the solve.
//
// ship-coins already refused an unpriced challenge when it bought the coins;
// it is checked again here because a set can expire between the two phases,
// and v14 submitted nothing FFT would not price. The messages are v14's, so
// the alert reads as it always did. The coin arithmetic is not here: the
// coins are bought, and the solve is priced by FFT itself.

// ── 1. إعادة بناء مصفوفة التحديات من كل عناصر الـ input ──
const allInputItems = $input.all();
let availableSBCs = null;

if (allInputItems.length > 1) {
  availableSBCs = allInputItems.map(item => item.json);
}
if (!availableSBCs && allInputItems.length === 1) {
  const firstJson = allInputItems[0].json;
  if (Array.isArray(firstJson)) availableSBCs = firstJson;
  else if (firstJson.data && Array.isArray(firstJson.data)) availableSBCs = firstJson.data;
  else if (firstJson.sbcs && Array.isArray(firstJson.sbcs)) availableSBCs = firstJson.sbcs;
}
if (!availableSBCs && allInputItems.length === 1) {
  const firstJson = allInputItems[0].json;
  if (firstJson.setID !== undefined) availableSBCs = [firstJson];
}

const request = $('Verify Request').first().json.request;
const orderId = request.order_number;
const item = request.item;

if (!availableSBCs || !Array.isArray(availableSBCs) || availableSBCs.length === 0) {
  throw new Error(`[sbc] order ${orderId}: فشل قراءة قائمة التحديات. عدد العناصر المستقبلة: ${allInputItems.length}`);
}

const platform = item.supplier_platform || 'PS';
const setID = Number(item.sbc.set_id);
const sbcMatch = availableSBCs.find(sbc => Number(sbc.setID) === setID);
if (!sbcMatch) throw new Error(`[sbc] order ${orderId}: التحدي غير موجود: setID ${setID}`);

const coinPrice = platform === 'PC'
  ? parseFloat(sbcMatch.pcPrice)
  : parseFloat(sbcMatch.consolePrice);

if (!coinPrice || coinPrice <= 0) {
  const rawPrice = platform === 'PC' ? sbcMatch.pcPrice : sbcMatch.consolePrice;
  throw new Error(`[sbc] order ${orderId}: FFT ما مسعّر هذا التحدي حالياً (السعر المستلم: ${rawPrice}) — غالباً غير قابل للحل الآلي: "${sbcMatch.sbcName}" [منصة ${platform}]. الطلب يحتاج حل يدوي.`);
}

// v14's "SBC: Split SBC IDs" item, for one set: what "SBC: Submit Solve" reads.
return [{
  json: {
    orderId,
    orderItemPublicId: item.order_item_public_id,
    setID: sbcMatch.setID,
    sbcName: sbcMatch.sbcName,
    challengeAmount: sbcMatch.challengeAmount,
    expiry: sbcMatch.expiry,
    coinsPerSolveK: Math.ceil(coinPrice / 1000),
    timesToSolve: Math.max(1, Number(item.sbc.times_to_solve) || 1),
    platform,
    eaEmail: item.account.ea_email,
    customerName: request.customer_name || '',
    funding: item.funding || null,
    totalSBCsFound: availableSBCs.length,
  },
}];
