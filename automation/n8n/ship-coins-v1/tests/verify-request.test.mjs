import assert from 'node:assert/strict';
import { Buffer } from 'node:buffer';
import { test } from 'node:test';

import { env, NOW_SECONDS, orderPaid, pipeline, webhookRequest } from './helpers.mjs';

async function verify(request, environment = env()) {
    const flow = pipeline({ env: environment });

    return flow.run('Verify Request', 'verify-request', request);
}

test('a request signed by the store is accepted and its order handed on', async () => {
    const [{ json }] = await verify(webhookRequest(orderPaid()));

    assert.equal(json.eventId, '01K52J0V4M8R1YQ6H2Z7N9P3C5');
    assert.equal(json.order.order_number, 'AUT-7K2MQ4');
    assert.equal(json.order.customer_name, 'فهد العتيبي');
    assert.equal(json.order.items.length, 1);
    // The float the store wrote as 1.0 reads back as a number, not a string.
    assert.equal(json.order.items[0].budget.max_eur_per_100k, 1);
});

test('the signature is checked over the raw bytes, which a re-serialised body would not match', async () => {
    const event = orderPaid();
    const request = webhookRequest(event);
    const reserialised = JSON.stringify(request.json.body);
    const raw = Buffer.from(request.binary.data.data, 'base64').toString('utf8');

    // PHP escapes the Arabic name and keeps 1.0; JavaScript does neither.
    assert.notEqual(reserialised, raw);
    assert.match(raw, /\\u0641/);
    assert.match(raw, /"max_eur_per_100k":1\.0,/);

    await assert.doesNotReject(verify(request));
    await assert.rejects(
        verify(webhookRequest(event, { raw: reserialised, signature: request.json.headers['x-arabut-signature'] })),
        /X-ArabUT-Signature does not match/,
    );
});

test('a wrong key, a stale timestamp, a tampered body or a mismatched event id is refused', async () => {
    const event = orderPaid();
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
        verify(webhookRequest(event, { eventHeader: '01K52J0V4M8R1YQ6H2Z7N9P3C6', signature: good.json.headers['x-arabut-signature'] })),
        /X-ArabUT-Signature/,
    );
    // A signature that is valid for a different event id than the body carries.
    const other = { ...event, eventId: '01K52J0V4M8R1YQ6H2Z7N9P3C6' };
    await assert.rejects(
        verify(webhookRequest(other, { eventHeader: '01K52J0V4M8R1YQ6H2Z7N9P3C5' })),
        /does not match the body eventId/,
    );
});

test('only schema 2 of order.paid with items is accepted', async () => {
    await assert.rejects(verify(webhookRequest(orderPaid([], { items: [] }))), /no items/);
    await assert.rejects(verify(webhookRequest({ ...orderPaid(), schemaVersion: 1 })), /schemaVersion 1/);
    await assert.rejects(verify(webhookRequest({ ...orderPaid(), eventType: 'order.refunded' })), /eventType/);
});

test('a missing environment variable or a missing raw body fails at the first node, by name', async () => {
    await assert.rejects(
        verify(webhookRequest(orderPaid()), env({ UTT_API_KEY: '' })),
        /missing n8n environment variable\(s\): UTT_API_KEY/,
    );
    await assert.rejects(
        verify(webhookRequest(orderPaid()), env({ N8N_ORDER_PAID_SECRET: 'short' })),
        /shorter than 32/,
    );

    const request = webhookRequest(orderPaid());
    delete request.binary;
    await assert.rejects(verify(request), /Raw Body/);
});
