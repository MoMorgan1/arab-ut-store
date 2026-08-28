/* eslint-disable */
// SBC Catalog v4.0 — direct apply.
// Every failure in this workflow THROWS. The n8n Error Workflow catches it and
// sends the Telegram alert, so there is no in-flow failure rail to maintain.

const ALPHABET = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

function ulid() {
    let time = Date.now();
    let timestamp = '';
    for (let index = 0; index < 10; index += 1) {
        timestamp = ALPHABET[time % 32] + timestamp;
        time = Math.floor(time / 32);
    }
    let random = '';
    for (let index = 0; index < 16; index += 1) {
        random += ALPHABET[Math.floor(Math.random() * ALPHABET.length)];
    }
    return timestamp + random;
}

// Laravel requires microsecond precision on generatedAt.
function generatedAt() {
    return new Date().toISOString().replace(/\.(\d{3})Z$/, '.$1000Z');
}

// The three triggers are distinguishable by the shape of what they emit, so
// they wire straight into this node without a Set node each.
function detectTriggerSource(json) {
    if (json && (json.headers || json.body || json.query)) return 'webhook';
    if (json && (json.timestamp || json['Readable date'])) return 'schedule';
    return 'manual';
}

const triggerSource = detectTriggerSource($input.first().json ?? {});

// Fail at the very first node, with a clear message, rather than letting an
// HTTP node send empty credentials and reporting it as a provider problem 6
// nodes later. The v3 export carried the FFT key in plaintext in the request
// body; it now lives in n8n environment variables.
const requiredEnv = [
    'FFT_API_USER',
    'FFT_API_KEY',
    'N8N_SBC_PRICING_READ_SECRET',
    'N8N_SBC_CATALOG_SECRET',
];
const missingEnv = requiredEnv.filter((name) => !$env[name]);
if (missingEnv.length) {
    throw new Error(
        `[config] missing n8n environment variable(s): ${missingEnv.join(', ')}`,
    );
}

return [
    {
        json: {
            settings: {
                mode: 'apply',

                pricingEndpoint:
                    'https://store.arab-ut.com/api/automation/v1/pricing/coins/sbc-bases',
                pricingPath: '/api/automation/v1/pricing/coins/sbc-bases',
                sourceEndpoint:
                    'https://api-fc26.easysbc.io/sbc-sets?page=1&limit=200',
                catalogEndpoint:
                    'https://store.arab-ut.com/api/automation/v1/catalog/sbc/snapshots',
                catalogSource: 'n8n-sbc',

                // Every source threshold lives here. v3 scattered these between Config
                // and hardcoded constants inside three separate Code nodes, where they
                // drifted out of agreement with each other.
                source: {
                    minUniqueFftRecords: 100,
                    minUniqueMetadataRecords: 20,
                    minMatchedRecords: 20,
                    metadataLimit: 200,
                    // Split from the old single minMatchRate, which asked one number to
                    // answer two questions and failed a healthy feed at 77.4%.
                    // Join integrity: of the SBCs BOTH providers list, how many agree on
                    // name and squad count. This is the safety property -- it verifies the
                    // two id spaces still mean the same thing. Should be ~100%.
                    minJoinIntegrity: 0.85,
                    // FFT coverage: what share of EasySBC's catalog FFT sells at all.
                    // Structurally well under 100% -- FFT does not sell daily freebies or
                    // OVR Token Swaps, which are not bought with coins. Loose on purpose:
                    // it catches FFT's feed collapsing, not the normal overlap gap.
                    minFftCoverage: 0.5,
                    // Symmetric tolerances. v3 allowed 10% invalid FFT records but zero
                    // invalid EasySBC records, so three cosmetic metadata rows took the
                    // whole catalog down.
                    maxInvalidFftRatio: 0.1,
                    maxInvalidMetadataRatio: 0.1,
                    maxMismatchRatio: 0.1,
                },

                sourceMinCount: 20,
                sourceLimit: 200,
                bootstrapMinimumEligibleCount: 20,
                minimumExpiryLeadSeconds: 7200,

                eligibility: {
                    minConsoleCoins: 1500,
                    minNonRepeatableConsoleCoins: 20000,
                    // Double backslash is REQUIRED: this is a JS string that becomes a
                    // RegExp, so '\b' would be a backspace character (U+0008) and the
                    // filter would silently match nothing. Build & Price Snapshot
                    // canary-tests this pattern before using it.
                    excludedNamePattern: '\\b(?:bronze|silver)\\b',
                },

                pricingPolicy: {
                    formulaVersion: 'fft-plus-owner-buffer-v2',
                    ownerCoinBufferBps: 500,
                    automationCostPerSquadMinor: 37.5,
                    nonRepeatServiceMarginPerSquadMinor: 60,
                    fixedOrderFeeMinor: 300,
                    minimumPriceMinor: 600,
                    commercialAdjustmentBps: 10000,
                    platformAdjustmentBps: {
                        playstation: 10000,
                        pc: 10000,
                    },
                    repeatServiceMarginPerRunMinor: {
                        1: 125,
                        2: 90,
                        3: 75,
                        5: 60,
                        10: 40,
                        15: 32,
                        20: 28,
                        30: 23,
                        40: 20,
                        50: 18,
                        75: 14,
                        100: 12,
                    },
                },
            },
            eventId: ulid(),
            runId: ulid(),
            generatedAt: generatedAt(),
            triggerSource,
        },
    },
];
