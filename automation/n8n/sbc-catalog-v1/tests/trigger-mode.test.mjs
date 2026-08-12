import assert from 'node:assert/strict';
import { test } from 'node:test';

import { runNode } from './helpers.mjs';

async function configure(input, staticData = {}) {
    return (
        await runNode('config', {
            items: [input],
            staticData,
        })
    )[0].json;
}

test('manual executions are inspection-only dry runs', async () => {
    const result = await configure({
        triggerSource: 'manual',
        requestedMode: 'dry_run',
    });

    assert.equal(result.configValid, true);
    assert.equal(result.triggerSource, 'manual');
    assert.equal(result.settings.mode, 'dry_run');
});

test('the authenticated production webhook accepts only an exact mode body', async (t) => {
    await t.test('dry run', async () => {
        const result = await configure({ body: { mode: 'dry_run' } });

        assert.equal(result.configValid, true);
        assert.equal(result.triggerSource, 'webhook');
        assert.equal(result.settings.mode, 'dry_run');
    });

    await t.test('controlled bootstrap apply', async () => {
        const result = await configure({ body: { mode: 'apply' } });

        assert.equal(result.configValid, true);
        assert.equal(result.triggerSource, 'webhook');
        assert.equal(result.settings.mode, 'apply');
    });

    for (const [label, body] of [
        ['missing mode', {}],
        ['unknown mode', { mode: 'preview' }],
        ['extra key', { mode: 'apply', force: true }],
        ['non-object body', 'apply'],
    ]) {
        await t.test(`rejects ${label}`, async () => {
            const result = await configure({ body });

            assert.equal(result.configValid, false);
            assert.match(result.failureReason, /trigger|mode|body/i);
        });
    }
});

test('the schedule applies only after a durable fresh-201 bootstrap exists', async () => {
    const unbootstrapped = await configure({
        triggerSource: 'schedule',
        requestedMode: 'apply',
    });
    assert.equal(unbootstrapped.configValid, false);
    assert.match(unbootstrapped.failureReason, /bootstrap/i);

    const bootstrapped = await configure(
        { triggerSource: 'schedule', requestedMode: 'apply' },
        {
            sbcCatalogV1: {
                lastSuccessfulItems: [
                    {
                        sourceId: '1340',
                        sourceName: 'Ayden Heaven',
                        expiresAt: '2026-08-18T17:00:00.000Z',
                    },
                ],
            },
        },
    );

    assert.equal(bootstrapped.configValid, true);
    assert.equal(bootstrapped.triggerSource, 'schedule');
    assert.equal(bootstrapped.settings.mode, 'apply');
});
