import assert from 'node:assert/strict';
import { test } from 'node:test';

import { challengeItem, fftSbcs, validated, verified } from './helpers.mjs';

test('a listed, priced set becomes the solve: v14 split item, one set, the store\'s times to solve', async () => {
    const flow = await validated();
    const solve = flow.json('Validate Set');

    assert.deepEqual(solve, {
        orderId: 'AUT-7K2MQ4',
        orderItemPublicId: '01K52J0V3D5S9F2G6H8J1K4L7Z',
        setID: 412,
        sbcName: 'Team of the Week Upgrade',
        challengeAmount: 2,
        expiry: '2035-07-30 19:00:00',
        coinsPerSolveK: 145,
        timesToSolve: 2,
        platform: 'PS',
        eaEmail: 'Fahad@example.test',
        customerName: 'فهد العتيبي',
        funding: { supplier: 'utt', supplier_order_id: '574339' },
        totalSBCsFound: 2,
    });
});

test('the list may also arrive as one item wrapping the array, and PC reads the PC price', async () => {
    const wrapped = await verified();
    await wrapped.run('Validate Set', 'validate-set', [{ json: { data: fftSbcs() } }]);
    assert.equal(wrapped.json('Validate Set').setID, 412);

    const pc = await verified(challengeItem({ platform: 'pc', supplier_platform: 'PC' }));
    await pc.run('Validate Set', 'validate-set', fftSbcs());
    assert.equal(pc.json('Validate Set').coinsPerSolveK, 132);
    assert.equal(pc.json('Validate Set').platform, 'PC');
});

test('a set FFT does not price, or does not list, stops the run with the v14 message and the order number', async () => {
    const unpriced = await verified(challengeItem({ sbc: { set_id: 413, times_to_solve: 1 } }));
    await assert.rejects(
        unpriced.run('Validate Set', 'validate-set', fftSbcs()),
        /order AUT-7K2MQ4: FFT ما مسعّر هذا التحدي حالياً .*"Unpriced" \[منصة PS\]\. الطلب يحتاج حل يدوي/,
    );

    const unlisted = await verified(challengeItem({ sbc: { set_id: 999, times_to_solve: 1 } }));
    await assert.rejects(
        unlisted.run('Validate Set', 'validate-set', fftSbcs()),
        /order AUT-7K2MQ4: التحدي غير موجود: setID 999/,
    );

    const empty = await verified();
    await assert.rejects(empty.run('Validate Set', 'validate-set', [{ json: { data: [] } }]), /فشل قراءة قائمة التحديات/);
});
