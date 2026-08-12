import { readFile } from 'node:fs/promises';

const root = new URL('../', import.meta.url);

export async function nodeSource(name) {
    return readFile(new URL(`nodes/${name}.js`, root), 'utf8');
}

export async function runNode(
    name,
    {
        named = {},
        items = [],
        env = {},
        staticData = {},
        now = '2026-08-12T12:00:00.000Z',
    } = {},
) {
    const source = await nodeSource(name);
    const lookup = (nodeName) => ({
        first: () => ({ json: named[nodeName] }),
        all: () => [{ json: named[nodeName] }],
    });
    const input = {
        first: () => ({ json: items[0] ?? {} }),
        all: () => items.map((json) => ({ json })),
    };
    class FixedDate extends Date {
        constructor(value) {
            super(value ?? now);
        }

        static now() {
            return new Date(now).getTime();
        }
    }

    const runner = new Function(
        '$',
        '$input',
        '$env',
        '$getWorkflowStaticData',
        'require',
        'Date',
        source,
    );

    return runner(
        lookup,
        input,
        env,
        () => staticData,
        await import('node:module').then(({ createRequire }) =>
            createRequire(import.meta.url),
        ),
        FixedDate,
    );
}

export function config(overrides = {}) {
    return {
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
                sourceCount: 20,
                eligibleCount: 1,
                approvedAt: '2026-08-12T12:00:00.000Z',
                approvedBy: 'operator',
            },
            ...overrides,
        },
        eventId: '01K2EXAMPLE000000000000001',
        runId: '01K2EXAMPLE000000000000002',
        generatedAt: '2026-08-12T12:00:00.000000Z',
    };
}

export function pricingRead(overrides = {}) {
    return {
        schemaVersion: 1,
        pricingVersion: 7,
        pricedAt: '2026-08-12T12:00:00+00:00',
        quotes: {
            playstation_fast: {
                platform: 'playstation',
                delivery: 'fast',
                quantity: 1_000_000,
                totalHalalah: 100_000,
            },
            pc: {
                platform: 'pc',
                delivery: null,
                quantity: 1_000_000,
                totalHalalah: 120_000,
            },
        },
        ...overrides,
    };
}

export function sourceRecord(index, overrides = {}) {
    const id = 1000 + index;

    return {
        id,
        name: `Player Challenge ${index}`,
        slug: `player-challenge-${index}`,
        categoryId: 1,
        description: `Complete Player Challenge ${index}.`,
        sbcsCount: 3,
        repeatable: false,
        repeatabilityMode: 'NON_REPEATABLE',
        startTime: 1_786_420_800,
        endTime: 1_787_112_000,
        imageURL: `https://assets.easysbc.io/fc26/sbcs/sets/icons/${id}.png`,
        active: true,
        psPrice: 100_000,
        pcPrice: 120_000,
        ...overrides,
    };
}

export function sourceRecords(count = 20, recordOverrides = {}) {
    return Array.from({ length: count }, (_, index) =>
        sourceRecord(index, recordOverrides),
    );
}

export function translations(records) {
    return Object.fromEntries(
        records.map((record, index) => [
            `${record.id}\u0000${record.name}`,
            {
                sourceName: record.name,
                nameAr: `تحدي اللاعب ${index + 1}`,
            },
        ]),
    );
}
