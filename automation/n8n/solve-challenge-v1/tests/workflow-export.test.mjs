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
    assert.doesNotMatch(text, /salla|supabase|google ?sheets|whapi|utautotransfer/i);

    for (const node of exported.nodes) {
        for (const parameter of node.parameters?.bodyParameters?.parameters ?? []) {
            if (['apiKey', 'apiUser', 'user', 'pass', 'password', 'email'].includes(parameter.name)) {
                assert.match(String(parameter.value), /^=\{\{ \$\('Config'\)/, `${node.name}.${parameter.name} must come from Config, never a literal`);
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
    assert.deepEqual(Object.keys(CONFIG_DEFAULTS), ['ARABUT_STORE_URL']);
    assert.equal(config.parameters.includeOtherFields, true, 'the webhook headers must reach Verify Request');
    assert.equal(config.parameters.options.includeBinary, true, 'the raw body must reach Verify Request');
    assert.deepEqual(exported.connections.Webhook.main, [[{ node: 'Config', type: 'main', index: 0 }]]);
    assert.deepEqual(exported.connections.Config.main, [[{ node: 'Verify Request', type: 'main', index: 0 }]]);
    // No UTT key: only FFT solves.
    assert.ok(!CONFIG_KEYS.some(([name]) => name === 'UTT_API_KEY'));

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
    assert.equal(webhook.parameters.path, 'arabut-solve-challenge-v1');
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

test('the chain is a straight line from the webhook to the answer, and every node is on it', async () => {
    const exported = await workflow();
    const names = new Set(exported.nodes.map(({ name }) => name));
    const reached = new Set(['Webhook']);

    for (const [from, outputs] of Object.entries(exported.connections)) {
        assert.ok(names.has(from), `connection from unknown node ${from}`);
        assert.equal(outputs.main.length, 1, `${from} branches; the solve has no branches`);

        for (const { node } of outputs.main[0]) {
            assert.ok(names.has(node), `${from} connects to unknown node ${node}`);
            reached.add(node);
        }
    }

    assert.deepEqual([...names].filter((name) => !reached.has(name)), []);
});

test('the v14 solve calls are the v14 calls, reading the Validate Set node', async () => {
    const exported = await workflow();
    const byName = Object.fromEntries(exported.nodes.map((node) => [node.name, node]));

    const list = byName['SBC: Get Available SBCs'];
    assert.equal(list.parameters.url, 'https://futtransfer.top/availableSBCsAPI');
    assert.equal(list.retryOnFail, true);

    const submit = byName['SBC: Submit Solve'];
    assert.equal(submit.parameters.url, 'https://futtransfer.top/newSBCAPI');
    assert.equal(submit.parameters.specifyBody, 'json');
    const body = submit.parameters.jsonBody;

    for (const fragment of [
        '"apiUser": $(\'Config\').first().json.FFT_API_USER',
        '"setID": $(\'Validate Set\').first().json.setID',
        '"account": [$(\'Validate Set\').first().json.eaEmail]',
        '"accountType": "customer"',
        '"timesToSolve": $(\'Validate Set\').first().json.timesToSolve || 1',
        '"fillSBC": 0',
        '"customName": "Order #" + $(\'Validate Set\').first().json.orderId + " - " + $(\'Validate Set\').first().json.sbcName',
        '"clubItemHandling": "exclude"',
        '"consoleLock": 1',
    ]) {
        assert.ok(body.includes(fragment), `Submit Solve body lacks ${fragment}`);
    }

    for (const name of ['SBC: Get Available SBCs', 'SBC: Submit Solve']) {
        assert.ok(byName[name].parameters.options.timeout >= 20000, `${name} has no timeout`);
        assert.equal(byName[name].onError, undefined, `${name} must fail loudly`);
    }
});

test('the placement report goes to the store signed, raw, in the challenge phase, and never as an unhandled error', async () => {
    const exported = await workflow();
    const report = exported.nodes.find(({ name }) => name === 'Report Placement');
    const headers = Object.fromEntries(report.parameters.headerParameters.parameters.map(({ name, value }) => [name, value]));

    assert.match(report.parameters.url, /\/api\/automation\/v1\/fulfillment\/placements'/);
    assert.match(report.parameters.url, /startsWith\('https:\/\/'\)/);
    assert.doesNotMatch(report.parameters.url, /ARABUT_STORE_URL \|\| 'https/);
    assert.equal(report.parameters.contentType, 'raw');
    assert.equal(report.parameters.body, '={{ $json.rawBody }}');
    assert.equal(headers['X-ArabUT-Key'], "={{ $('Config').first().json.N8N_FULFILLMENT_KEY }}");
    assert.equal(headers['X-ArabUT-Signature'], '={{ $json.signature }}');
    assert.equal(report.parameters.options.response.response.neverError, true);
    assert.equal(report.parameters.options.response.response.fullResponse, true);
    assert.equal(report.retryOnFail, true);

    const sign = await readFile(new URL('nodes/sign-report.js', root), 'utf8');
    assert.match(sign, /challenge_ids: json\.challenge_ids/);
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
