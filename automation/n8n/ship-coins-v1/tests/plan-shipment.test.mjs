import assert from 'node:assert/strict';
import { test } from 'node:test';

import { account, coinsItem, planned, sbcItem } from './helpers.mjs';

test('a plain coins order becomes one shipment in v14 field names', async () => {
    const flow = await planned([coinsItem()]);
    const shipment = flow.json('Plan Shipment');

    assert.deepEqual(
        {
            orderId: shipment.orderId,
            platform: shipment.platform,
            eaEmail: shipment.eaEmail,
            eaPass: shipment.eaPass,
            codes: [shipment.eaBackup1, shipment.eaBackup2, shipment.eaBackup3],
            customerName: shipment.customerName,
            startCoins: shipment.startCoins,
            amountK: shipment.amountK,
            calculatedMaxPrice: shipment.calculatedMaxPrice,
            isSlowShipping: shipment.isSlowShipping,
            needsChallengePricing: shipment.needsChallengePricing,
            transferMethod: shipment.transferMethod,
            itemIds: shipment.itemIds,
            remainingGroups: shipment.remainingGroups,
        },
        {
            orderId: 'AUT-7K2MQ4',
            platform: 'PS',
            // Lower-cased: it is the account key, and the suppliers key on it too.
            eaEmail: 'fahad@example.test',
            eaPass: 'safe password',
            codes: ['11111111', '22222222', '33333333'],
            customerName: 'فهد العتيبي',
            startCoins: 350000,
            amountK: 1250,
            calculatedMaxPrice: 1,
            isSlowShipping: false,
            needsChallengePricing: false,
            transferMethod: 'targetedSnipe',
            itemIds: ['01K52J0V3C1A8W4E7R2T9Y6U3I'],
            remainingGroups: 0,
        },
    );
});

test('slow console delivery is v14 slow shipping; an unknown balance is 200,000', async () => {
    const flow = await planned([
        coinsItem({ coins: { quantity: 400000, delivery: 'normal' }, account: account({ current_balance: null }) }),
    ]);
    const shipment = flow.json('Plan Shipment');

    assert.equal(shipment.isSlowShipping, true);
    assert.equal(shipment.amountK, 400);
    assert.equal(shipment.startCoins, 200000);
});

test('a challenge and the coins beside it on one account are one shipment, priced later', async () => {
    const flow = await planned([
        coinsItem({ budget: { max_eur_per_100k: 0.95 } }),
        sbcItem({ budget: { max_eur_per_100k: 1.1 } }),
    ]);
    const shipment = flow.json('Plan Shipment');

    assert.equal(shipment.needsChallengePricing, true);
    assert.equal(shipment.isSlowShipping, false);
    assert.equal(shipment.extraCoinsK, 1250);
    assert.deepEqual(shipment.sbcItems, [
        { orderItemPublicId: '01K52J0V3D5S9F2G6H8J1K4L7Z', sbcSetID: 412, sbcTimesToSolve: 2 },
    ]);
    // The larger of the two ceilings is what the merged shipment may spend.
    assert.equal(shipment.calculatedMaxPrice, 1.1);
    assert.deepEqual(shipment.itemIds, ['01K52J0V3C1A8W4E7R2T9Y6U3I', '01K52J0V3D5S9F2G6H8J1K4L7Z']);
    assert.equal(shipment.remainingGroups, 0);
});

test('items on two accounts are two shipments; this run takes the first and says one remains', async () => {
    const flow = await planned([
        coinsItem(),
        coinsItem({
            order_item_public_id: '01K52J0V3E9Q1W3E5R7T9Y2U4I',
            platform: 'pc',
            supplier_platform: 'PC',
            coins: { quantity: 2000000, delivery: null },
            account: account({ ea_email: 'pc@example.test' }),
        }),
    ]);
    const shipment = flow.json('Plan Shipment');

    assert.equal(shipment.platform, 'PS');
    assert.deepEqual(shipment.itemIds, ['01K52J0V3C1A8W4E7R2T9Y6U3I']);
    assert.equal(shipment.remainingGroups, 1);
});

test('an item no supplier can take stops the run with the order number', async () => {
    await assert.rejects(planned([coinsItem({ platform: 'xbox', supplier_platform: 'XBOX' })]), /order AUT-7K2MQ4: item .* is on xbox/);
    await assert.rejects(planned([coinsItem({ account: account({ ea_password: '' }) })]), /carries no EA account/);
    await assert.rejects(planned([coinsItem({ budget: { max_eur_per_100k: 0 } })]), /carries no budget/);
    await assert.rejects(planned([coinsItem({ coins: { quantity: 0, delivery: 'fast' } })]), /no coins quantity/);
    await assert.rejects(planned([sbcItem({ sbc: { set_id: 0, times_to_solve: 1 } })]), /names no challenge/);
    await assert.rejects(planned([coinsItem({ service: 'rivals' })]), /no supplier delivers/);
});
