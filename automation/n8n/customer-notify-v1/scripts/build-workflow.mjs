import assert from 'node:assert/strict';
import { readFile, writeFile } from 'node:fs/promises';
import process from 'node:process';

// customer-notify v1: the sending half of `Customer Notifier v2`, and nothing
// else. The store decides whether a message is owed, which message it is, and
// what it says; this workflow verifies the request, refuses a repeat, and
// calls Whapi. Contract: docs/api/n8n-customer-notification-v1.md.

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

// The instance has neither environment variables nor $vars (owner decision,
// 2026-09-14): every secret is a field of this Edit Fields node, pasted in the
// n8n UI after import. The export carries CONFIGURE_ placeholders only, and
// Verify Request refuses to run while any of them is still there.
export const CONFIG_KEYS = [
    ['N8N_CUSTOMER_NOTIFY_KEY', "the store's publisher identity, compared with X-ArabUT-Key"],
    ['N8N_CUSTOMER_NOTIFY_SECRET', 'HMAC secret of the incoming request, 32+ characters, the same value as on the store'],
    ['WHAPI_TOKEN', 'Whapi channel token, sent as Authorization: Bearer'],
    ['WHAPI_BASE_URL', 'Whapi gateway origin - ships filled in, change only for a second channel'],
];

export const CONFIG_DEFAULTS = { WHAPI_BASE_URL: 'https://gate.whapi.cloud' };

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
        id: 'config-customer-notify-v1',
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
            path: 'arabut-customer-notify-v1',
            responseMode: 'responseNode',
            options: {
                // The store signs the exact bytes it sends; Verify Request
                // reads them from binary.data. Without this the signature can
                // never be checked and the run fails at the first node.
                rawBody: true,
            },
        },
        id: 'webhook-customer-notify-v1',
        name: 'Webhook',
        type: 'n8n-nodes-base.webhook',
        typeVersion: 2,
        position: [180, 400],
        webhookId: 'b7e41c92-5a3d-4f18-9c62-8e0d4a7b3f15',
        notes:
            'The store posts customer.notify (schema 1) here. Authentication is ' +
            'the HMAC in Verify Request, not an n8n credential, so nothing is ' +
            'attached. Raw Body must stay on.',
    },
    configNode([400, 400]),
    await codeNode('Verify Request', 'verify-request-customer-notify-v1', 'verify-request.js', [620, 400]),
    await codeNode('Already Sent?', 'already-sent-customer-notify-v1', 'already-sent.js', [840, 400]),
    {
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
                        id: 'send-or-skip-condition',
                        leftValue: '={{ $json.alreadySent }}',
                        rightValue: '',
                        operator: { type: 'boolean', operation: 'true', singleValue: true },
                    },
                ],
                combinator: 'and',
            },
            options: {},
        },
        id: 'send-or-skip-customer-notify-v1',
        name: 'Send or Skip',
        type: 'n8n-nodes-base.if',
        typeVersion: 2.2,
        position: [1060, 400],
        notes:
            'True = this key was already sent from this instance, so the store ' +
            'is retrying a message that arrived; answer acknowledged without a ' +
            'second Whapi call. False = send it.',
    },
    {
        parameters: {
            method: 'POST',
            url: '={{ $(\'Config\').first().json.WHAPI_BASE_URL }}/messages/text',
            sendHeaders: true,
            headerParameters: {
                parameters: [
                    {
                        name: 'Authorization',
                        value: '={{ "Bearer " + $(\'Config\').first().json.WHAPI_TOKEN }}',
                    },
                ],
            },
            sendBody: true,
            specifyBody: 'json',
            jsonBody: '={{ JSON.stringify({ to: $json.to, body: $json.body }) }}',
            options: {
                // The store waits 60 seconds for the whole run. Whapi answers
                // in under a second when it is healthy; 20 leaves room for a
                // bad day without spending the store's whole budget.
                timeout: 20000,
                response: { response: { fullResponse: true, neverError: true } },
            },
        },
        id: 'send-whatsapp-customer-notify-v1',
        name: 'Send WhatsApp',
        type: 'n8n-nodes-base.httpRequest',
        typeVersion: 4.4,
        position: [1280, 500],
        onError: 'continueRegularOutput',
        notes:
            'Never errors and never throws: a dead number and a Whapi outage are ' +
            'both "the store retries", not incidents. Record Result reads the ' +
            'status code and decides. Only verification failures reach the Error ' +
            'Workflow.',
    },
    await codeNode('Record Result', 'record-result-customer-notify-v1', 'record-result.js', [1500, 500]),
    {
        parameters: {
            respondWith: 'json',
            responseBody: '={{ JSON.stringify({ data: { acknowledged: $json.acknowledged } }) }}',
            options: { responseCode: 200 },
        },
        id: 'respond-customer-notify-v1',
        name: 'Respond',
        type: 'n8n-nodes-base.respondToWebhook',
        typeVersion: 1.1,
        position: [1720, 500],
        notes:
            'acknowledged:false sends the delivery row back to queued on the ' +
            "store's backoff. A throw before this node answers 500, which the " +
            'store also treats as "retry later".',
    },
    {
        parameters: {
            respondWith: 'json',
            responseBody: '={{ JSON.stringify({ data: { acknowledged: true } }) }}',
            options: { responseCode: 200 },
        },
        id: 'respond-duplicate-customer-notify-v1',
        name: 'Respond Already Sent',
        type: 'n8n-nodes-base.respondToWebhook',
        typeVersion: 1.1,
        position: [1280, 280],
        notes:
            'The message already went out from this instance; acknowledging lets ' +
            'the store close the row instead of retrying a customer into reading ' +
            'it twice.',
    },
];

