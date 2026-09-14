import assert from 'node:assert/strict';
import { readFile, writeFile } from 'node:fs/promises';
import process from 'node:process';

// ship-coins v1: Fulfillment v14's placement path, one shipment per run.
// Every HTTP node that talks to a supplier is v14's node with two changes -
// the shipment is read from `$('Shipment')` instead of `$('Coins Item')` /
// `$('SBC: Match & Validate')`, and it has a timeout. Node names are v14's
// where the node is v14's, so a reader of the old export finds them.

const root = new URL('../', import.meta.url);
const output = new URL('workflow.json', root);
const errorOutput = new URL('error-workflow.json', root);

async function codeNode(name, id, sourceFile, position, extra = {}) {
    return {
        parameters: {
            mode: 'runOnceForAllItems',
            jsCode: await readFile(new URL(`nodes/${sourceFile}`, root), 'utf8'),
        },
        id,
        name,
        type: 'n8n-nodes-base.code',
        typeVersion: 2,
        position,
        notes: `Source: nodes/${sourceFile}`,
        ...extra,
    };
}

function edge(node, index = 0) {
    return { node, type: 'main', index };
}

function ifNode(name, id, leftValue, position, notes) {
    return {
        parameters: {
            conditions: {
                options: {
                    caseSensitive: true,
                    leftValue: '',
                    typeValidation: 'loose',
                    version: 3,
                },
                conditions: [
                    {
                        id: `${id}-condition`,
                        leftValue,
                        rightValue: '',
                        operator: {
                            type: 'boolean',
                            operation: 'true',
                            singleValue: true,
                        },
                    },
                ],
                combinator: 'and',
            },
            options: {},
        },
        id,
        name,
        type: 'n8n-nodes-base.if',
        typeVersion: 2.2,
        position,
        notes,
    };
}

// A supplier call fails loudly: no continueOnFail, so a transport error is a
// thrown error and the Error Workflow reports it. In-band rejections (HTTP
// 200 with an error body) are caught by Extract Reference.
function supplierHttp(name, id, position, parameters, extra = {}) {
    return {
        parameters: { ...parameters, options: { timeout: 20000, ...(parameters.options ?? {}) } },
        id,
        name,
        type: 'n8n-nodes-base.httpRequest',
        typeVersion: 4.4,
        position,
        ...extra,
    };
}

const S = "$('Shipment').first().json";

