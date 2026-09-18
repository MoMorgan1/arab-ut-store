import assert from 'node:assert/strict';
import { Buffer } from 'node:buffer';
import { test } from 'node:test';

import { config, customerNotify, NOW_SECONDS, pipeline, webhookRequest } from './helpers.mjs';

async function verify(request, pasted = config()) {
    const flow = pipeline({ config: pasted });

    return flow.run('Verify Request', 'verify-request', request);
}

test('a request signed by the store is accepted and its message handed on', async () => {
    const [{ json }] = await verify(webhookRequest(customerNotify()));

    assert.equal(json.eventId, '01K5F8Q2M3R8X1YQ6H2Z7N9P3C5');
    assert.equal(json.idempotencyKey, 'customer-notify:item:881:credentials:12043');
    assert.equal(json.orderNumber, 'AUT-7K2MQ4');
    assert.equal(json.template, 'credentials');
    assert.equal(json.to, '966512345678');
    assert.match(json.body, /طلب #AUT-7K2MQ4/);
});

test('the signature is checked over the raw bytes, which a re-serialised body would not match', async () => {
    const event = customerNotify();
    const request = webhookRequest(event);
    const reserialised = JSON.stringify(request.json.body);
    const raw = Buffer.from(request.binary.data.data, 'base64').toString('utf8');

    // PHP escapes the Arabic that every one of these bodies is made of.
    assert.notEqual(reserialised, raw);
    assert.match(raw, /\\u0623/);

    await assert.doesNotReject(verify(request));
    await assert.rejects(
        verify(webhookRequest(event, { raw: reserialised, signature: request.json.headers['x-arabut-signature'] })),
        /X-ArabUT-Signature does not match/,
    );
});

test('a wrong key, a stale timestamp, a tampered body or a mismatched event id is refused', async () => {
    const event = customerNotify();

    await assert.rejects(verify(webhookRequest(event, { key: 'someone-else' })), /X-ArabUT-Key does not match/);
    await assert.rejects(
        verify(webhookRequest(event, { timestamp: String(NOW_SECONDS - 400) })),
        /outside the five-minute window/,
    );
    await assert.rejects(
        verify(webhookRequest(event, { signature: 'a'.repeat(64) })),
        /X-ArabUT-Signature does not match/,
    );
    await assert.rejects(
        verify(webhookRequest(event, { eventHeader: '01K5F8Q2M3R8X1YQ6H2Z7N9P3XX' })),
        /does not match the body eventId/,
    );
});

test('only customer.notify schema 1 is read', async () => {
    await assert.rejects(
        verify(webhookRequest({ ...customerNotify(), eventType: 'order.paid' })),
        /unexpected eventType order\.paid/,
    );
    await assert.rejects(
        verify(webhookRequest({ ...customerNotify(), schemaVersion: 2 })),
        /unexpected schemaVersion 2/,
    );
});

test('a request with nothing to send fails at our door rather than as a Whapi 4xx', async () => {
    await assert.rejects(
        verify(webhookRequest(customerNotify({ to: '+966512345678' }))),
        /international digits without the \+/,
    );
    await assert.rejects(verify(webhookRequest(customerNotify({ to: '12345' }))), /international digits/);
    await assert.rejects(verify(webhookRequest(customerNotify({ body: '   ' }))), /data\.body is empty/);
    await assert.rejects(
        verify(webhookRequest(customerNotify({ idempotency_key: '' }))),
        /idempotency_key is missing/,
    );
});

test('a Config node nobody filled in refuses to run', async () => {
    await assert.rejects(
        verify(webhookRequest(customerNotify()), config({ WHAPI_TOKEN: 'CONFIGURE_WHAPI_TOKEN' })),
        /not set in the Config node: WHAPI_TOKEN/,
    );
    await assert.rejects(
        verify(webhookRequest(customerNotify()), config({ N8N_CUSTOMER_NOTIFY_SECRET: 'too-short' })),
        /shorter than 32 characters/,
    );
});
