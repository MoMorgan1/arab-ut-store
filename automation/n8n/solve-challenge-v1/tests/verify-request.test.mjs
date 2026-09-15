import assert from 'node:assert/strict';
import { Buffer } from 'node:buffer';
import { test } from 'node:test';

import { challengeItem, challengeReady, config, NOW_SECONDS, pipeline, webhookRequest } from './helpers.mjs';

async function verify(request, pasted = config()) {
    const flow = pipeline({ config: pasted });

    return flow.run('Verify Request', 'verify-request', request);
}

test('a request signed by the store is accepted and its item handed on', async () => {
    const [{ json }] = await verify(webhookRequest(challengeReady()));

    assert.equal(json.eventId, '01K5A2Q7M3R8X1YQ6H2Z7N9P3C5');
    assert.equal(json.request.order_number, 'AUT-7K2MQ4');
    assert.equal(json.request.customer_name, 'فهد العتيبي');
    assert.deepEqual(json.request.item.sbc, { set_id: 412, times_to_solve: 2 });
    assert.equal(json.request.item.account.ea_email, 'Fahad@example.test');
});

test('the signature is checked over the raw bytes, which a re-serialised body would not match', async () => {
    const event = challengeReady();
    const request = webhookRequest(event);
    const reserialised = JSON.stringify(request.json.body);
    const raw = Buffer.from(request.binary.data.data, 'base64').toString('utf8');

    // PHP escapes the Arabic name; JavaScript does not.
    assert.notEqual(reserialised, raw);
    assert.match(raw, /\\u0641/);

    await assert.doesNotReject(verify(request));
    await assert.rejects(
        verify(webhookRequest(event, { raw: reserialised, signature: request.json.headers['x-arabut-signature'] })),
        /X-ArabUT-Signature does not match/,
    );
});

test('a wrong key, a stale timestamp, a tampered body or a mismatched event id is refused', async () => {
    const event = challengeReady();
    const good = webhookRequest(event);

    await assert.rejects(verify(webhookRequest(event, { key: 'someone-else' })), /X-ArabUT-Key/);
    await assert.rejects(
        verify(webhookRequest(event, { timestamp: String(NOW_SECONDS - 301) })),
        /five-minute window/,
    );
    await assert.rejects(
        verify(webhookRequest(event, { secret: 'not-the-secret-not-the-secret-000001' })),
        /X-ArabUT-Signature/,
    );
    await assert.rejects(
        verify(webhookRequest(event, { eventHeader: '01K5A2Q7M3R8X1YQ6H2Z7N9P3C6', signature: good.json.headers['x-arabut-signature'] })),
        /X-ArabUT-Signature/,
    );
    const other = { ...event, eventId: '01K5A2Q7M3R8X1YQ6H2Z7N9P3C6' };
    await assert.rejects(
        verify(webhookRequest(other, { eventHeader: '01K5A2Q7M3R8X1YQ6H2Z7N9P3C5' })),
        /does not match the body eventId/,
    );
});

test('only schema 1 of challenge.ready with a funded challenge and its account is accepted', async () => {
    await assert.rejects(verify(webhookRequest({ ...challengeReady(), schemaVersion: 2 })), /schemaVersion 2/);
    await assert.rejects(verify(webhookRequest({ ...challengeReady(), eventType: 'order.paid' })), /eventType/);
    await assert.rejects(verify(webhookRequest(challengeReady(challengeItem({ service: 'coins', sbc: undefined })))), /not a challenge with a set id/);
    await assert.rejects(verify(webhookRequest(challengeReady(challengeItem({ sbc: { set_id: 0, times_to_solve: 1 } })))), /not a challenge with a set id/);
    await assert.rejects(verify(webhookRequest(challengeReady(challengeItem({ supplier_platform: 'XBOX' })))), /platform XBOX has no solver/);
    await assert.rejects(verify(webhookRequest(challengeReady(challengeItem({ account: { ea_email: 'x@example.test' } })))), /carries no EA account/);
    await assert.rejects(verify(webhookRequest(challengeReady(challengeItem(), { item: undefined }))), /names no order item/);
});

test('a key left unpasted in the Config node fails at the first node, by name', async () => {
    await assert.rejects(
        verify(webhookRequest(challengeReady()), config({ FFT_API_KEY: '' })),
        /not set in the Config node: FFT_API_KEY/,
    );
    await assert.rejects(
        verify(webhookRequest(challengeReady()), config({ N8N_SOLVE_CHALLENGE_KEY: 'CONFIGURE_N8N_SOLVE_CHALLENGE_KEY' })),
        /not set in the Config node: N8N_SOLVE_CHALLENGE_KEY/,
    );
    await assert.rejects(
        verify(webhookRequest(challengeReady()), config({ N8N_SOLVE_CHALLENGE_SECRET: 'short' })),
        /shorter than 32 characters/,
    );
    const noBody = webhookRequest(challengeReady());
    delete noBody.binary;
    await assert.rejects(verify(noBody), /raw request body is unavailable/);
});