const nodes = [
    {
        parameters: {
            httpMethod: 'POST',
            path: 'arabut-ship-coins-v1',
            responseMode: 'responseNode',
            options: {
                // The store signs the exact bytes it sends; Verify Request
                // reads them from binary.data. Without this the signature can
                // never be checked and the run fails at the first node.
                rawBody: true,
            },
        },
        id: 'webhook-ship-coins-v1',
        name: 'Webhook',
        type: 'n8n-nodes-base.webhook',
        typeVersion: 2,
        position: [180, 400],
        webhookId: '4e2f9c1a-7b6d-4a53-9e8c-2d1f0b7a5c63',
        notes:
            'The store posts order.paid (schema 2) here. Authentication is the ' +
            'HMAC in Verify Request, not an n8n credential, so nothing is ' +
            'attached. Raw Body must stay on.',
    },
    await codeNode('Verify Request', 'verify-request-ship-coins-v1', 'verify-request.js', [420, 400]),
    await codeNode('Plan Shipment', 'plan-shipment-ship-coins-v1', 'plan-shipment.js', [660, 400]),
    ifNode(
        'Needs Challenge Price?',
        'needs-challenge-price-ship-coins-v1',
        '={{ $json.needsChallengePricing }}',
        [900, 400],
        'True = the shipment funds a challenge, and FFT has to price it first. False = plain coins.',
    ),
    // v14: SBC: Get Available SBCs
    supplierHttp('SBC: Get Available SBCs', 'available-sbcs-ship-coins-v1', [1140, 240], {
        method: 'POST',
        url: 'https://futtransfer.top/availableSBCsAPI',
        sendBody: true,
        bodyParameters: {
            parameters: [
                { name: 'apiUser', value: '={{ $env.FFT_API_USER }}' },
                { name: 'apiKey', value: '={{ $env.FFT_API_KEY }}' },
            ],
        },
        options: { response: { response: { responseFormat: 'json' } } },
    }, { retryOnFail: true, maxTries: 3, waitBetweenTries: 1500 }),
    await codeNode('SBC: Match & Validate', 'match-challenges-ship-coins-v1', 'match-challenges.js', [1380, 240]),
    await codeNode('Shipment', 'shipment-ship-coins-v1', 'shipment.js', [1620, 400]),
    ifNode(
        'Slow Shipping?',
        'slow-shipping-ship-coins-v1',
        '={{ $json.isSlowShipping }}',
        [1860, 400],
        'v14. True = slow console delivery, which always goes to FFT (cycle when UTT cards are out). False = choose a supplier.',
    ),
    // v14: UTT: Check Slow Cooldown
    supplierHttp('UTT: Check Slow Cooldown', 'utt-slow-cooldown-ship-coins-v1', [2100, 240], {
        method: 'POST',
        url: 'https://utautotransfer.com/api/getMaxOrderPrediction',
        sendBody: true,
        contentType: 'form-urlencoded',
        bodyParameters: {
            parameters: [
                { name: 'apiKey', value: '={{ $env.UTT_API_KEY }}' },
                { name: 'platform', value: `={{ ${S}.platform.toLowerCase() }}` },
                { name: 'orderMethod', value: 'public' },
                { name: 'email', value: `={{ ${S}.eaEmail }}` },
            ],
        },
    }),
    // v14: UTT: Get Stocks
    supplierHttp('UTT: Get Stocks', 'utt-stocks-ship-coins-v1', [2100, 560], {
        method: 'POST',
        url: 'https://utautotransfer.com/api/getPublicSaleStocks',
        sendBody: true,
        contentType: 'form-urlencoded',
        bodyParameters: {
            parameters: [
                { name: 'apiKey', value: '={{ $env.UTT_API_KEY }}' },
                { name: 'platform', value: `={{ ${S}.platform.toLowerCase() }}` },
            ],
        },
    }),
    await codeNode('Supplier Decision Engine', 'supplier-decision-ship-coins-v1', 'supplier-decision.js', [2340, 560]),
    ifNode(
        'Supplier Switch',
        'supplier-switch-ship-coins-v1',
        "={{ $json.supplier === 'UTT' }}",
        [2580, 560],
        'v14. True = UTT was chosen: check the FFT cooldown status first. False = FFT.',
    ),
    // v14: FFT: Check Cooldown
    supplierHttp('FFT: Check Cooldown', 'fft-cooldown-ship-coins-v1', [2820, 480], {
        method: 'POST',
        url: 'https://futtransfer.top/getCooldownStatus',
        sendBody: true,
        bodyParameters: {
            parameters: [
                { name: 'account', value: `={{ ${S}.eaEmail }}` },
                { name: 'apiUser', value: '={{ $env.FFT_API_USER }}' },
                { name: 'apiKey', value: '={{ $env.FFT_API_KEY }}' },
            ],
        },
    }),
    ifNode(
        'Cooldown OK?',
        'cooldown-ok-ship-coins-v1',
        "={{ (() => { try { const d = $json.data; const p = typeof d === 'string' ? JSON.parse(d) : (d || $json); return p.isReady === true; } catch(e) { return false; } })() }}",
        [3060, 480],
        'v14, ported as it was: isReady true sends the UTT order, false falls back to FFT.',
    ),
    // v14: UTT: Add Order Public
    supplierHttp('UTT: Add Order Public', 'utt-place-ship-coins-v1', [3300, 400], {
        method: 'POST',
        url: 'https://utautotransfer.com/api/addOrderPublic',
        sendBody: true,
        contentType: 'form-urlencoded',
        bodyParameters: {
            parameters: [
                { name: 'apiKey', value: '={{ $env.UTT_API_KEY }}' },
                { name: 'email', value: `={{ ${S}.eaEmail }}` },
                { name: 'password', value: `={{ ${S}.eaPass }}` },
                { name: 'amountOrder', value: `={{ ${S}.amountK * 1000 }}` },
                { name: 'platform', value: `={{ ${S}.platform.toLowerCase() }}` },
                { name: 'publicSale', value: "={{ $('Supplier Decision Engine').first().json.uttPublicSaleString }}" },
                { name: 'name', value: `={{ ${S}.customerName }}` },
                { name: 'backupCodes', value: `={{ [${S}.eaBackup1, ${S}.eaBackup2, ${S}.eaBackup3].filter(c => c).join(',') }}` },
                { name: 'checkDetails', value: '0' },
                { name: 'accountLock', value: '0' },
            ],
        },
        options: { timeout: 30000 },
    }),
    // v14: External: Buy Coins
    supplierHttp('External: Buy Coins', 'fft-place-ship-coins-v1', [3300, 640], {
        method: 'POST',
        url: 'https://futtransfer.top/buyCoinsAPI',
        sendBody: true,
        bodyParameters: {
            parameters: [
                { name: 'apiUser', value: '={{ $env.FFT_API_USER }}' },
                { name: 'apiKey', value: '={{ $env.FFT_API_KEY }}' },
                { name: 'platform', value: `={{ ${S}.platform }}` },
                { name: 'amount', value: `={{ ${S}.amountK }}` },
                { name: 'updateCustomer', value: '1' },
                { name: 'ba', value: `={{ ${S}.eaBackup1 }}` },
                { name: 'ba2', value: `={{ ${S}.eaBackup2 }}` },
                { name: 'ba3', value: `={{ ${S}.eaBackup3 }}` },
                { name: 'customerName', value: `={{ ${S}.customerName }}` },
                { name: 'externalOrderID', value: `={{ ${S}.orderId }}` },
                { name: 'user', value: `={{ ${S}.eaEmail }}` },
                { name: 'pass', value: `={{ ${S}.eaPass }}` },
                {
                    name: 'transferMethod',
                    value:
                        "={{ (() => {\n  try {\n    const cards = $('UTT: Check Slow Cooldown').first().json.orderCardsRemaining;\n    if (cards === 0) return 'cycle';\n  } catch(e) {}\n  return " +
                        `${S}.transferMethod;\n})() }}`,
                },
                { name: 'buyNowThreshold', value: `={{ ${S}.calculatedMaxPrice }}` },
                {
                    name: 'topUpEnabled',
                    value: `={{ ${S}.platform === 'PC' ? 50 : (${S}.amountK <= 300 ? 20 : ${S}.amountK <= 500 ? 20 : ${S}.amountK <= 1000 ? 50 : ${S}.amountK <= 1500 ? 100 : 150) }}`,
                },
                { name: 'autoFinishCycle', value: '1' },
                { name: 'riskLevel', value: '10' },
            ],
        },
        options: { timeout: 30000 },
    }),
    await codeNode('Extract Reference', 'extract-reference-ship-coins-v1', 'extract-reference.js', [3540, 520]),
    await codeNode('Sign Placement Reports', 'sign-reports-ship-coins-v1', 'sign-reports.js', [3780, 520]),
    {
        parameters: {
            method: 'POST',
            url: "={{ ($env.ARABUT_STORE_URL || 'https://store.arab-ut.com') + '/api/automation/v1/fulfillment/placements' }}",
            sendHeaders: true,
            headerParameters: {
                parameters: [
                    { name: 'Accept', value: 'application/json' },
                    { name: 'X-ArabUT-Key', value: '={{ $env.N8N_FULFILLMENT_KEY }}' },
                    { name: 'X-ArabUT-Timestamp', value: '={{ $json.timestamp }}' },
                    { name: 'X-ArabUT-Event', value: '={{ $json.event }}' },
                    { name: 'X-ArabUT-Signature', value: '={{ $json.signature }}' },
                ],
            },
            sendBody: true,
            contentType: 'raw',
            rawContentType: 'application/json',
            // Sent raw so the exact bytes that were HMAC'd are the exact bytes
            // on the wire.
            body: '={{ $json.rawBody }}',
            options: {
                response: {
                    response: {
                        fullResponse: true,
                        neverError: true,
                        responseFormat: 'json',
                    },
                },
                timeout: 15000,
            },
        },
        id: 'report-placement-ship-coins-v1',
        name: 'Report Placement',
        type: 'n8n-nodes-base.httpRequest',
        typeVersion: 4.4,
        position: [4020, 520],
        // Idempotent on the store's side: a retried report of the same
        // reference is a 200 no-op.
        retryOnFail: true,
        maxTries: 3,
        waitBetweenTries: 1500,
        notes:
            'Runs once per funded item. Signed with N8N_FULFILLMENT_KEY / ' +
            'N8N_FULFILLMENT_SECRET, the store-side credential of the placement ' +
            'endpoint (docs/api/n8n-fulfillment-v1.md).',
    },
    await codeNode('Confirm Reports', 'confirm-reports-ship-coins-v1', 'confirm-reports.js', [4260, 520]),
    {
        parameters: {
            respondWith: 'json',
            responseBody:
                '={{ JSON.stringify({ data: { acknowledged: $json.acknowledged, placed: $json.placed, remaining: $json.remaining } }) }}',
            options: { responseCode: 200 },
        },
        id: 'respond-ship-coins-v1',
        name: 'Respond',
        type: 'n8n-nodes-base.respondToWebhook',
        typeVersion: 1.1,
        position: [4500, 520],
        notes:
            'acknowledged:false while other shipments remain - the store retries ' +
            'with what is still unplaced. A throw anywhere before this node ' +
            'answers 500, which the store also treats as "retry later".',
    },
];

