import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { test } from 'node:test';

const root = new URL('../', import.meta.url);

async function workflow(file = 'workflow.json') {
    return JSON.parse(await readFile(new URL(file, root), 'utf8'));
}

test('export is inactive, secret-free, and carries no real credential ids', async () => {
    const exported = await workflow();
    const text = JSON.stringify(exported);

    assert.equal(exported.active, false);
    assert.doesNotMatch(text, /salla|woocommerce|wordpress/i);

    // The v3 export shipped the FuTTransfer key inline in the request body, so
    // it landed in every commit and backup. It now comes from the environment.
    //
    // Asserted structurally, on purpose. Naming the old key literally here
    // would republish the very secret this guards against -- and this repo is
    // public. Any 32-char hex literal in the export is treated as a leak.
    for (const node of exported.nodes) {
        for (const parameter of node.parameters?.bodyParameters?.parameters ??
            []) {
            assert.match(
                String(parameter.value),
                /^=\{\{/,
                `${node.name}.${parameter.name} must come from an expression, never a literal`,
            );
        }
    }

    assert.doesNotMatch(
        text,
        /\b[0-9a-f]{32}\b/,
        'the export contains something shaped like an API key',
    );
    assert.doesNotMatch(
        text,
        /N8N_SBC_(?:CATALOG|PRICING_READ)_SECRET\s*[:=]\s*['"][^$]/i,
    );
    assert.doesNotMatch(text, /apiKey["']?\s*,\s*["']value["']:\s*["'][^={]/);

    // Real credential ids are instance-specific and must never be committed.
    for (const node of exported.nodes) {
        for (const credential of Object.values(node.credentials ?? {})) {
            assert.match(
                credential.id,
                /^CONFIGURE_[A-Z0-9_]+$/,
                `${node.name} must use a placeholder credential id`,
            );
        }
    }
});

test('the FFT fetch reads its credentials from the environment', async () => {
    const exported = await workflow();
    const fetchFft = exported.nodes.find(
        ({ name }) => name === 'Fetch FFT SBCs',
    );

    assert.equal(fetchFft.parameters.method, 'POST');
    assert.deepEqual(
        fetchFft.parameters.bodyParameters.parameters.map(
            ({ name, value }) => [name, value],
        ),
        [
            ['apiUser', '={{ $env.FFT_API_USER }}'],
            ['apiKey', '={{ $env.FFT_API_KEY }}'],
        ],
    );
    assert.equal(fetchFft.credentials, undefined);
});

test('triggers wire straight into Config with no per-trigger Set nodes', async () => {
    const exported = await workflow();
    const nodes = exported.nodes;

    const schedule = nodes.find(({ name }) => name === 'Every 2 Hours');
    assert.equal(schedule.parameters.rule.interval[0].field, 'hours');
    assert.equal(schedule.parameters.rule.interval[0].hoursInterval, 2);

    const webhook = nodes.find(
        ({ name }) => name === 'SBC Catalog Production Trigger',
    );
    assert.equal(webhook.parameters.httpMethod, 'POST');
    assert.equal(webhook.parameters.path, 'arabut-sbc-catalog-v1/run');
    assert.equal(webhook.parameters.authentication, 'headerAuth');

    for (const trigger of [
        'Run SBC Catalog Now',
        'Every 2 Hours',
        'SBC Catalog Production Trigger',
    ]) {
        assert.deepEqual(
            exported.connections[trigger].main[0],
            [{ node: 'Config', type: 'main', index: 0 }],
            `${trigger} must feed Config directly`,
        );
    }

    assert.equal(
        nodes.some(({ type }) => type === 'n8n-nodes-base.set'),
        false,
        'Config infers triggerSource, so the Set nodes are gone',
    );
});

test('the graph is one line with a single routing decision', async () => {
    const exported = await workflow();
    const connections = exported.connections;

    // v3 had ten IF gates, all but one existing only to reach the failure rail.
    const ifNodes = exported.nodes.filter(
        ({ type }) => type === 'n8n-nodes-base.if',
    );
    assert.deepEqual(
        ifNodes.map(({ name }) => name),
        ['Needs Translation?'],
    );

    // No in-flow failure rail: failures throw and the Error Workflow reports.
    assert.equal(
        exported.nodes.some(
            ({ type }) => type === 'n8n-nodes-base.stopAndError',
        ),
        false,
    );

    assert.deepEqual(connections['Needs Translation?'].main[0], [
        { node: 'Translate SBC Names', type: 'main', index: 0 },
    ]);
    assert.deepEqual(connections['Needs Translation?'].main[1], [
        { node: 'Build & Price Snapshot', type: 'main', index: 0 },
    ]);
    assert.deepEqual(connections['Validate Translations'].main[0], [
        { node: 'Build & Price Snapshot', type: 'main', index: 0 },
    ]);

    // Both branches converge, so every node after the merge runs either way.
    for (const [from, to] of [
        ['Config', 'Sign Pricing Read'],
        ['Sign Pricing Read', 'Read Coins Bases'],
        ['Read Coins Bases', 'Evaluate Pricing Read'],
        ['Evaluate Pricing Read', 'Fetch FFT SBCs'],
        ['Fetch FFT SBCs', 'Fetch EasySBC Sets'],
        ['Fetch EasySBC Sets', 'Merge Provider Sources'],
        ['Merge Provider Sources', 'Plan Translations'],
        ['Plan Translations', 'Needs Translation?'],
        ['Build & Price Snapshot', 'Validate Snapshot'],
        ['Validate Snapshot', 'Sign Catalog Snapshot'],
        ['Sign Catalog Snapshot', 'Publish SBC Catalog'],
        ['Publish SBC Catalog', 'Finish Run'],
    ]) {
        assert.deepEqual(
            connections[from].main[0],
            [{ node: to, type: 'main', index: 0 }],
            `${from} must feed ${to}`,
        );
    }
});

test('every node reachable from a trigger, and every fetch inspectable', async () => {
    const exported = await workflow();
    const reachable = new Set([
        'Run SBC Catalog Now',
        'Every 2 Hours',
        'SBC Catalog Production Trigger',
        'OpenAI Chat Model',
    ]);

    let grew = true;

    while (grew) {
        grew = false;

        for (const [from, outputs] of Object.entries(exported.connections)) {
            if (!reachable.has(from)) {
                continue;
            }

            for (const group of Object.values(outputs)) {
                for (const targets of group) {
                    for (const { node } of targets ?? []) {
                        if (!reachable.has(node)) {
                            reachable.add(node);
                            grew = true;
                        }
                    }
                }
            }
        }
    }

    assert.deepEqual(
        exported.nodes
            .map(({ name }) => name)
            .filter((name) => !reachable.has(name)),
        [],
        'every node must be reachable',
    );

    // fullResponse is what lets the next Code node read the status. v3 set
    // neverError without it, so a 500 flowed on as if it were data.
    for (const node of exported.nodes.filter(
        ({ type }) => type === 'n8n-nodes-base.httpRequest',
    )) {
        const response = node.parameters.options?.response?.response ?? {};
        assert.equal(response.fullResponse, true, `${node.name} fullResponse`);
        assert.equal(response.neverError, true, `${node.name} neverError`);
    }
});

test('apply posts the exact signed bytes as a raw body', async () => {
    const exported = await workflow();
    const publish = exported.nodes.find(
        ({ name }) => name === 'Publish SBC Catalog',
    );

    assert.equal(publish.parameters.method, 'POST');
    // Raw, not jsonBody: n8n re-serialising the payload would break the HMAC.
    assert.equal(publish.parameters.contentType, 'raw');
    assert.equal(publish.parameters.rawContentType, 'application/json');
    assert.equal(publish.parameters.body, '={{ $json.rawBody }}');
    assert.equal(publish.parameters.jsonBody, undefined);

    const headers = Object.fromEntries(
        publish.parameters.headerParameters.parameters.map(
            ({ name, value }) => [name, value],
        ),
    );
    assert.equal(headers['X-ArabUT-Signature'], '={{ $json.signature }}');
    assert.equal(headers['X-ArabUT-Event'], '={{ $json.event }}');
    // rawContentType already sets it; a second header can trip strict proxies.
    assert.equal(headers['Content-Type'], undefined);
});

test('workflow embeds every Code node source exactly and all sources compile', async () => {
    const AsyncFunction = Object.getPrototypeOf(
        async function () {},
    ).constructor;

    for (const file of ['workflow.json', 'error-workflow.json']) {
        const exported = await workflow(file);

        for (const node of exported.nodes.filter(
            ({ type }) => type === 'n8n-nodes-base.code',
        )) {
            const fileName = node.notes?.replace(/^Source: nodes\//, '');
            assert.ok(fileName, `${node.name} must declare its source file`);
            assert.equal(
                node.parameters.jsCode,
                await readFile(new URL(`nodes/${fileName}`, root), 'utf8'),
                `${node.name} export is stale`,
            );
            assert.doesNotThrow(
                () => new AsyncFunction(node.parameters.jsCode),
                node.name,
            );
        }
    }
});

test('the error workflow is the only alerting path and cannot bury a failure', async () => {
    const exported = await workflow('error-workflow.json');
    const names = exported.nodes.map(({ name }) => name);

    assert.deepEqual(names, [
        'On Workflow Error',
        'Build Failure Alert',
        'Telegram Failure Alert',
    ]);
    assert.equal(exported.nodes[0].type, 'n8n-nodes-base.errorTrigger');

    const telegram = exported.nodes.find(
        ({ name }) => name === 'Telegram Failure Alert',
    );
    // Wired to $json.to so an unset chat id turns the node red rather than
    // silently delivering nowhere. v3 hardcoded the id and discarded the value.
    assert.equal(telegram.parameters.chatId, '={{ $json.to }}');
    assert.equal(telegram.onError, undefined);

    // Alert copy is Gulf-leaning Arabic, never Egyptian.
    const alert = exported.nodes.find(
        ({ name }) => name === 'Build Failure Alert',
    ).parameters.jsCode;
    assert.doesNotMatch(alert, /مفيش|عايز|ايه/);
    // Comments stripped first: the file explains at length why it must not
    // throw, and that prose is not a throw statement.
    const alertCode = alert
        .replace(/\/\*[\s\S]*?\*\//g, '')
        .replace(/^\s*\/\/.*$/gm, '');

    assert.doesNotMatch(
        alertCode,
        /\bthrow\b/,
        'the alert builder must never throw inside an error execution',
    );
});

test('README documents the failure model and the manual-run state caveat', async () => {
    const readme = await readFile(new URL('README.md', root), 'utf8');

    assert.match(readme, /Error Workflow/);
    assert.match(readme, /FFT_API_USER/);
    assert.match(readme, /FFT_API_KEY/);
    assert.match(readme, /manual[^\n]{0,200}not persist/i);
});
