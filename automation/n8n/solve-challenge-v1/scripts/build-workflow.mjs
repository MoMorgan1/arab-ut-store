import assert from 'node:assert/strict';
import { readFile, writeFile } from 'node:fs/promises';
import process from 'node:process';

// solve-challenge v1: the solve half of Fulfillment v14's SBC branch, started
// by the store once the coins for a challenge have landed (challenge.ready,
// schema 1). The funding half lives in ship-coins. The two FFT nodes are
// v14's with two changes - the challenge is read from `$('Validate Set')`
// instead of `$('SBC: Split SBC IDs')`, and each has a timeout. Node names
// are v14's where the node is v14's, so a reader of the old export finds them.

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

// A supplier call fails loudly: no continueOnFail, so a transport error is a
// thrown error and the Error Workflow reports it. In-band rejections (HTTP
// 200 with an error body) are caught by Extract Solve Ids.
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

const V = "$('Validate Set').first().json";
const C = "$('Config').first().json";

// The instance has neither environment variables nor $vars (owner decision,
// 2026-09-14: "use the data inside the node"). Every key and secret is a
// field of this Edit Fields node, pasted in the n8n UI after import. The
// export carries CONFIGURE_ placeholders only; Verify Request refuses to run
// while any of them is still there. It passes the webhook item through -
// json and binary - so the raw body reaches Verify Request untouched.
export const CONFIG_KEYS = [
    ['N8N_SOLVE_CHALLENGE_KEY', "the store's solve publisher identity, compared with X-ArabUT-Key"],
    ['N8N_SOLVE_CHALLENGE_SECRET', 'HMAC secret of the incoming request, 32+ characters, same value as on the store'],
    ['N8N_FULFILLMENT_KEY', 'X-ArabUT-Key the store expects on the placement report - the same value ship-coins uses'],
    ['N8N_FULFILLMENT_SECRET', 'HMAC secret of the placement report, 32+ characters, same value as on the store'],
    ['FFT_API_USER', 'FuTTransfer API user'],
    ['FFT_API_KEY', 'FuTTransfer API key'],
    ['ARABUT_STORE_URL', 'the store origin - ships filled in, change only for a staging store'],
];

// A value that ships filled in. Everything else is a CONFIGURE_ placeholder,
// and a placeholder is never a value (ship-coins, 2026-09-15: a placeholder
// store URL was read as an origin and a placed shipment went unreported).
export const CONFIG_DEFAULTS = { ARABUT_STORE_URL: 'https://store.arab-ut.com' };

function configNode(position) {
    return {
        parameters: {
            assignments: {
                assignments: CONFIG_KEYS.map(([name]) => ({
                    id: `config-${name.toLowerCase().replace(/_/g, '-')}`,
                    name,
                    value: CONFIG_DEFAULTS[name] ?? `CONFIGURE_${name}`,
                    type: 'string',
                })),
            },
            includeOtherFields: true,
            options: { includeBinary: true },
        },
        id: 'config-solve-challenge-v1',
        name: 'Config',
        type: 'n8n-nodes-base.set',
        typeVersion: 3.4,
        position,
        notes:
            'Paste the real values here after import - ' +
            CONFIG_KEYS.map(([name, purpose]) => `${name}: ${purpose}`).join('; ') +
            '. Keep "Include Other Input Fields" and "Include Binary" on.',
    };
}

