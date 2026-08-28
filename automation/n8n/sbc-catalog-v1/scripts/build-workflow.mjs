import assert from 'node:assert/strict';
import { readFile, writeFile } from 'node:fs/promises';
import process from 'node:process';

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

// Every fetch runs neverError + fullResponse so the status code reaches the
// next Code node as data. That node turns a bad status into a thrown error,
// which the Error Workflow reports. See README "Failure model".
function httpResponseOptions(timeout) {
    return {
        response: {
            response: {
                fullResponse: true,
                neverError: true,
                responseFormat: 'json',
            },
        },
        timeout,
    };
}

const nodes = [
    {
        parameters: {},
        id: 'manual-sbc-catalog-v1',
        name: 'Run SBC Catalog Now',
        type: 'n8n-nodes-base.manualTrigger',
        typeVersion: 1,
        position: [180, 120],
    },
    {
        parameters: {
            rule: { interval: [{ field: 'hours', hoursInterval: 2 }] },
        },
        id: 'schedule-sbc-catalog-v1',
        name: 'Every 2 Hours',
        type: 'n8n-nodes-base.scheduleTrigger',
        typeVersion: 1.2,
        position: [180, 280],
    },
    {
        parameters: {
            httpMethod: 'POST',
            path: 'arabut-sbc-catalog-v1/run',
            authentication: 'headerAuth',
            responseMode: 'lastNode',
            options: {},
        },
        id: 'webhook-sbc-catalog-v1',
        name: 'SBC Catalog Production Trigger',
        type: 'n8n-nodes-base.webhook',
        typeVersion: 2.1,
        position: [180, 440],
        webhookId: '07b2a529-b860-4517-a03c-c72854a423ca',
        credentials: {
            httpHeaderAuth: {
                id: 'CONFIGURE_ARABUT_SBC_BOOTSTRAP_TRIGGER_CREDENTIAL_ID',
                name: 'ArabUT SBC Bootstrap Trigger',
            },
        },
    },
    // v3 needed a Set node per trigger to stamp triggerSource. Config now infers
    // it from the shape each trigger emits, so those two nodes are gone.
    await codeNode('Config', 'config-sbc-catalog-v1', 'config.js', [420, 280]),
    await codeNode(
        'Sign Pricing Read',
        'sign-pricing-read-sbc-v1',
        'sign-pricing-read.js',
        [660, 280],
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
                        value: '={{ $json.timestamp }}',
                    },
                    {
                        name: 'X-ArabUT-Signature',
                        value: '={{ $json.signature }}',
                    },
                ],
            },
            options: httpResponseOptions(15000),
        },
        id: 'read-coins-bases-sbc-v1',
        name: 'Read Coins Bases',
        type: 'n8n-nodes-base.httpRequest',
        typeVersion: 4.4,
        position: [900, 280],
        retryOnFail: true,
        maxTries: 3,
        waitBetweenTries: 1000,
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
        [1140, 280],
    ),
    {
        parameters: {
            method: 'POST',
            url: 'https://futtransfer.top/availableSBCsAPI',
            sendBody: true,
            bodyParameters: {
                parameters: [
                    { name: 'apiUser', value: '={{ $env.FFT_API_USER }}' },
                    { name: 'apiKey', value: '={{ $env.FFT_API_KEY }}' },
                ],
            },
            options: httpResponseOptions(20000),
        },
        id: 'fetch-fft-sbcs-sbc-v1',
        name: 'Fetch FFT SBCs',
        type: 'n8n-nodes-base.httpRequest',
        typeVersion: 4.4,
        position: [1380, 280],
        executeOnce: true,
        retryOnFail: true,
        maxTries: 3,
        waitBetweenTries: 1500,
        onError: 'continueRegularOutput',
        notes:
            'Credentials come from the FFT_API_USER / FFT_API_KEY environment ' +
            'variables. Config fails the run at the first node if either is ' +
            'unset. Never inline the key here: workflow exports get committed.',
    },
    {
        parameters: {
            method: 'GET',
            url: '={{ $("Config").first().json.settings.sourceEndpoint }}',
            sendHeaders: true,
            headerParameters: {
                parameters: [
                    { name: 'Accept', value: 'application/json' },
                    { name: 'User-Agent', value: 'ArabUT-SBC-Catalog/4.0' },
                ],
            },
            options: httpResponseOptions(20000),
        },
        id: 'fetch-easysbc-sets-v1',
        name: 'Fetch EasySBC Sets',
        type: 'n8n-nodes-base.httpRequest',
        typeVersion: 4.4,
        position: [1620, 280],
        executeOnce: true,
        retryOnFail: true,
        maxTries: 3,
        waitBetweenTries: 1500,
        onError: 'continueRegularOutput',
    },
    await codeNode(
        'Merge Provider Sources',
        'merge-sources-sbc-v1',
        'merge-sources.js',
        [1860, 280],
    ),
    await codeNode(
        'Plan Translations',
        'plan-translations-sbc-v1',
        'plan-translations.js',
        [2100, 280],
    ),
    {
        parameters: {
            conditions: {
                options: {
                    caseSensitive: true,
                    leftValue: '',
                    typeValidation: 'strict',
                    version: 2,
                },
                conditions: [
                    {
                        id: 'needs-translation',
                        leftValue: '={{ $json.translationReady }}',
                        rightValue: false,
                        operator: {
                            type: 'boolean',
                            operation: 'false',
                            singleValue: true,
                        },
                    },
                ],
                combinator: 'and',
            },
            options: {},
        },
        id: 'needs-translation-sbc-v1',
        name: 'Needs Translation?',
        type: 'n8n-nodes-base.if',
        typeVersion: 2.2,
        position: [2340, 280],
        notes:
            'The only routing decision left in the workflow. Every other ' +
            'branch in v3 existed to reach the failure rail; those nodes now ' +
            'throw instead. True = names are missing, ask the model.',
    },
    {
        parameters: {
            promptType: 'define',
            text: '={{ $json.prompt }}',
            options: {},
        },
        id: 'translate-sbc-names-v1',
        name: 'Translate SBC Names',
        type: '@n8n/n8n-nodes-langchain.chainLlm',
        typeVersion: 1.5,
        position: [2580, 160],
        retryOnFail: true,
        maxTries: 2,
        waitBetweenTries: 2000,
    },
    {
        parameters: {
            model: {
                __rl: true,
                value: 'openai/gpt-5.6-luna',
                mode: 'list',
                cachedResultName: 'openai/gpt-5.6-luna',
            },
            builtInTools: {},
            options: {},
        },
        id: 'openai-translation-model-sbc-v1',
        name: 'OpenAI Chat Model',
        type: '@n8n/n8n-nodes-langchain.lmChatOpenAi',
        typeVersion: 1.3,
        position: [2580, 360],
        credentials: {
            openAiApi: {
                id: 'CONFIGURE_ARABUT_OPENAI_CREDENTIAL_ID',
                name: 'OpenAi account 2',
            },
        },
    },
    await codeNode(
        'Validate Translations',
        'validate-translations-sbc-v1',
        'validate-translations.js',
        [2820, 160],
    ),
    await codeNode(
        'Build & Price Snapshot',
        'build-and-price-sbc-v1',
        'build-and-price.js',
        [3060, 280],
    ),
    await codeNode(
        'Validate Snapshot',
        'validate-snapshot-sbc-v1',
        'validate-snapshot.js',
        [3300, 280],
    ),
    await codeNode(
        'Sign Catalog Snapshot',
        'sign-catalog-sbc-v1',
        'sign-catalog.js',
        [3540, 280],
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
                    {
                        name: 'X-ArabUT-Timestamp',
                        value: '={{ $json.timestamp }}',
                    },
                    { name: 'X-ArabUT-Event', value: '={{ $json.event }}' },
                    {
                        name: 'X-ArabUT-Signature',
                        value: '={{ $json.signature }}',
                    },
                ],
            },
            sendBody: true,
            contentType: 'raw',
            rawContentType: 'application/json',
            // Sent raw so the exact bytes that were HMAC'd are the exact bytes
            // on the wire. v3 passed this string to jsonBody, where any
            // re-parse and re-serialise by n8n would break the signature.
            body: '={{ $json.rawBody }}',
            options: httpResponseOptions(30000),
        },
        id: 'publish-sbc-catalog-v1',
        name: 'Publish SBC Catalog',
        type: 'n8n-nodes-base.httpRequest',
        typeVersion: 4.4,
        position: [3780, 280],
        retryOnFail: true,
        maxTries: 3,
        waitBetweenTries: 1500,
        credentials: {
            httpCustomAuth: {
                id: 'CONFIGURE_ARABUT_SBC_CATALOG_CREDENTIAL_ID',
                name: 'ArabUT SBC Catalog API',
            },
        },
        onError: 'continueRegularOutput',
    },
    await codeNode('Finish Run', 'finish-run-sbc-v1', 'finish-run.js', [
        4020, 280,
    ]),
];

