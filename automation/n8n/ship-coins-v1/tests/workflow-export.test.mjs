import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { test } from 'node:test';

import { CONFIG_DEFAULTS, CONFIG_KEYS } from '../scripts/build-workflow.mjs';

const root = new URL('../', import.meta.url);

async function workflow(file = 'workflow.json') {
    return JSON.parse(await readFile(new URL(file, root), 'utf8'));
}

test('the export is inactive, secret-free, and carries no real credential ids', async () => {
    const exported = await workflow();
    const text = JSON.stringify(exported);

    assert.equal(exported.active, false);
    assert.doesNotMatch(text, /salla|supabase|google ?sheets|whapi/i);

    // Every supplier credential comes from the Config node. Asserted
    // structurally: any 32-char hex literal in the export is treated as a leak.
    for (const node of exported.nodes) {
        for (const parameter of node.parameters?.bodyParameters?.parameters ?? []) {
            if (['apiKey', 'apiUser', 'user', 'pass', 'password', 'email'].includes(parameter.name)) {
                assert.match(String(parameter.value), /^=\{\{ \$\('(Config|Shipment)'\)/, `${node.name}.${parameter.name} must come from Config or the shipment, never a literal`);
            }
        }

        for (const credential of Object.values(node.credentials ?? {})) {
            assert.match(credential.id, /^CONFIGURE_[A-Z0-9_]+$/, `${node.name} must use a placeholder credential id`);
        }
    }

    assert.doesNotMatch(text, /\b[0-9a-f]{32}\b/, 'the export contains something shaped like an API key');
    assert.doesNotMatch(text, /\$env[.[]|\$vars[.[]/, 'the instance has neither environment variables nor $vars');
});

test('the Config node holds every key as a placeholder and passes the webhook item through', async () => {
    const exported = await workflow();
    const config = exported.nodes.find(({ name }) => name === 'Config');

    assert.equal(config.type, 'n8n-nodes-base.set');
    assert.deepEqual(
        config.parameters.assignments.assignments.map(({ name, value, type }) => [name, value, type]),
        CONFIG_KEYS.map(([name]) => [name, CONFIG_DEFAULTS[name] ?? `CONFIGURE_${name}`, 'string']),
    );
    // Only the store origin ships filled in; a secret never does.
    assert.deepEqual(Object.keys(CONFIG_DEFAULTS), ['ARABUT_STORE_URL']);
    assert.equal(CONFIG_DEFAULTS.ARABUT_STORE_URL, 'https://store.arab-ut.com');
    assert.equal(config.parameters.includeOtherFields, true, 'the webhook headers must reach Verify Request');
    assert.equal(config.parameters.options.includeBinary, true, 'the raw body must reach Verify Request');
    assert.deepEqual(exported.connections.Webhook.main, [[{ node: 'Config', type: 'main', index: 0 }]]);
    assert.deepEqual(exported.connections.Config.main, [[{ node: 'Verify Request', type: 'main', index: 0 }]]);

    // Every Code node that needs a key reads the Config node, never $env.
    for (const node of exported.nodes.filter(({ type }) => type === 'n8n-nodes-base.code')) {
        assert.doesNotMatch(node.parameters.jsCode, /\$env[.[]/, `${node.name} reads $env`);
    }
});

test('no execution data is kept: the EA account travels in the request', async () => {
    const { settings } = await workflow();

    assert.equal(settings.saveDataErrorExecution, 'none');
    assert.equal(settings.saveDataSuccessExecution, 'none');
    assert.equal(settings.saveManualExecutions, false);
    assert.equal(settings.saveExecutionProgress, false);
    assert.equal(settings.errorWorkflow, undefined, 'the error workflow id is instance-specific and set by hand');
    assert.ok(settings.executionTimeout > 60, 'the store waits 60 seconds; the run may not be cut before that');
});

test('the webhook keeps the raw body and answers from the Respond node', async () => {
    const exported = await workflow();
    const webhook = exported.nodes.find(({ name }) => name === 'Webhook');
    const respond = exported.nodes.find(({ name }) => name === 'Respond');

    assert.equal(webhook.parameters.httpMethod, 'POST');
    assert.equal(webhook.parameters.options.rawBody, true);
    assert.equal(webhook.parameters.responseMode, 'responseNode');
    assert.equal(webhook.parameters.authentication, undefined, 'authentication is the HMAC in Verify Request');
    assert.equal(webhook.credentials, undefined);
    assert.equal(respond.type, 'n8n-nodes-base.respondToWebhook');
    assert.match(respond.parameters.responseBody, /acknowledged/);
});

test('every Code node is built from its source file', async () => {
    const exported = await workflow();

    for (const node of exported.nodes.filter(({ type }) => type === 'n8n-nodes-base.code')) {
        const source = node.notes.match(/^Source: (nodes\/[a-z-]+\.js)$/)?.[1];

        assert.ok(source, `${node.name} names no source file`);
        assert.equal(node.parameters.jsCode, await readFile(new URL(source, root), 'utf8'));
    }
});

test('every connection names a node that exists, and every node but the webhook is reachable', async () => {
    const exported = await workflow();
    const names = new Set(exported.nodes.map(({ name }) => name));
    const reached = new Set(['Webhook']);

    for (const [from, outputs] of Object.entries(exported.connections)) {
        assert.ok(names.has(from), `connection from unknown node ${from}`);

        for (const branch of outputs.main) {
            for (const { node } of branch) {
                assert.ok(names.has(node), `${from} connects to unknown node ${node}`);
                reached.add(node);
            }
        }
    }

    assert.deepEqual([...names].filter((name) => !reached.has(name)), []);
});

test('the v14 placement calls are the v14 calls, reading the Shipment node', async () => {
    const exported = await workflow();
    const byName = Object.fromEntries(exported.nodes.map((node) => [node.name, node]));
    const parameters = (name) => Object.fromEntries(byName[name].parameters.bodyParameters.parameters.map(({ name: key, value }) => [key, value]));

    const fft = parameters('External: Buy Coins');
    assert.equal(byName['External: Buy Coins'].parameters.url, 'https://futtransfer.top/buyCoinsAPI');
    assert.equal(fft.riskLevel, '10');
    assert.equal(fft.autoFinishCycle, '1');
    assert.equal(fft.updateCustomer, '1');
    assert.equal(fft.buyNowThreshold, "={{ $('Shipment').first().json.calculatedMaxPrice }}");
    assert.equal(fft.externalOrderID, "={{ $('Shipment').first().json.orderId }}");
    assert.match(fft.transferMethod, /UTT: Check Slow Cooldown/);
    assert.match(fft.topUpEnabled, /=== 'PC' \? 50/);

    const utt = parameters('UTT: Add Order Public');
    assert.equal(byName['UTT: Add Order Public'].parameters.url, 'https://utautotransfer.com/api/addOrderPublic');
    assert.equal(byName['UTT: Add Order Public'].parameters.contentType, 'form-urlencoded');
    assert.equal(utt.publicSale, "={{ $('Supplier Decision Engine').first().json.uttPublicSaleString }}");
    assert.equal(utt.checkDetails, '0');
    assert.equal(utt.accountLock, '0');

    for (const name of ['SBC: Get Available SBCs', 'UTT: Check Slow Cooldown', 'UTT: Get Stocks', 'FFT: Check Cooldown', 'UTT: Add Order Public', 'External: Buy Coins']) {
        assert.ok(byName[name].parameters.options.timeout >= 20000, `${name} has no timeout`);
        assert.equal(byName[name].onError, undefined, `${name} must fail loudly`);
    }

    // The three ways into the FFT placement are v14's: slow, FFT chosen, UTT cooldown fallback.
    const intoFft = Object.entries(exported.connections)
        .filter(([, outputs]) => outputs.main.some((branch) => branch.some(({ node }) => node === 'External: Buy Coins')))
        .map(([from]) => from)
        .sort();
    assert.deepEqual(intoFft, ['Cooldown OK?', 'Supplier Switch', 'UTT: Check Slow Cooldown']);
});

test('the placement report goes to the store signed, raw, and never as an unhandled error', async () => {
    const exported = await workflow();
    const report = exported.nodes.find(({ name }) => name === 'Report Placement');
    const headers = Object.fromEntries(report.parameters.headerParameters.parameters.map(({ name, value }) => [name, value]));

    assert.match(report.parameters.url, /\/api\/automation\/v1\/fulfillment\/placements'/);
    // A placeholder left in the Config node must fall back to the real store,
    // never be used as an origin (2026-09-15: a placed shipment went unreported).
    assert.match(report.parameters.url, /startsWith\('https:\/\/'\)/);
    assert.doesNotMatch(report.parameters.url, /ARABUT_STORE_URL \|\| 'https/);
    assert.equal(report.parameters.contentType, 'raw');
    assert.equal(report.parameters.body, '={{ $json.rawBody }}');
    assert.equal(headers['X-ArabUT-Key'], "={{ $('Config').first().json.N8N_FULFILLMENT_KEY }}");
    assert.equal(headers['X-ArabUT-Signature'], '={{ $json.signature }}');
    assert.equal(report.parameters.options.response.response.neverError, true);
    assert.equal(report.parameters.options.response.response.fullResponse, true);
    assert.equal(report.retryOnFail, true);
});

test('the error workflow alerts on Telegram through a placeholder credential', async () => {
    const exported = await workflow('error-workflow.json');
    const telegram = exported.nodes.find(({ type }) => type === 'n8n-nodes-base.telegram');
    const code = exported.nodes.find(({ type }) => type === 'n8n-nodes-base.code');

    assert.equal(exported.active, false);
    assert.equal(telegram.credentials.telegramApi.id, 'CONFIGURE_TELEGRAM_CREDENTIAL_ID');
    assert.equal(telegram.parameters.chatId, 'CONFIGURE_OPS_TELEGRAM_CHAT_ID', 'the chat id is typed into the node after import');
    assert.doesNotMatch(JSON.stringify(exported), /\$env[.[]/);
    assert.equal(code.parameters.jsCode, await readFile(new URL('nodes/failure-alert.js', root), 'utf8'));
    assert.doesNotMatch(JSON.stringify(exported), /whapi/i);
});
