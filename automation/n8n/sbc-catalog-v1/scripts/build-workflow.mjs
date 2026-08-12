import assert from 'node:assert/strict';
import { readFile, writeFile } from 'node:fs/promises';
import process from 'node:process';

const root = new URL('../', import.meta.url);
const output = new URL('workflow.json', root);

async function codeNode(name, id, sourceFile, position) {
    return {
        parameters: {
            mode: 'runOnceForAllItems',
            jsCode: await readFile(
                new URL(`nodes/${sourceFile}`, root),
                'utf8',
            ),
        },
        id,
        name,
        type: 'n8n-nodes-base.code',
        typeVersion: 2,
        position,
        notes: `Source: nodes/${sourceFile}`,
    };
}

function ifNode(name, id, leftValue, rightValue, type, position) {
    return {
        parameters: {
            conditions: {
                options: { caseSensitive: true, typeValidation: 'strict' },
                conditions: [
                    {
                        leftValue,
                        rightValue,
                        operator: { type, operation: 'equals' },
                    },
                ],
                combinator: 'and',
            },
        },
        id,
        name,
        type: 'n8n-nodes-base.if',
        typeVersion: 2.3,
        position,
    };
}

function edge(node, index = 0) {
    return { node, type: 'main', index };
}

const nodes = [
    {
        parameters: {},
        id: 'manual-sbc-catalog-v1',
        name: 'Run SBC Catalog Now',
        type: 'n8n-nodes-base.manualTrigger',
        typeVersion: 1,
        position: [180, 280],
    },
    {
        parameters: {
            rule: { interval: [{ field: 'hours', hoursInterval: 2 }] },
        },
        id: 'schedule-sbc-catalog-v1',
        name: 'Every 2 Hours',
        type: 'n8n-nodes-base.scheduleTrigger',
        typeVersion: 1.2,
        position: [180, 440],
    },
    await codeNode('Config', 'config-sbc-catalog-v1', 'config.js', [420, 360]),
    await codeNode(
        'Sign Pricing Read',
        'sign-pricing-read-sbc-v1',
        'sign-pricing-read.js',
        [660, 360],
    ),
    ifNode(
        'Pricing Read Signed?',
        'pricing-read-signed-sbc-v1',
        '={{ $json.pricingReadSigned }}',
        true,
        'boolean',
        [900, 360],
    ),
    {
        parameters: {
            method: 'GET',
            url: '={{ $("Config").first().json.settings.pricingEndpoint }}',
            authentication: 'genericCredentialType',
            genericAuthType: 'httpCustomAuth',
            sendHeaders: true,
            headerParameters: {
                parameters: [
                    { name: 'Accept', value: 'application/json' },
                    {
                        name: 'X-ArabUT-Timestamp',
                        value: '={{ $("Sign Pricing Read").first().json.timestamp }}',
                    },
                    {
                        name: 'X-ArabUT-Signature',
                        value: '={{ $("Sign Pricing Read").first().json.signature }}',
                    },
                ],
            },
            options: {
                response: {
                    response: {
                        fullResponse: true,
                        neverError: true,
                        responseFormat: 'json',
                    },
                },
            },
        },
        id: 'read-coins-bases-sbc-v1',
        name: 'Read Coins Bases',
        type: 'n8n-nodes-base.httpRequest',
        typeVersion: 4.4,
        position: [1140, 280],
        credentials: {
            httpCustomAuth: {
                id: 'CONFIGURE_ARABUT_SBC_PRICING_READ_CREDENTIAL_ID',
                name: 'ArabUT SBC Pricing Read API',
            },
        },
        onError: 'continueRegularOutput',
    },
    await codeNode(
        'Evaluate Pricing Read',
        'evaluate-pricing-read-sbc-v1',
        'evaluate-pricing-read.js',
        [1380, 280],
    ),
    ifNode(
        'Pricing Ready?',
        'pricing-ready-sbc-v1',
        '={{ $json.valid }}',
        true,
        'boolean',
        [1620, 280],
    ),
    {
        parameters: {
            method: 'GET',
            url: '={{ $("Config").first().json.settings.sourceEndpoint }}',
            sendHeaders: true,
            headerParameters: {
                parameters: [
                    { name: 'Accept', value: 'application/json' },
                    { name: 'User-Agent', value: 'ArabUT-SBC-Catalog/1.0' },
                ],
            },
            options: {
                response: {
                    response: { neverError: true, responseFormat: 'json' },
                },
            },
        },
        id: 'fetch-easysbc-sets-v1',
        name: 'Fetch EasySBC Sets',
        type: 'n8n-nodes-base.httpRequest',
        typeVersion: 4.4,
        position: [1860, 200],
        onError: 'continueRegularOutput',
    },
    await codeNode(
        'Prepare Translations',
        'prepare-translations-sbc-v1',
        'prepare-translations.js',
        [2100, 200],
    ),
    ifNode(
        'Translation Plan Valid?',
        'translation-plan-valid-sbc-v1',
        '={{ $json.translationPlanValid }}',
        true,
        'boolean',
        [2340, 200],
    ),
    ifNode(
        'Translation Ready?',
        'translation-ready-sbc-v1',
        '={{ $json.translationReady }}',
        true,
        'boolean',
        [2580, 200],
    ),
    {
        parameters: {
            promptType: 'define',
            text: '={{ $json.prompt }}',
        },
        id: 'translate-sbc-names-v1',
        name: 'Translate SBC Names',
        type: '@n8n/n8n-nodes-langchain.chainLlm',
        typeVersion: 1.5,
        position: [2820, 320],
        onError: 'continueRegularOutput',
    },
    {
        parameters: {
            options: { temperature: 0, maxOutputTokens: 8192 },
        },
        id: 'gemini-translation-model-sbc-v1',
        name: 'Gemini Translation Model',
        type: '@n8n/n8n-nodes-langchain.lmChatGoogleGemini',
        typeVersion: 1,
        position: [2820, 520],
        credentials: {
            googlePalmApi: {
                id: 'WgUWtkjmfC1iEIMi',
                name: 'Google Gemini(PaLM) Api account 2',
            },
        },
    },
    await codeNode(
        'Validate Translations',
        'validate-translations-sbc-v1',
        'validate-translations.js',
        [3060, 320],
    ),
    ifNode(
        'Translation Valid?',
        'translation-valid-sbc-v1',
        '={{ $json.translationReady }}',
        true,
        'boolean',
        [3300, 320],
    ),
    await codeNode(
        'Prepare SBC Snapshot',
        'prepare-sbc-snapshot-v1',
        'prepare-snapshot.js',
        [3540, 200],
    ),
    await codeNode(
        'Validate SBC Snapshot',
        'validate-sbc-snapshot-v1',
        'validate-snapshot.js',
        [2340, 200],
    ),
    ifNode(
        'Snapshot Valid?',
        'snapshot-valid-sbc-v1',
        '={{ $json.valid }}',
        true,
        'boolean',
        [2580, 200],
    ),
    ifNode(
        'Apply Mode?',
        'apply-mode-sbc-v1',
        '={{ $("Config").first().json.settings.mode }}',
        'apply',
        'string',
        [2820, 200],
    ),
    await codeNode(
        'Dry Run Summary',
        'dry-run-summary-sbc-v1',
        'dry-run-summary.js',
        [3060, 360],
    ),
    await codeNode(
        'Sign Catalog Snapshot',
        'sign-catalog-sbc-v1',
        'sign-catalog.js',
        [3060, 120],
    ),
    ifNode(
        'Catalog Signed?',
        'catalog-signed-sbc-v1',
        '={{ $json.catalogSigned }}',
        true,
        'boolean',
        [3300, 120],
    ),
    {
        parameters: {
            method: 'POST',
            url: '={{ $("Config").first().json.settings.catalogEndpoint }}',
            authentication: 'genericCredentialType',
            genericAuthType: 'httpCustomAuth',
            sendHeaders: true,
            headerParameters: {
                parameters: [
                    { name: 'Accept', value: 'application/json' },
                    { name: 'Content-Type', value: 'application/json' },
                    {
                        name: 'X-ArabUT-Timestamp',
                        value: '={{ $("Sign Catalog Snapshot").first().json.timestamp }}',
                    },
                    {
                        name: 'X-ArabUT-Event',
                        value: '={{ $("Sign Catalog Snapshot").first().json.event }}',
                    },
                    {
                        name: 'X-ArabUT-Signature',
                        value: '={{ $("Sign Catalog Snapshot").first().json.signature }}',
                    },
                ],
            },
            sendBody: true,
            specifyBody: 'json',
            jsonBody: '={{ $("Sign Catalog Snapshot").first().json.rawBody }}',
            options: {
                response: {
                    response: {
                        fullResponse: true,
                        neverError: true,
                        responseFormat: 'json',
                    },
                },
            },
        },
        id: 'publish-sbc-catalog-v1',
        name: 'Publish SBC Catalog',
        type: 'n8n-nodes-base.httpRequest',
        typeVersion: 4.4,
        position: [3540, 40],
        credentials: {
            httpCustomAuth: {
                id: 'CONFIGURE_ARABUT_SBC_CATALOG_CREDENTIAL_ID',
                name: 'ArabUT SBC Catalog API',
            },
        },
        onError: 'continueRegularOutput',
    },
    await codeNode(
        'Evaluate Publish Result',
        'evaluate-publish-sbc-v1',
        'evaluate-publish.js',
        [3780, 40],
    ),
    ifNode(
        'Publish OK?',
        'publish-ok-sbc-v1',
        '={{ $json.publishOk }}',
        true,
        'boolean',
        [4020, 40],
    ),
    await codeNode(
        'Success Summary',
        'success-summary-sbc-v1',
        'success-summary.js',
        [4260, -40],
    ),
    await codeNode(
        'Prepare Failure Alert',
        'failure-alert-sbc-v1',
        'failure-alert.js',
        [2820, 600],
    ),
    ifNode(
        'Alert Configured?',
        'alert-configured-sbc-v1',
        '={{ $json.alertEnabled }}',
        true,
        'boolean',
        [3060, 600],
    ),
    {
        parameters: {
            method: 'POST',
            url: 'https://gate.whapi.cloud/messages/text',
            authentication: 'genericCredentialType',
            genericAuthType: 'httpCustomAuth',
            sendBody: true,
            specifyBody: 'json',
            jsonBody:
                '={{ JSON.stringify({ to: $json.to, body: $json.body }) }}',
            options: {
                response: {
                    response: { neverError: true, responseFormat: 'json' },
                },
            },
        },
        id: 'whapi-failure-alert-sbc-v1',
        name: 'Whapi Failure Alert',
        type: 'n8n-nodes-base.httpRequest',
        typeVersion: 4.4,
        position: [3300, 520],
        credentials: {
            httpCustomAuth: {
                id: 'CONFIGURE_WHAPI_CREDENTIAL_ID',
                name: 'Whapi Alerts',
            },
        },
        onError: 'continueRegularOutput',
    },
    {
        parameters: {
            errorMessage:
                '={{ $("Prepare Failure Alert").first().json.failureReason || "SBC catalog workflow failed closed" }}',
        },
        id: 'stop-workflow-error-sbc-v1',
        name: 'Stop Workflow With Error',
        type: 'n8n-nodes-base.stopAndError',
        typeVersion: 1,
        position: [3540, 680],
    },
];

