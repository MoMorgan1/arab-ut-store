/* eslint-disable */
// ══════════════════════════════════════════════════════════════════════
// SUPPLIER DECISION ENGINE — UTT vs FFT for a coins order.
//
// v14's node, verbatim, with two renames: the shipment is `$('Shipment')`
// (v14 read `$('Coins Item')` on the coins path and
// `$('SBC: Match & Validate')` on the challenge path; both end in Shipment
// here) and the budget it reads was computed by the store rather than read
// from the price sheet. Everything below the input block is v14.11.
//
// PRICE UNITS: calculatedMaxPrice and UTT stock.price are BOTH EUR per 100K.
// COMBINED STOCKS (v14.6, validated live): UTT pools capacity across the stocks
// listed in publicSale, so we greedily build the CHEAPEST combination whose combined
// getMaxOrderPrediction covers the order. MAX_COMBO 10 = the EA card budget per
// order/36h (raised from 4 on 2026-07-04); the publicSale stock list itself is
// unlimited, so failover pads are free until a card is actually used, and compare
// FFT against the BLENDED price of that combination (marginal-gain weighted).
// COOLDOWN: orderCardsRemaining < 3 → force UTT (cross-system ban protection).
// PARTIAL (v14.11): neither covers fully → whoever delivers MORE right now
// (FFT keeps ties — it tops up; UTT must beat FFT by 20%+). Full-coverage FFT
// price-win additionally needs 10% preview headroom (owner decision 2026-07-04).
// ══════════════════════════════════════════════════════════════════════
const src = $('Shipment').first().json;
let uttStocksData = {};
try { uttStocksData = $('UTT: Get Stocks').first().json || {}; } catch (e) {}

const amountK = src.amountK;
const orderAmountCoins = amountK * 1000;
const platform = src.platform;
const startCoins = src.startCoins || 200000;
const maxPrice = Number(src.calculatedMaxPrice) || 0;
const stocks = uttStocksData.stocks || [];
const ratioEuroUsd = parseFloat(uttStocksData.ratioEuroUsd) || 1.15;

// FFT preview balance ladder (kept as-is per business decision 2026-07)
const topUpTarget = platform === 'PC' ? 0 : (
    amountK <= 300 ? 20000 : amountK <= 500 ? 50000 : amountK <= 1000 ? 200000
  : amountK <= 1500 ? 300000 : amountK <= 2000 ? 300000 : 400000);
const fftCustomerBalance = Math.max(startCoins, topUpTarget);

// keep stocks big enough to matter in a combination (not: cover alone)
const minUseful = Math.max(100000, orderAmountCoins * 0.15);
const validStocks = stocks
  .filter(s => (parseFloat(s.averageCoinsAvailable) * 5) >= minUseful)
  .filter(s => parseFloat(s.price) <= maxPrice)
  .sort((a, b) => parseFloat(a.price) - parseFloat(b.price))
  .slice(0, 20);

const CONFIG = $('Config').first().json;
const UTT_API_KEY = CONFIG.UTT_API_KEY;
const uttPlatform = platform.toLowerCase();
const MAX_COMBO = 10;      // = card budget per order/36h (was 4); stock list is unlimited
const MAX_PREDICTION_CALLS = 12;

let forcedUTT = false;
let cardsRemaining = null;
let combo = [];            // {numberStock, price(str), marginal}
let comboPred = 0;
let calls = 0;

async function predict(sectionString) {
  calls++;
  const result = await this.helpers.httpRequest({
    method: 'POST',
    url: 'https://utautotransfer.com/api/getMaxOrderPrediction',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: `apiKey=${UTT_API_KEY}&platform=${uttPlatform}&orderMethod=public&stockSection=${encodeURIComponent(sectionString)}&startCoins=${startCoins}&email=${encodeURIComponent(src.eaEmail)}`,
  });
  return result;
}

for (const stock of validStocks) {
  if (calls >= MAX_PREDICTION_CALLS || combo.length >= MAX_COMBO) break;
  if (comboPred >= orderAmountCoins) break;
  const tentative = [...combo, { numberStock: stock.numberStock, price: stock.price }];
  const section = tentative.map(s => `${s.numberStock},${s.price}`).join(',');
  try {
    const r = await predict.call(this, section);
    const pred = r.maxTransferable || 0;
    if (cardsRemaining === null) cardsRemaining = r.orderCardsRemaining ?? 10;
    if ((r.orderCardsRemaining ?? 10) < 3) forcedUTT = true;
    const marginal = pred - comboPred;
    if (marginal > 50000) {           // stock adds real capacity → keep it
      combo = tentative;
      combo[combo.length - 1].marginal = marginal;
      comboPred = pred;
    }
  } catch (e) { continue; }
}