const connections = {
    Webhook: { main: [[edge('Verify Request')]] },
    'Verify Request': { main: [[edge('Plan Shipment')]] },
    'Plan Shipment': { main: [[edge('Needs Challenge Price?')]] },
    // true -> FFT prices the challenge first; false -> plain coins
    'Needs Challenge Price?': {
        main: [[edge('SBC: Get Available SBCs')], [edge('Shipment')]],
    },
    'SBC: Get Available SBCs': { main: [[edge('SBC: Match & Validate')]] },
    'SBC: Match & Validate': { main: [[edge('Shipment')]] },
    Shipment: { main: [[edge('Slow Shipping?')]] },
    // true -> slow: FFT after the UTT card check; false -> choose a supplier
    'Slow Shipping?': {
        main: [[edge('UTT: Check Slow Cooldown')], [edge('UTT: Get Stocks')]],
    },
    'UTT: Check Slow Cooldown': { main: [[edge('External: Buy Coins')]] },
    'UTT: Get Stocks': { main: [[edge('Supplier Decision Engine')]] },
    'Supplier Decision Engine': { main: [[edge('Supplier Switch')]] },
    // true -> UTT chosen, check the cooldown first; false -> FFT
    'Supplier Switch': {
        main: [[edge('FFT: Check Cooldown')], [edge('External: Buy Coins')]],
    },
    'FFT: Check Cooldown': { main: [[edge('Cooldown OK?')]] },
    // v14: isReady -> UTT; otherwise FFT
    'Cooldown OK?': {
        main: [[edge('UTT: Add Order Public')], [edge('External: Buy Coins')]],
    },
    'UTT: Add Order Public': { main: [[edge('Extract Reference')]] },
    'External: Buy Coins': { main: [[edge('Extract Reference')]] },
    'Extract Reference': { main: [[edge('Sign Placement Reports')]] },
    'Sign Placement Reports': { main: [[edge('Report Placement')]] },
    'Report Placement': { main: [[edge('Confirm Reports')]] },
    'Confirm Reports': { main: [[edge('Respond')]] },
};