const connections = {
    'Run SBC Catalog Now': { main: [[edge('Config')]] },
    'Every 2 Hours': { main: [[edge('Config')]] },
    Config: { main: [[edge('Sign Pricing Read')]] },
    'Sign Pricing Read': { main: [[edge('Pricing Read Signed?')]] },
    'Pricing Read Signed?': {
        main: [[edge('Read Coins Bases')], [edge('Prepare Failure Alert')]],
    },
    'Read Coins Bases': { main: [[edge('Evaluate Pricing Read')]] },
    'Evaluate Pricing Read': { main: [[edge('Pricing Ready?')]] },
    'Pricing Ready?': {
        main: [[edge('Fetch EasySBC Sets')], [edge('Prepare Failure Alert')]],
    },
    'Fetch EasySBC Sets': { main: [[edge('Prepare Translations')]] },
    'Prepare Translations': { main: [[edge('Translation Plan Valid?')]] },
    'Translation Plan Valid?': {
        main: [[edge('Translation Ready?')], [edge('Prepare Failure Alert')]],
    },
    'Translation Ready?': {
        main: [[edge('Prepare SBC Snapshot')], [edge('Translate SBC Names')]],
    },
    'Translate SBC Names': { main: [[edge('Validate Translations')]] },
    'Gemini Translation Model': {
        ai_languageModel: [
            [
                {
                    node: 'Translate SBC Names',
                    type: 'ai_languageModel',
                    index: 0,
                },
            ],
        ],
    },
    'Validate Translations': { main: [[edge('Translation Valid?')]] },
    'Translation Valid?': {
        main: [[edge('Prepare SBC Snapshot')], [edge('Prepare Failure Alert')]],
    },
    'Prepare SBC Snapshot': { main: [[edge('Validate SBC Snapshot')]] },
    'Validate SBC Snapshot': { main: [[edge('Snapshot Valid?')]] },
    'Snapshot Valid?': {
        main: [[edge('Apply Mode?')], [edge('Prepare Failure Alert')]],
    },
    'Apply Mode?': {
        main: [[edge('Sign Catalog Snapshot')], [edge('Dry Run Summary')]],
    },
    'Sign Catalog Snapshot': { main: [[edge('Catalog Signed?')]] },
    'Catalog Signed?': {
        main: [[edge('Publish SBC Catalog')], [edge('Prepare Failure Alert')]],
    },
    'Publish SBC Catalog': { main: [[edge('Evaluate Publish Result')]] },
    'Evaluate Publish Result': { main: [[edge('Publish OK?')]] },
    'Publish OK?': {
        main: [[edge('Success Summary')], [edge('Prepare Failure Alert')]],
    },
    'Prepare Failure Alert': { main: [[edge('Alert Configured?')]] },
    'Alert Configured?': {
        main: [
            [edge('Whapi Failure Alert')],
            [edge('Stop Workflow With Error')],
        ],
    },
    'Whapi Failure Alert': { main: [[edge('Stop Workflow With Error')]] },
};

const workflow = {
    name: 'SBC Catalog v1 - Signed Laravel Snapshot',
    nodes,
    connections,
    active: false,
    settings: { executionOrder: 'v1', availableInMCP: false },
    versionId: 'SBC_CATALOG_V1_VERSION',
    meta: { templateCredsSetupCompleted: false },
    tags: [],
};
const serialized = `${JSON.stringify(workflow, null, 2)}\n`;

if (process.argv.includes('--check')) {
    assert.equal(
        await readFile(output, 'utf8'),
        serialized,
        'workflow.json is stale; run npm run build',
    );
} else {
    await writeFile(output, serialized, 'utf8');
}
