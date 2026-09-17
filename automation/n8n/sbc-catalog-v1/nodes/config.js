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
                    'https://api-fc27.easysbc.io/sbc-sets?page=1&limit=200',
                catalogEndpoint:
                    'https://store.arab-ut.com/api/automation/v1/catalog/sbc/snapshots',
                catalogSource: 'n8n-sbc',

                // A challenge earns its place on its own, not by the size of the
                // batch it arrived in. It is published when both providers list
                // it, they agree on its name and squad count, and FFT prices it
                // above zero; anything else is skipped and counted in the audit.
                // Owner decision 2026-09-16: the batch floors and ratios this
                // block used to hold froze the catalogue for two days at the
                // FC26 -> FC27 turn, while the store went on selling last
                // season's challenges.
                source: {
                    metadataLimit: 200,
                },

                sourceLimit: 200,
                minimumExpiryLeadSeconds: 7200,

                eligibility: {
                    // No coin floor. Owner decision 2026-09-17: every
                    // challenge a supplier will actually solve belongs on the
                    // storefront, whatever it costs. The floors that used to
                    // live here threw away the only two sellable FC27
                    // challenges - 2,332 and 18,815 coins against a 20,000
                    // minimum - and left the catalogue showing one badly
                    // priced set and nothing else.
                    //
                    // How far the two providers may disagree before the price
                    // is treated as a typo rather than a price. FFT is always
                    // the cheaper of the two in practice - it builds the squad
                    // better than the open market - and the observed spread on
                    // 2026-09-17 was 0.14x to 0.76x, so these bounds are wide
                    // enough to never touch a real listing. They exist for
                    // FFT's 100,700,000-coin Gold Upgrade, a set EasySBC
                    // prices at 8,200: a data-entry error that reached the
                    // storefront as a 6,458 SAR product.
                    maxProviderPriceRatio: 10,
                    // Lowered from 0.02 on 2026-09-17 with the basis move:
                    // FFT cheaper than the market is upside now, not a fault,
                    // and Intro to SBCs sits at 0.037 - close enough to the old
                    // bound to lose a profitable product to a rounding.
                    minProviderPriceRatio: 0.005,
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