const nodes = [
    {
        parameters: {
            httpMethod: 'POST',
            path: 'arabut-solve-challenge-v1',
            responseMode: 'responseNode',
            options: {
                // The store signs the exact bytes it sends; Verify Request
                // reads them from binary.data. Without this the signature can
                // never be checked and the run fails at the first node.
                rawBody: true,
            },
        },
        id: 'webhook-solve-challenge-v1',
        name: 'Webhook',
        type: 'n8n-nodes-base.webhook',
        typeVersion: 2,
        position: [180, 400],
        webhookId: '7d3a5f92-1c8e-4b07-a6d4-9e2f1c5b8a70',
        notes:
            'The store posts challenge.ready (schema 1) here. Authentication is ' +
            'the HMAC in Verify Request, not an n8n credential, so nothing is ' +
            'attached. Raw Body must stay on.',
    },
    configNode([400, 400]),
    await codeNode('Verify Request', 'verify-request-solve-challenge-v1', 'verify-request.js', [620, 400]),
    // v14: SBC: Get Available SBCs
    supplierHttp('SBC: Get Available SBCs', 'available-sbcs-solve-challenge-v1', [840, 400], {
        method: 'POST',
        url: 'https://futtransfer.top/availableSBCsAPI',
        sendBody: true,
        bodyParameters: {
            parameters: [
                { name: 'apiUser', value: `={{ ${C}.FFT_API_USER }}` },
                { name: 'apiKey', value: `={{ ${C}.FFT_API_KEY }}` },
            ],
        },
        options: { response: { response: { responseFormat: 'json' } } },
    }, { retryOnFail: true, maxTries: 3, waitBetweenTries: 1500 }),
    await codeNode('Validate Set', 'validate-set-solve-challenge-v1', 'validate-set.js', [1060, 400]),
    // v14: SBC: Submit Solve
    supplierHttp('SBC: Submit Solve', 'submit-solve-solve-challenge-v1', [1280, 400], {
        method: 'POST',
        url: 'https://futtransfer.top/newSBCAPI',
        sendBody: true,
        specifyBody: 'json',
        jsonBody:
            '={{ JSON.stringify({\n' +
            `  "apiUser": ${C}.FFT_API_USER,\n` +
            `  "apiKey": ${C}.FFT_API_KEY,\n` +
            `  "setID": ${V}.setID,\n` +
            `  "account": [${V}.eaEmail],\n` +
            '  "accountType": "customer",\n' +
            `  "timesToSolve": ${V}.timesToSolve || 1,\n` +
            '  "fillSBC": 0,\n' +
            `  "customName": "Order #" + ${V}.orderId + " - " + ${V}.sbcName,\n` +
            '  "clubItemHandling": "exclude",\n' +
            '  "consoleLock": 1\n' +
            '}) }}',
        options: { timeout: 30000 },
    }),
    await codeNode('Extract Solve Ids', 'extract-solve-ids-solve-challenge-v1', 'extract-solve-ids.js', [1500, 400]),
    await codeNode('Sign Placement Report', 'sign-report-solve-challenge-v1', 'sign-report.js', [1720, 400]),
    {
        parameters: {
            method: 'POST',
            url: `={{ ((${C}.ARABUT_STORE_URL || '').startsWith('https://') ? ${C}.ARABUT_STORE_URL : 'https://store.arab-ut.com') + '/api/automation/v1/fulfillment/placements' }}`,
            sendHeaders: true,
            headerParameters: {
                parameters: [
                    { name: 'Accept', value: 'application/json' },
                    { name: 'X-ArabUT-Key', value: `={{ ${C}.N8N_FULFILLMENT_KEY }}` },
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
        id: 'report-placement-solve-challenge-v1',
        name: 'Report Placement',
        type: 'n8n-nodes-base.httpRequest',
        typeVersion: 4.4,
        position: [1940, 400],
        // Idempotent on the store's side: a retried report of the same
        // reference is a 200 no-op.
        retryOnFail: true,
        maxTries: 3,
        waitBetweenTries: 1500,
        notes:
            'Runs once per challenge. Signed with N8N_FULFILLMENT_KEY / ' +
            'N8N_FULFILLMENT_SECRET from the Config node, the store-side ' +
            'credential of the placement endpoint (docs/api/n8n-fulfillment-v1.md).',
    },
    await codeNode('Confirm Report', 'confirm-report-solve-challenge-v1', 'confirm-report.js', [2160, 400]),
    {
        parameters: {
            respondWith: 'json',
            responseBody: '={{ JSON.stringify({ data: { acknowledged: $json.acknowledged, placed: $json.placed } }) }}',
            options: { responseCode: 200 },
        },
        id: 'respond-solve-challenge-v1',
        name: 'Respond',
        type: 'n8n-nodes-base.respondToWebhook',
        typeVersion: 1.1,
        position: [2380, 400],
        notes:
            'A throw anywhere before this node answers 500, which the store ' +
            'treats as "retry later" on its backoff.',
    },
];

const connections = {
    Webhook: { main: [[edge('Config')]] },
    Config: { main: [[edge('Verify Request')]] },
    'Verify Request': { main: [[edge('SBC: Get Available SBCs')]] },
    'SBC: Get Available SBCs': { main: [[edge('Validate Set')]] },
    'Validate Set': { main: [[edge('SBC: Submit Solve')]] },
    'SBC: Submit Solve': { main: [[edge('Extract Solve Ids')]] },
    'Extract Solve Ids': { main: [[edge('Sign Placement Report')]] },
    'Sign Placement Report': { main: [[edge('Report Placement')]] },
    'Report Placement': { main: [[edge('Confirm Report')]] },
    'Confirm Report': { main: [[edge('Respond')]] },
};

const workflow = {
    name: 'solve-challenge v1 - submit the solve for a funded challenge',
    nodes,
    connections,
    active: false,
    settings: {
        executionOrder: 'v1',
        availableInMCP: false,
        // The store waits 60 seconds; a run that outlives that is retried.
        executionTimeout: 110,
        // The EA account travels in the request (ADR 2026-09-12): no mode
        // may keep the execution data. Verify on the instance after import;
        // the settings screen can override what the export says.
        saveDataErrorExecution: 'none',
        saveDataSuccessExecution: 'none',
        saveManualExecutions: false,
        saveExecutionProgress: false,
        // errorWorkflow is intentionally absent: it holds an n8n-instance
        // specific id. Set it by hand to "solve-challenge - Failure Alert",
        // or nothing alerts. See README "Failure model".
    },
    versionId: 'SOLVE_CHALLENGE_V1_VERSION',
    meta: { templateCredsSetupCompleted: false },
    tags: [],
};

const errorWorkflow = {
    name: 'solve-challenge - Failure Alert',
    nodes: [
        {
            parameters: {},
            id: 'error-trigger-solve-challenge-v1',
            name: 'On Workflow Error',
            type: 'n8n-nodes-base.errorTrigger',
            typeVersion: 1,
            position: [180, 280],
            notes: 'Set Workflow Settings -> Error Workflow of solve-challenge to point here.',
        },
        {
            parameters: {
                mode: 'runOnceForAllItems',
                jsCode: await readFile(new URL('nodes/failure-alert.js', root), 'utf8'),
            },
            id: 'build-failure-alert-solve-challenge-v1',
            name: 'Build Failure Alert',
            type: 'n8n-nodes-base.code',
            typeVersion: 2,
            position: [420, 280],
            notes: 'Source: nodes/failure-alert.js',
        },
        {
            parameters: {
                // Typed in here after import: the instance has no environment
                // variables and no $vars.
                chatId: 'CONFIGURE_OPS_TELEGRAM_CHAT_ID',
                text: '={{ $json.body }}',
                additionalFields: {},
            },
            id: 'telegram-failure-alert-solve-challenge-v1',
            name: 'Telegram Failure Alert',
            type: 'n8n-nodes-base.telegram',
            typeVersion: 1.2,
            position: [660, 280],
            webhookId: 'b2e6c814-5a9f-4d31-9c07-3f8e6a1d2b55',
            credentials: {
                telegramApi: {
                    id: 'CONFIGURE_TELEGRAM_CREDENTIAL_ID',
                    name: 'Telegram account',
                },
            },
            notes: 'Replace the Chat ID placeholder with the ops Telegram chat. No onError override: an undelivered alert stays red.',
        },
    ],
    connections: {
        'On Workflow Error': { main: [[edge('Build Failure Alert')]] },
        'Build Failure Alert': { main: [[edge('Telegram Failure Alert')]] },
    },
    active: false,
    settings: { executionOrder: 'v1', availableInMCP: false },
    versionId: 'SOLVE_CHALLENGE_FAILURE_ALERT_VERSION',
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