const connections = {
    'Run SBC Catalog Now': { main: [[edge('Config')]] },
    'Every 2 Hours': { main: [[edge('Config')]] },
    'SBC Catalog Production Trigger': { main: [[edge('Config')]] },
    Config: { main: [[edge('Sign Pricing Read')]] },
    'Sign Pricing Read': { main: [[edge('Read Coins Bases')]] },
    'Read Coins Bases': { main: [[edge('Evaluate Pricing Read')]] },
    'Evaluate Pricing Read': { main: [[edge('Fetch FFT SBCs')]] },
    'Fetch FFT SBCs': { main: [[edge('Fetch EasySBC Sets')]] },
    'Fetch EasySBC Sets': { main: [[edge('Merge Provider Sources')]] },
    'Merge Provider Sources': { main: [[edge('Plan Translations')]] },
    'Plan Translations': { main: [[edge('Needs Translation?')]] },
    // true  -> translations are missing, ask the model
    // false -> every name is cached, go straight to the snapshot
    'Needs Translation?': {
        main: [[edge('Translate SBC Names')], [edge('Build & Price Snapshot')]],
    },
    'Translate SBC Names': { main: [[edge('Validate Translations')]] },
    'OpenAI Chat Model': {
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
    'Validate Translations': { main: [[edge('Build & Price Snapshot')]] },
    'Build & Price Snapshot': { main: [[edge('Validate Snapshot')]] },
    'Validate Snapshot': { main: [[edge('Sign Catalog Snapshot')]] },
    'Sign Catalog Snapshot': { main: [[edge('Publish SBC Catalog')]] },
    'Publish SBC Catalog': { main: [[edge('Finish Run')]] },
};

const workflow = {
    name: 'SBC Catalog v4 - FFT Authority, Signed Laravel Snapshot',
    nodes,
    connections,
    active: false,
    settings: {
        executionOrder: 'v1',
        availableInMCP: false,
        executionTimeout: 900,
        saveDataErrorExecution: 'all',
        saveDataSuccessExecution: 'all',
        saveExecutionProgress: false,
        // errorWorkflow is intentionally absent: it holds an n8n-instance
        // specific id. Set it by hand to the SBC Catalog - Failure Alert
        // workflow, or nothing alerts. See README "Failure model".
    },
    versionId: 'SBC_CATALOG_V4_VERSION',
    meta: { templateCredsSetupCompleted: false },
    tags: [],
};

// The whole failure rail lives here now: 4 in-flow nodes plus 10 IF gates in v3
// collapsed into one Error Workflow that catches every throw, including the
// ones v3 could not see (timeouts, out-of-memory, and the two largest Code
// nodes, which had no onError and bypassed the rail entirely).
const errorWorkflow = {
    name: 'SBC Catalog - Failure Alert',
    nodes: [
        {
            parameters: {},
            id: 'error-trigger-sbc-v4',
            name: 'On Workflow Error',
            type: 'n8n-nodes-base.errorTrigger',
            typeVersion: 1,
            position: [180, 280],
            notes: 'Set Workflow Settings -> Error Workflow to point here.',
        },
        {
            parameters: {
                mode: 'runOnceForAllItems',
                jsCode: await readFile(
                    new URL('nodes/failure-alert.js', root),
                    'utf8',
                ),
            },
            id: 'build-failure-alert-sbc-v4',
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
            id: 'telegram-failure-alert-sbc-v4',
            name: 'Telegram Failure Alert',
            type: 'n8n-nodes-base.telegram',
            typeVersion: 1.2,
            position: [660, 280],
            webhookId: '81b52ab2-6979-4a02-a05f-8a47b8b67e5d',
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
    versionId: 'SBC_CATALOG_FAILURE_ALERT_VERSION',
    meta: { templateCredsSetupCompleted: false },
    tags: [],
};

const serialized = `${JSON.stringify(workflow, null, 2)}\n`;
const errorSerialized = `${JSON.stringify(errorWorkflow, null, 2)}\n`;

if (process.argv.includes('--check')) {
    assert.equal(
        await readFile(output, 'utf8'),
        serialized,
        'workflow.json is stale; run npm run build',
    );
    assert.equal(
        await readFile(errorOutput, 'utf8'),
        errorSerialized,
        'error-workflow.json is stale; run npm run build',
    );
} else {
    await writeFile(output, serialized, 'utf8');
    await writeFile(errorOutput, errorSerialized, 'utf8');
}