const workflow = {
    name: 'ship-coins v1 - place one shipment for a paid order',
    nodes,
    connections,
    active: false,
    settings: {
        executionOrder: 'v1',
        availableInMCP: false,
        // The store waits 60 seconds; a run that outlives that is retried, and
        // the retry carries only what is still unplaced.
        executionTimeout: 110,
        // The EA account travels in the request (ADR 2026-09-12): no mode
        // may keep the execution data. Verify on the instance after import;
        // the settings screen can override what the export says.
        saveDataErrorExecution: 'none',
        saveDataSuccessExecution: 'none',
        saveManualExecutions: false,
        saveExecutionProgress: false,
        // errorWorkflow is intentionally absent: it holds an n8n-instance
        // specific id. Set it by hand to "ship-coins - Failure Alert", or
        // nothing alerts. See README "Failure model".
    },
    versionId: 'SHIP_COINS_V1_VERSION',
    meta: { templateCredsSetupCompleted: false },
    tags: [],
};

const errorWorkflow = {
    name: 'ship-coins - Failure Alert',
    nodes: [
        {
            parameters: {},
            id: 'error-trigger-ship-coins-v1',
            name: 'On Workflow Error',
            type: 'n8n-nodes-base.errorTrigger',
            typeVersion: 1,
            position: [180, 280],
            notes: 'Set Workflow Settings -> Error Workflow of ship-coins to point here.',
        },
        {
            parameters: {
                mode: 'runOnceForAllItems',
                jsCode: await readFile(new URL('nodes/failure-alert.js', root), 'utf8'),
            },
            id: 'build-failure-alert-ship-coins-v1',
            name: 'Build Failure Alert',
            type: 'n8n-nodes-base.code',
            typeVersion: 2,
            position: [420, 280],
            notes: 'Source: nodes/failure-alert.js',
        },
        {
            parameters: {
                chatId: '={{ $json.to }}',
                text: '={{ $json.body }}',
                additionalFields: {},
            },
            id: 'telegram-failure-alert-ship-coins-v1',
            name: 'Telegram Failure Alert',
            type: 'n8n-nodes-base.telegram',
            typeVersion: 1.2,
            position: [660, 280],
            webhookId: '9c4d7e21-3f8a-4b6e-8d15-6a2c0f9b7e41',
            credentials: {
                telegramApi: {
                    id: 'CONFIGURE_TELEGRAM_CREDENTIAL_ID',
                    name: 'Telegram account',
                },
            },
            notes: 'No onError override: an undelivered alert stays red.',
        },
    ],
    connections: {
        'On Workflow Error': { main: [[edge('Build Failure Alert')]] },
        'Build Failure Alert': { main: [[edge('Telegram Failure Alert')]] },
    },
    active: false,
    settings: { executionOrder: 'v1', availableInMCP: false },
    versionId: 'SHIP_COINS_FAILURE_ALERT_VERSION',
    meta: { templateCredsSetupCompleted: false },
    tags: [],
};

const serialized = `${JSON.stringify(workflow, null, 2)}\n`;
const errorSerialized = `${JSON.stringify(errorWorkflow, null, 2)}\n`;

if (process.argv.includes('--check')) {
    assert.equal(await readFile(output, 'utf8'), serialized, 'workflow.json is stale; run npm run build');
    assert.equal(await readFile(errorOutput, 'utf8'), errorSerialized, 'error-workflow.json is stale; run npm run build');
} else {
    await writeFile(output, serialized, 'utf8');
    await writeFile(errorOutput, errorSerialized, 'utf8');
}
