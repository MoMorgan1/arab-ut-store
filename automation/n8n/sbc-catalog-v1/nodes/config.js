/* eslint-disable */
// SBC Catalog v1 settings. Secrets remain in n8n environment and Credentials.

const alphabet = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

function ulid() {
    let time = Date.now();
    let timestamp = '';
    for (let index = 0; index < 10; index += 1) {
        timestamp = alphabet[time % 32] + timestamp;
        time = Math.floor(time / 32);
    }

    let random = '';
    for (let index = 0; index < 16; index += 1) {
        random += alphabet[Math.floor(Math.random() * alphabet.length)];
    }

    return timestamp + random;
}

function generatedAt() {
    return new Date().toISOString().replace(/\.(\d{3})Z$/, '.$1000Z');
}

return [
    {
        json: {
            settings: {
                mode: 'dry_run',
                pricingEndpoint:
                    'https://store.arab-ut.com/api/automation/v1/pricing/coins/sbc-bases',
                pricingPath: '/api/automation/v1/pricing/coins/sbc-bases',
                sourceEndpoint:
                    'https://api-fc26.easysbc.io/sbc-sets?page=1&limit=200',
                catalogEndpoint:
                    'https://store.arab-ut.com/api/automation/v1/catalog/sbc/snapshots',
                catalogSource: 'n8n-sbc',
                sourceMinCount: 20,
                sourceLimit: 200,
                minimumExpiryLeadSeconds: 7200,
                approvedBaseline: {
                    sourceCount: 56,
                    eligibleCount: 39,
                    approvedAt: '2026-08-12T12:00:00.000Z',
                    approvedBy: 'operator',
                },
            },
            eventId: ulid(),
            runId: ulid(),
            generatedAt: generatedAt(),
        },
    },
];
