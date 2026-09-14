/* eslint-disable */
// ship-coins v1 — v14's "SBC: Match & Validate", with the challenge list read
// from the shipment instead of Router Logic1 and the budget taken from the
// store instead of the price sheet. The matching, the unpriced-challenge
// refusal and the coin arithmetic are v14's.
//
// FFT prices a challenge live (consolePrice / pcPrice, in coins), so the
// coins a challenge shipment must carry are only known here.

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

const shipment = $('Plan Shipment').first().json;
const orderId = shipment.orderId;

if (!availableSBCs || !Array.isArray(availableSBCs) || availableSBCs.length === 0) {
  throw new Error(`[sbc] order ${orderId}: فشل قراءة قائمة التحديات. عدد العناصر المستقبلة: ${allInputItems.length}`);
}

const sbcRouterItems = shipment.sbcItems || [];
if (sbcRouterItems.length === 0) {
  throw new Error(`[sbc] order ${orderId}: لم يتم العثور على أي SBC في بيانات الطلب`);
}

const platform = shipment.platform || 'PS';

// ── 3. التحقق من كل SBC مطلوب ──
const sbcSetIDs = [];
let totalCoinsK = 0;
let totalChallengeAmount = 0;
const sbcNames = [];

for (const routerItem of sbcRouterItems) {
  const setID = routerItem.sbcSetID;
  const sbcMatch = availableSBCs.find(sbc => Number(sbc.setID) === Number(setID));
  if (!sbcMatch) throw new Error(`[sbc] order ${orderId}: التحدي غير موجود: setID ${setID}`);

  const coinPrice = platform === 'PC'
    ? parseFloat(sbcMatch.pcPrice)
    : parseFloat(sbcMatch.consolePrice);

  if (!coinPrice || coinPrice <= 0) {
    const rawPrice = platform === 'PC' ? sbcMatch.pcPrice : sbcMatch.consolePrice;
    throw new Error(`[sbc] order ${orderId}: FFT ما مسعّر هذا التحدي حالياً (السعر المستلم: ${rawPrice}) — غالباً غير قابل للحل الآلي: "${sbcMatch.sbcName}" [منصة ${platform}]. الطلب يحتاج حل يدوي.`);
  }

  const coinsPerSolveK = Math.ceil(coinPrice / 1000);
  const timesToSolve = routerItem.sbcTimesToSolve || 1;

  totalCoinsK += coinsPerSolveK * timesToSolve;
  totalChallengeAmount += (sbcMatch.challengeAmount || 0) * timesToSolve;
  sbcNames.push(sbcMatch.sbcName);

  sbcSetIDs.push({
    setID: sbcMatch.setID,
    sbcName: sbcMatch.sbcName,
    challengeAmount: sbcMatch.challengeAmount,
    expiry: sbcMatch.expiry,
    coinsPerSolveK,
    timesToSolve,
    orderItemPublicId: routerItem.orderItemPublicId,
  });
}

// ── 4. إضافة الكوينز الإضافية (لو فيه طلب كوينز مدمج) ──
const extraCoinsK = shipment.extraCoinsK || 0;
const totalAmountK = totalCoinsK + extraCoinsK;

// ── 6. إرجاع عنصر واحد مدمج ──
return [{
  json: {
    ...shipment,
    sbcSetIDs,
    sbcName: sbcNames.join(' + '),
    challengeAmount: totalChallengeAmount,
    amountK: totalAmountK,
    extraCoinsK,
    totalSBCsFound: availableSBCs.length,
  }
}];
