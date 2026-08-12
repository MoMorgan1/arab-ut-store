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

const approvedEligibleItems = [
    ['1340', 'Ayden Heaven', '2026-08-18T17:00:00.000Z'],
    ['1351', '1 of 3 85+ Player Pick', '2026-08-17T17:00:00.000Z'],
    [
        '1348',
        '1 of 4 95+ FOF or FUTTIES T1-T3 Player Pick',
        '2026-08-18T17:00:00.000Z',
    ],
    ['1344', '7x 87+ Upgrade', '2026-08-18T17:00:00.000Z'],
    ['1339', 'Patrick Kluivert', '2026-08-24T17:00:00.000Z'],
    ['1337', '4 of 10 84+ Player Pick', '2026-08-17T17:00:00.000Z'],
    ['1338', 'Marcos Llorente', '2026-08-23T17:00:00.000Z'],
    [
        '1328',
        '94+ GOTG & FUTTIES Team 1 & 2 (Icons & Heroes) Upgrade',
        '2026-08-23T17:00:00.000Z',
    ],
    ['1327', 'Striker Instinct EVO', '2026-08-16T17:00:00.000Z'],
    ['1335', 'Antonio Rüdiger', '2026-08-22T17:00:00.000Z'],
    ['1329', 'Debinha', '2026-08-15T17:00:00.000Z'],
    ['1333', '10x 85+ Upgrade', '2026-08-15T17:00:00.000Z'],
    ['1325', 'Jérémy Doku', '2026-08-28T17:00:00.000Z'],
    [
        '1326',
        '1 of 3 95+ FOF or FUTTIES T1 & T2 Player Pick',
        '2026-08-14T17:00:00.000Z',
    ],
    ['1332', '2x 85+ Upgrade', '2026-08-21T17:00:00.000Z'],
    ['1324', 'Saeed Al-Owairan', '2026-08-14T17:00:00.000Z'],
    ['1334', '2 of 3 86+ Player Pick', '2026-08-14T17:00:00.000Z'],
    ['1309', 'Moussa Sissoko', '2026-08-13T17:00:00.000Z'],
    [
        '1321',
        'Repeatable FUTTIES Provisions Upgrade',
        '2026-08-13T17:00:00.000Z',
    ],
    ['1320', '3x 87-90 Upgrade', '2026-08-13T17:00:00.000Z'],
    ['1316', '1 of 3 FUTTIES Team 2 Player Pick', '2026-08-12T17:00:00.000Z'],
    ['1319', 'Eusébio', '2026-08-23T17:00:00.000Z'],
    [
        '1298',
        '1 of 3 94+ GOTG or FUTTIES Icon or Hero Player Pick',
        '2026-08-16T17:00:00.000Z',
    ],
    ['1302', 'Fernando Torres', '2026-08-21T17:00:00.000Z'],
    ['1306', 'Frenkie de Jong', '2026-08-21T17:00:00.000Z'],
    ['1310', '10x 84+ Upgrade', '2026-08-14T17:00:00.000Z'],
    ['1274', 'Antonio Di Natale', '2026-08-14T17:00:00.000Z'],
    ['1261', '5x 80+ Upgrade', '2026-08-21T17:00:00.000Z'],
    ['1248', 'Johan Cruyff', '2026-08-13T17:00:00.000Z'],
    ['1237', '89 OVR Token Swap', '2026-08-13T17:00:00.000Z'],
    ['1236', '88 OVR Token Swap', '2026-08-13T17:00:00.000Z'],
    ['1231', '83 OVR Token Swap', '2026-08-13T17:00:00.000Z'],
    ['1235', '87 OVR Token Swap', '2026-08-13T17:00:00.000Z'],
    ['1238', '90 OVR Token Swap', '2026-08-13T17:00:00.000Z'],
    ['1234', '86 OVR Token Swap', '2026-08-13T17:00:00.000Z'],
    ['1232', '84 OVR Token Swap', '2026-08-13T17:00:00.000Z'],
    ['1233', '85 OVR Token Swap', '2026-08-13T17:00:00.000Z'],
    ['1239', '91 OVR Token Swap', '2026-08-13T17:00:00.000Z'],
    ['7', 'Gold Upgrade', '2035-07-30T17:00:00.000Z'],
].map(([sourceId, sourceName, expiresAt]) => ({
    sourceId,
    sourceName,
    expiresAt,
}));

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
                    observedAt: '2026-08-12T05:46:36.701Z',
                    approvedAt: '2026-08-12T05:46:36.701Z',
                    approvedBy: 'operator',
                    eligibleItems: approvedEligibleItems,
                },
            },
            eventId: ulid(),
            runId: ulid(),
            generatedAt: generatedAt(),
        },
    },
];
