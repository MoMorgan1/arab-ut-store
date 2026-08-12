import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { test } from 'node:test';

const root = new URL('../', import.meta.url);

async function workflow() {
    return JSON.parse(await readFile(new URL('workflow.json', root), 'utf8'));
}

test('export is inactive, secret-free, two-hourly, and references only approved credentials', async () => {
    const exported = await workflow();
    const text = JSON.stringify(exported);
    const nodes = exported.nodes ?? [];
    const credentials = nodes
        .flatMap((node) => Object.values(node.credentials ?? {}))
        .map(({ name }) => name);

    assert.equal(exported.active, false);
    assert.doesNotMatch(text, /salla|woocommerce|wordpress|fft|delete/i);
    assert.doesNotMatch(
        text,
        /N8N_SBC_(?:CATALOG|PRICING_READ)_SECRET\s*[:=]\s*['"][^$]/i,
    );
    assert.deepEqual([...new Set(credentials)].sort(), [
        'ArabUT SBC Catalog API',
        'ArabUT SBC Pricing Read API',
        'Google Gemini(PaLM) Api account 2',
        'Whapi Alerts',
    ]);

    const schedule = nodes.find(({ name }) => name === 'Every 2 Hours');
    assert.equal(schedule.parameters.rule.interval[0].field, 'hours');
    assert.equal(schedule.parameters.rule.interval[0].hoursInterval, 2);
    assert.ok(nodes.some(({ name }) => name === 'Run SBC Catalog Now'));
});

test('dry-run graph cannot reach the catalog POST and every invalid branch terminates with an error', async () => {
    const exported = await workflow();
    const connections = exported.connections;

    assert.deepEqual(connections['Apply Mode?'].main[1], [
        { node: 'Dry Run Summary', type: 'main', index: 0 },
    ]);
    assert.equal(connections['Dry Run Summary'], undefined);

    for (const guard of [
        'Pricing Read Signed?',
        'Pricing Ready?',
        'Translation Plan Valid?',
        'Translation Valid?',
        'Snapshot Valid?',
        'Catalog Signed?',
        'Publish OK?',
    ]) {
        assert.deepEqual(
            connections[guard].main[1],
            [{ node: 'Prepare Failure Alert', type: 'main', index: 0 }],
            `${guard} must fail closed`,
        );
    }

    const publish = exported.nodes.filter(
        ({ name, parameters }) =>
            name === 'Publish SBC Catalog' && parameters?.method === 'POST',
    );
    assert.equal(publish.length, 1);
    assert.equal(publish[0].name, 'Publish SBC Catalog');
    assert.deepEqual(connections['Catalog Signed?'].main[0], [
        { node: 'Publish SBC Catalog', type: 'main', index: 0 },
    ]);

    assert.deepEqual(connections['Alert Configured?'].main[0], [
        { node: 'Whapi Failure Alert', type: 'main', index: 0 },
    ]);
    assert.deepEqual(connections['Alert Configured?'].main[1], [
        { node: 'Stop Workflow With Error', type: 'main', index: 0 },
    ]);
    assert.deepEqual(connections['Whapi Failure Alert'].main[0], [
        { node: 'Stop Workflow With Error', type: 'main', index: 0 },
    ]);
    assert.equal(
        exported.nodes.find(({ name }) => name === 'Stop Workflow With Error')
            .type,
        'n8n-nodes-base.stopAndError',
    );
});

test('workflow embeds every Code node source exactly and all sources compile', async () => {
    const exported = await workflow();
    const AsyncFunction = Object.getPrototypeOf(
        async function () {},
    ).constructor;

    for (const node of exported.nodes.filter(
        ({ type }) => type === 'n8n-nodes-base.code',
    )) {
        const fileName = node.notes?.replace(/^Source: nodes\//, '');
        assert.ok(
            fileName,
            `${node.name} must declare its versioned source file`,
        );
        const source = await readFile(
            new URL(`nodes/${fileName}`, root),
            'utf8',
        );
        assert.equal(
            node.parameters.jsCode,
            source,
            `${node.name} export is stale`,
        );
        assert.doesNotThrow(
            () => new AsyncFunction(node.parameters.jsCode),
            node.name,
        );
    }
});

test('source fetch is a single bounded page and apply posts the exact signed raw body', async () => {
    const exported = await workflow();
    const nodes = exported.nodes;
    const source = nodes.find(({ name }) => name === 'Fetch EasySBC Sets');
    const publish = nodes.find(({ name }) => name === 'Publish SBC Catalog');

    assert.equal(source.parameters.method, 'GET');
    assert.equal(
        source.parameters.url,
        '={{ $("Config").first().json.settings.sourceEndpoint }}',
    );
    assert.equal(publish.parameters.method, 'POST');
    assert.equal(
        publish.parameters.jsonBody,
        '={{ $("Sign Catalog Snapshot").first().json.rawBody }}',
    );
    assert.equal(
        publish.parameters.headerParameters.parameters.find(
            ({ name }) => name === 'X-ArabUT-Event',
        ).value,
        '={{ $("Sign Catalog Snapshot").first().json.event }}',
    );
});

test('translation enrichment uses the approved Gemini credential and exact cache gates', async () => {
    const exported = await workflow();
    const model = exported.nodes.find(
        ({ name }) => name === 'Gemini Translation Model',
    );

    assert.deepEqual(model.credentials.googlePalmApi, {
        id: 'WgUWtkjmfC1iEIMi',
        name: 'Google Gemini(PaLM) Api account 2',
    });
    assert.deepEqual(
        exported.connections['Gemini Translation Model'].ai_languageModel[0],
        [{ node: 'Translate SBC Names', type: 'ai_languageModel', index: 0 }],
    );
    assert.deepEqual(exported.connections['Translation Ready?'].main[0], [
        { node: 'Prepare SBC Snapshot', type: 'main', index: 0 },
    ]);
    assert.deepEqual(exported.connections['Translation Ready?'].main[1], [
        { node: 'Translate SBC Names', type: 'main', index: 0 },
    ]);
});

test('production config contains the approved 56/39 bootstrap baseline', async () => {
    const exported = await workflow();
    const configSource = exported.nodes.find(({ name }) => name === 'Config')
        .parameters.jsCode;

    assert.match(configSource, /sourceCount:\s*56/);
    assert.match(configSource, /eligibleCount:\s*39/);
    assert.match(configSource, /approvedBy:\s*'operator'/);
});

test('rollout requires the EasySBC media allowlist and mirrored-media verification', async () => {
    const readme = await readFile(new URL('README.md', root), 'utf8');

    assert.match(readme, /N8N_CATALOG_MEDIA_HOSTS=assets\.easysbc\.io/);
    assert.match(readme, /verify mirrored media counts/i);
});