const connections = {
    Webhook: { main: [[edge('Config')]] },
    Config: { main: [[edge('Verify Request')]] },
    'Verify Request': { main: [[edge('Already Sent?')]] },
    'Already Sent?': { main: [[edge('Send or Skip')]] },
    // true -> already sent, answer and stop; false -> send it
    'Send or Skip': { main: [[edge('Respond Already Sent')], [edge('Send WhatsApp')]] },
    'Send WhatsApp': { main: [[edge('Record Result')]] },
    'Record Result': { main: [[edge('Respond')]] },
};

const workflow = {
    name: 'customer-notify v1 - send one customer WhatsApp message',
    nodes,
    connections,
    active: false,
    settings: {
        executionOrder: 'v1',
        availableInMCP: false,
        // The store waits 60 seconds; a run that outlives that is retried.
        executionTimeout: 50,
        // The request carries the customer's name, phone number and the whole
        // message body. No mode may keep the execution data (ADR 2026-09-12
        // covers credential-bearing workflows; this one carries a person).
        // Verify on the instance after import: the settings screen can
        // override what the export says.
        saveDataErrorExecution: 'none',
        saveDataSuccessExecution: 'none',
        saveManualExecutions: false,
        saveExecutionProgress: false,
        // errorWorkflow is intentionally absent: it holds an n8n-instance
        // specific id. Set it by hand to "customer-notify - Failure Alert",
        // or nothing alerts. See README "Failure model".
    },
    versionId: 'CUSTOMER_NOTIFY_V1_VERSION',
    meta: { templateCredsSetupCompleted: false },
    tags: [],
};

const errorWorkflow = {
    name: 'customer-notify - Failure Alert',
    nodes: [
        {
            parameters: {},
            id: 'error-trigger-customer-notify-v1',
            name: 'On Workflow Error',
            type: 'n8n-nodes-base.errorTrigger',
            typeVersion: 1,
            position: [180, 280],
            notes: 'Set Workflow Settings -> Error Workflow of customer-notify to point here.',
        },
        {
            parameters: {
                mode: 'runOnceForAllItems',
                jsCode: await readFile(new URL('nodes/failure-alert.js', root), 'utf8'),
            },
            id: 'build-failure-alert-customer-notify-v1',
            name: 'Build Failure Alert',
            type: 'n8n-nodes-base.code',
            typeVersion: 2,
            position: [420, 280],
            notes: 'Source: nodes/failure-alert.js',
        },
        {
            parameters: {
                chatId: 'CONFIGURE_OPS_TELEGRAM_CHAT_ID',
                text: '={{ $json.body }}',
                additionalFields: {},
            },
            id: 'telegram-failure-alert-customer-notify-v1',
            name: 'Telegram Failure Alert',
            type: 'n8n-nodes-base.telegram',
            typeVersion: 1.2,
            position: [660, 280],
            webhookId: 'd3a91f76-2c85-4e0b-b719-5f8c6a2d4e90',
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
    versionId: 'CUSTOMER_NOTIFY_FAILURE_ALERT_VERSION',
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
