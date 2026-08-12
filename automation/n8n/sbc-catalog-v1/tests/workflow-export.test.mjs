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
    assert.doesNotMatch(text, /salla|woocommerce|wordpress|gemini|fft|delete/i);
    assert.doesNotMatch(
        text,
        /N8N_SBC_(?:CATALOG|PRICING_READ)_SECRET\s*[:=]\s*['"][^$]/i,
    );
    assert.deepEqual([...new Set(credentials)].sort(), [
        'ArabUT SBC Catalog API',
        'ArabUT SBC Pricing Read API',
        'Whapi Alerts',
    ]);

    const schedule = nodes.find(({ name }) => name === 'Every 2 Hours');
    assert.equal(schedule.parameters.rule.interval[0].field, 'hours');
    assert.equal(schedule.parameters.rule.interval[0].hoursInterval, 2);
    assert.ok(nodes.some(({ name }) => name === 'Run SBC Catalog Now'));
});

test('dry-run graph cannot reach the catalog POST and every invalid branch fails closed', async () => {
    const exported = await workflow();
    const connections = exported.connections;

    assert.deepEqual(connections['Apply Mode?'].main[1], [
        { node: 'Dry Run Summary', type: 'main', index: 0 },
    ]);
    assert.equal(connections['Dry Run Summary'], undefined);

    for (const guard of [
        'Pricing Read Signed?',
        'Pricing Ready?',
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
