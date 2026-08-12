import assert from 'node:assert/strict';
import { createHmac } from 'node:crypto';
import { test } from 'node:test';

import { config, pricingRead, runNode } from './helpers.mjs';

test('pricing read requires the route-separated HMAC over an empty GET body', async () => {
    const result = (
        await runNode('sign-pricing-read', {
            items: [config()],
            env: { N8N_SBC_PRICING_READ_SECRET: 'pricing-secret' },
        })
    )[0].json;
    const canonical =
        '1786536000\nGET\n/api/automation/v1/pricing/coins/sbc-bases\n';

    assert.equal(result.pricingReadSigned, true);
    assert.equal(result.timestamp, '1786536000');
    assert.equal(
        result.signature,
        createHmac('sha256', 'pricing-secret').update(canonical).digest('hex'),
    );
});

test('pricing read accepts only the exact authoritative two-quote response', async (t) => {
    const valid = (
        await runNode('evaluate-pricing-read', { items: [pricingRead()] })
    )[0].json;
    assert.equal(valid.valid, true, valid.failureReason);
    assert.deepEqual(valid.pricing, pricingRead());

    const cases = [
        [
            'missing quote',
            {
                ...pricingRead(),
                quotes: {
                    playstation_fast: pricingRead().quotes.playstation_fast,
                },
            },
        ],
        [
            'wrong quantity',
            {
                ...pricingRead(),
                quotes: {
                    ...pricingRead().quotes,
                    pc: { ...pricingRead().quotes.pc, quantity: 500_000 },
                },
            },
        ],
        [
            'extra quote',
            {
                ...pricingRead(),
                quotes: {
                    ...pricingRead().quotes,
                    console_normal: pricingRead().quotes.playstation_fast,
                },
            },
        ],
        [
            'non-success response',
            { statusCode: 503, body: { error: { code: 'unavailable' } } },
        ],
    ];

    for (const [name, response] of cases) {
        await t.test(name, async () => {
            const result = (
                await runNode('evaluate-pricing-read', { items: [response] })
            )[0].json;
            assert.equal(result.valid, false);
            assert.ok(result.failureReason);
        });
    }
});

test('catalog signing serializes once and signs the exact raw body', async () => {
    const snapshot = {
        schemaVersion: 1,
        eventId: '01K2EXAMPLE000000000000001',
        runId: '01K2EXAMPLE000000000000002',
        generatedAt: '2026-08-12T12:00:00.000000Z',
        completeSnapshot: true,
        categories: [],
        products: [],
    };
    const result = (
        await runNode('sign-catalog', {
            items: [{ valid: true, catalogSnapshot: snapshot }],
            env: { N8N_SBC_CATALOG_SECRET: 'catalog-secret' },
        })
    )[0].json;
    const rawBody = JSON.stringify(snapshot);
    const canonical = `1786536000\n${snapshot.eventId}\nn8n-sbc\n${rawBody}`;

    assert.equal(result.catalogSigned, true);
    assert.equal(result.rawBody, rawBody);
    assert.equal(
        result.signature,
        createHmac('sha256', 'catalog-secret').update(canonical).digest('hex'),
    );
});

test('publish evaluation accepts only exact 201 completion or same-request replay', async (t) => {
    const submitted = {
        eventId: '01K2EXAMPLE000000000000001',
        runId: '01K2EXAMPLE000000000000002',
        products: [{ variants: [{}, {}] }],
    };
    const cases = [
        [
            '201 completed',
            {
                statusCode: 201,
                body: {
                    data: {
                        runId: submitted.runId,
                        status: 'completed',
                        applied: 1,
                        archived: 0,
                    },
                },
            },
            true,
            false,
        ],
        [
            '409 replay',
            {
                statusCode: 409,
                body: { error: { code: 'catalog_snapshot_replayed' } },
            },
            true,
            true,
        ],
        [
            '422 rejected',
            { statusCode: 422, body: { message: 'invalid' } },
            false,
            false,
        ],
        [
            '500 failure',
            { statusCode: 500, body: { error: { code: 'server_error' } } },
            false,
            false,
        ],
    ];

    for (const [name, response, publishOk, replayed] of cases) {
        await t.test(name, async () => {
            const result = (
                await runNode('evaluate-publish', {
                    named: {
                        'Sign Catalog Snapshot': { catalogSnapshot: submitted },
                    },
                    items: [response],
                })
            )[0].json;
            assert.equal(result.publishOk, publishOk);
            assert.equal(result.replayed, replayed);
        });
    }
});

test('dry run summary explicitly records that no publish was attempted', async () => {
    const result = (
        await runNode('dry-run-summary', {
            items: [
                {
                    sourceCount: 56,
                    eligibleCount: 39,
                    sourceSafetyFloor: 56,
                    eligibleSafetyFloor: 39,
                    catalogSnapshot: {
                        categories: [{}, {}],
                        products: [{ variants: [{}, {}] }],
                    },
                },
            ],
        })
    )[0].json;

    assert.deepEqual(result, {
        status: 'dry_run',
        publishAttempted: false,
        categories: 2,
        products: 1,
        variants: 2,
        sourceCount: 56,
        eligibleCount: 39,
        sourceSafetyFloor: 56,
        eligibleSafetyFloor: 39,
        wouldCreate: null,
        wouldUpdate: null,
        wouldArchive: null,
        previewAvailable: false,
        previewReason:
            'Laravel has no authenticated n8n-sbc snapshot read endpoint',
    });
});

test('only an exact fresh 201 completion advances durable safety counts and counts never decrease', async () => {
    const staticData = {};
    const snapshot = {
        runId: '01K2EXAMPLE000000000000002',
        products: [{ variants: [{}, {}] }],
    };

    await runNode('success-summary', {
        items: [
            {
                replayed: false,
                sourceCount: 56,
                eligibleCount: 39,
                catalogSnapshot: snapshot,
                publishResponse: {
                    data: { runId: snapshot.runId, status: 'completed' },
                },
            },
        ],
        staticData,
    });
    assert.deepEqual(staticData.sbcCatalogV1.lastSuccessfulCounts, {
        sourceCount: 56,
        eligibleCount: 39,
        completedAt: '2026-08-12T12:00:00.000Z',
    });

    await runNode('success-summary', {
        items: [
            {
                replayed: false,
                sourceCount: 50,
                eligibleCount: 30,
                catalogSnapshot: snapshot,
                publishResponse: {
                    data: { runId: snapshot.runId, status: 'completed' },
                },
            },
        ],
        staticData,
    });
    assert.equal(staticData.sbcCatalogV1.lastSuccessfulCounts.sourceCount, 56);
    assert.equal(
        staticData.sbcCatalogV1.lastSuccessfulCounts.eligibleCount,
        39,
    );

    await runNode('success-summary', {
        items: [
            {
                replayed: true,
                sourceCount: 80,
                eligibleCount: 60,
                catalogSnapshot: snapshot,
                publishResponse: {
                    error: { code: 'catalog_snapshot_replayed' },
                },
            },
        ],
        staticData,
    });
    assert.equal(staticData.sbcCatalogV1.lastSuccessfulCounts.sourceCount, 56);
    assert.equal(
        staticData.sbcCatalogV1.lastSuccessfulCounts.eligibleCount,
        39,
    );
});