// blended price = marginal-gain-weighted average over what the order actually uses
let blendedPrice = null;
if (combo.length) {
  let remaining = orderAmountCoins, costSum = 0, used = 0;
  for (const c of combo) {
    const take = Math.min(c.marginal, remaining);
    costSum += take * parseFloat(c.price);
    used += take; remaining -= take;
    if (remaining <= 0) break;
  }
  blendedPrice = used > 0 ? Math.round((costSum / used) * 1000) / 1000 : parseFloat(combo[0].price);
}

const fullCoverage = comboPred >= orderAmountCoins && combo.length > 0;

// pad pure-failover backups (unused cheapest stocks) up to 8 entries total —
// list is unlimited and a pad only consumes a card if UTT actually switches to it
// (built whenever a combo exists — UTT can now also win partial-coverage orders)
let publicSaleString = null;
if (combo.length) {
  const usedStocks = new Set(combo.map(c => `${c.numberStock},${c.price}`));
  const pads = validStocks
    .map(s => `${s.numberStock},${s.price}`)
    .filter(k => !usedStocks.has(k));
  const parts = combo.map(c => `${c.numberStock},${c.price}`);
  while (parts.length < 8 && pads.length) parts.push(pads.shift());
  publicSaleString = parts.join(',');
}

// ── SUPPLIER DECISION ──
let supplier = '';
let fftMaxPossibleK = 0;
let fftTestPrice = null;

const askFFT = async (testPrice) => {
  const fftResult = await this.helpers.httpRequest({
    method: 'POST',
    url: 'https://futtransfer.top/maxOrderPreviewAPI',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ apiUser: CONFIG.FFT_API_USER, apiKey: CONFIG.FFT_API_KEY,
      platform, customerBalance: fftCustomerBalance, ownSenders: 0, maxPrice: testPrice, riskLevel: 10 }),
  });
  return typeof fftResult === 'string' ? JSON.parse(fftResult) : fftResult;
};

if (forcedUTT) {
  supplier = combo.length > 0 ? 'UTT' : 'FFT';
} else if (!fullCoverage) {
  // neither side guaranteed → send to whoever moves more coins RIGHT NOW
  const uttK = Math.round(comboPred / 1000);
  try {
    const parsed = await askFFT(maxPrice);
    fftMaxPossibleK = (parsed.success && parsed.maxPossibleK) || 0;
    if (fftMaxPossibleK >= amountK) supplier = 'FFT';                            // FFT covers fully after all
    else if (combo.length > 0 && uttK > fftMaxPossibleK * 1.2) supplier = 'UTT'; // UTT clearly ahead
    else supplier = 'FFT';                                                        // tie or FFT ahead → FFT tops up
  } catch (e) { supplier = 'FFT'; }
} else {
  fftTestPrice = Math.round((blendedPrice - 0.09) * 100) / 100;
  if (fftTestPrice <= 0) {
    supplier = 'UTT';
  } else {
    try {
      const parsed = await askFFT(fftTestPrice);
      fftMaxPossibleK = parsed.maxPossibleK || 0;
      supplier = (parsed.success && fftMaxPossibleK >= amountK * 1.10) ? 'FFT' : 'UTT';
    } catch (e) { supplier = 'UTT'; }
  }
}

return [{ json: {
  supplier,
  forcedUTT,
  isPartialFulfillment: !!(supplier === 'UTT' && comboPred < orderAmountCoins),
  uttPublicSaleString: publicSaleString,
  uttPrice: blendedPrice,
  uttComboStocks: combo.map(c => `${c.numberStock}@${c.price}(+${Math.round(c.marginal / 1000)}K)`).join(' '),
  uttMaxK: comboPred ? Math.round(comboPred / 1000) : null,
  uttCardsRemaining: cardsRemaining,
  fftTestPrice, fftMaxK: fftMaxPossibleK || null, fftCustomerBalance,
  amountK, platform, startCoins,
  calculatedMaxPrice: src.calculatedMaxPrice,
  ratioEuroUsd,
  stocksTested: calls,
  totalStocksAvailable: stocks.length,
  orderId: src.orderId,
}}];
