/* eslint-disable */
// ship-coins v1 — one shipment per run.
//
// v14 read one coins item per order and merged a challenge's coins with the
// coins bought beside it into ONE supplier order, on purpose: two shipments to
// one EA account at once are two bots on one account. This node keeps that
// rule and generalises it only as far as the store's data forces: the items
// are grouped by EA account and platform, the first group becomes the
// shipment, and any other group is left for the store's retry - the store
// re-reads the order before every send and omits what is already placed, so
// answering "not acknowledged" with items remaining brings them next time.
//
// The output field names are v14's own (amountK, calculatedMaxPrice,
// startCoins, eaBackup1..3, isSlowShipping ...), so the Supplier Decision
// Engine and the HTTP nodes copied from v14 read them unchanged.

const order = $('Verify Request').first().json.order;
const orderId = order.order_number;

function fail(message) {
    throw new Error(`[plan] order ${orderId}: ${message}`);
}

const groups = new Map();

for (const item of order.items) {
    const account = item.account || {};
    const email = String(account.ea_email || '').trim().toLowerCase();
    const platform = String(item.supplier_platform || '');

    if (!email || !account.ea_password) fail(`item ${item.order_item_public_id} carries no EA account`);
    if (platform !== 'PS' && platform !== 'PC') fail(`item ${item.order_item_public_id} is on ${item.platform || '?'}, which no supplier serves`);
    if (item.service !== 'coins' && item.service !== 'sbc') fail(`item ${item.order_item_public_id} is a ${item.service}, which no supplier delivers`);

    const key = `${email}|${platform}`;
    if (!groups.has(key)) groups.set(key, { email, platform, account, items: [] });
    groups.get(key).items.push(item);
}

const [shipment, ...rest] = [...groups.values()];
const codes = Array.isArray(shipment.account.backup_codes)
    ? shipment.account.backup_codes.filter((code) => typeof code === 'string' && code !== '')
    : [];

const coinsItems = [];
const sbcItems = [];
let calculatedMaxPrice = 0;

for (const item of shipment.items) {
    const budget = Number(item.budget && item.budget.max_eur_per_100k) || 0;
    if (budget <= 0) fail(`item ${item.order_item_public_id} carries no budget`);
    // v14 priced a merged shipment by its total tier; the store prices each
    // item, and the larger ceiling is the one the merged shipment may spend.
    calculatedMaxPrice = Math.max(calculatedMaxPrice, budget);

    if (item.service === 'coins') {
        const quantity = Number(item.coins && item.coins.quantity) || 0;
        if (quantity <= 0) fail(`item ${item.order_item_public_id} has no coins quantity`);
        coinsItems.push({
            orderItemPublicId: item.order_item_public_id,
            amountK: Math.round(quantity / 1000),
            delivery: (item.coins && item.coins.delivery) || null,
        });
    } else {
        const setID = Number(item.sbc && item.sbc.set_id) || 0;
        if (setID <= 0) fail(`item ${item.order_item_public_id} names no challenge`);
        sbcItems.push({
            orderItemPublicId: item.order_item_public_id,
            sbcSetID: setID,
            sbcTimesToSolve: Math.max(1, Number(item.sbc && item.sbc.times_to_solve) || 1),
        });
    }
}

const extraCoinsK = coinsItems.reduce((sum, entry) => sum + entry.amountK, 0);
const balance = Number(shipment.account.current_balance);

return [{
    json: {
        orderId,
        orderPublicId: order.order_public_id,
        customerName: String(order.customer_name || '').trim() || 'ArabUT',
        platform: shipment.platform,
        eaEmail: shipment.email,
        eaPass: String(shipment.account.ea_password),
        eaBackup1: codes[0] || '',
        eaBackup2: codes[1] || '',
        eaBackup3: codes[2] || '',
        // v14: an unknown balance was taken as 200,000.
        startCoins: Number.isFinite(balance) && balance > 0 ? Math.round(balance) : 200000,
        calculatedMaxPrice,
        coinsItems,
        sbcItems,
        extraCoinsK,
        // The coins-only amount; a challenge shipment's amount is settled once
        // FFT has priced the challenge (SBC: Match & Validate).
        amountK: extraCoinsK,
        needsChallengePricing: sbcItems.length > 0,
        // v14: slow shipping only ever applied to a plain coins order.
        isSlowShipping: sbcItems.length === 0 && coinsItems.every((entry) => entry.delivery === 'normal'),
        transferMethod: 'targetedSnipe',
        itemIds: shipment.items.map((item) => item.order_item_public_id),
        remainingGroups: rest.length,
    },
}];
