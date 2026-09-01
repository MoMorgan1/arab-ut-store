import assert from 'node:assert/strict';
import { test } from 'node:test';

import {
    asBareBytes,
    asBuffer,
    asStream,
    env,
    fftRecords,
    httpOk,
    metaRecord,
    metaRecords,
    pipeline,
} from './helpers.mjs';

async function merge({ fft = fftRecords(), meta = metaRecords() } = {}) {
    const flow = pipeline({ env: env() });

    await flow.run('Config', 'config', [{}]);
    flow.set('Fetch FFT SBCs', httpOk(fft));
    flow.set('Fetch EasySBC Sets', httpOk(meta));
    await flow.run(
        'Merge Provider Sources',
        'merge-sources',
        flow.get('Fetch EasySBC Sets'),
    );

    return flow.json('Merge Provider Sources');
}

async function mergeError(options) {
    try {
        await merge(options);
    } catch (error) {
        return error.message;
    }

    return null;
}

test('a few unusable provider rows are counted, never fatal', async () => {
    // v3.2.6 threw on the first invalid EasySBC record. Three cosmetic rows out
    // of fifty-six stopped every price in the store.
    const audit = (await merge({ meta: metaRecords(120, 3) })).sourceAudit;

    assert.equal(audit.metadataInvalid, 3);
    assert.equal(audit.metadataUniqueUsable, 120);
    assert.equal(audit.identityMismatches, 0);
});

test('a genuinely broken feed still fails, by ratio', async () => {
    const message = await mergeError({ meta: metaRecords(120, 40) });

    assert.match(message, /25\.0% of parsed EasySBC records are invalid/);
});

test('EasySBC prices are not required, because FFT prices what it lists', async () => {
    // Production: id 1406 "Marcelo" carries psPrice ~948k and no pcPrice at all.
    // Requiring both discarded a sellable ~1M coin player SBC over a field the
    // merge never reads for records FFT covers.
    const meta = metaRecords(120);
    delete meta[0].pcPrice;
    meta[0].name = 'Marcelo';

    const fft = fftRecords();
    fft[0].sbcName = 'Marcelo';

    const merged = await merge({ fft, meta });
    const marcelo = merged.body.find(({ name }) => name === 'Marcelo');

    assert.ok(marcelo, 'the record must survive the merge');
    assert.equal(marcelo.source, 'fft');
    assert.ok(marcelo.psPrice > 0 && marcelo.pcPrice > 0);
    assert.equal(merged.sourceAudit.metadataInvalid, 0);
});

test('join integrity and FFT coverage are measured separately', async () => {
    // FFT is a coin-farming service: it structurally does not sell daily
    // freebies or OVR Token Swaps. Roughly a quarter of EasySBC is absent while
    // every shared id agrees perfectly. One ratio cannot express both facts,
    // and conflating them failed a healthy feed at 77.4%.
    const audit = (
        await merge({
            meta: metaRecords(53),
            fft: fftRecords().filter(({ setID }) => setID > 12),
        })
    ).sourceAudit;

    assert.equal(audit.joinIntegrity, 1);
    assert.equal(audit.missingFftCount, 12);
    assert.ok(
        Math.abs(audit.fftCoverage - 41 / 53) < 0.02,
        `coverage was ${audit.fftCoverage}`,
    );
});

test('a drifted id space is rejected even at full coverage', async () => {
    // Names are the only thing verifying FFT setID 412 and EasySBC id 412 are
    // the same challenge. Losing that means prices attach to the wrong product.
    const meta = metaRecords(120).map((record, index) =>
        index < 40 ? { ...record, name: `Completely Different ${index}` } : record,
    );

    assert.match(
        await mergeError({ meta }),
        /disagree with FFT on name or squad count/,
    );
});

test('cross-provider drift is tolerated below the ratio', async () => {
    const fft = fftRecords();

    for (let index = 0; index < 5; index += 1) {
        fft[index].sbcName = `Renamed Between Fetches ${index}`;
    }

    const audit = (await merge({ fft })).sourceAudit;

    assert.equal(audit.identityMismatches, 5);
    assert.equal(audit.identityMismatchIds.length, 5);
});

test('a provider HTTP error names the provider', async () => {
    const flow = pipeline({ env: env() });

    await flow.run('Config', 'config', [{}]);
    flow.set('Fetch FFT SBCs', {
        statusCode: 503,
        body: '<html>upstream down</html>',
    });
    flow.set('Fetch EasySBC Sets', httpOk(metaRecords()));

    await assert.rejects(
        () =>
            flow.run(
                'Merge Provider Sources',
                'merge-sources',
                flow.get('Fetch EasySBC Sets'),
            ),
        // Without this the failure reads "0 unique usable records", which sends
        // whoever is on call to inspect a provider that is answering fine.
        /Fetch FFT SBCs returned HTTP 503/,
    );
});

test('a Buffer-wrapped provider body is decoded, not walked as bytes', async () => {
    const flow = pipeline({ env: env() });

    await flow.run('Config', 'config', [{}]);
    flow.set('Fetch FFT SBCs', {
        statusCode: 200,
        body: asBuffer(fftRecords()),
    });
    flow.set('Fetch EasySBC Sets', {
        statusCode: 200,
        body: asBuffer(metaRecords()),
    });
    await flow.run(
        'Merge Provider Sources',
        'merge-sources',
        flow.get('Fetch EasySBC Sets'),
    );

    assert.ok(flow.json('Merge Provider Sources').sourceAudit.exactMatches > 100);
});

test('an envelope that looks like a record does not swallow its children', async () => {
    const audit = (
        await merge({
            fft: {
                id: 999_999,
                pcPrice: 1,
                consolePrice: 1,
                sets: fftRecords(),
            },
        })
    ).sourceAudit;

    assert.ok(
        audit.fftUniqueUsable >= 130,
        `harvested ${audit.fftUniqueUsable}`,
    );
});

test('a duplicate flood on the price authority is rejected', async () => {
    const fft = fftRecords();
    // Deep-cloned: a provider repeating a page sends distinct objects, and the
    // harvester dedupes by object identity before ids are ever compared.
    const repeated = fft.slice(0, 40).map((record) => ({ ...record }));

    assert.match(
        await mergeError({ fft: fft.concat(repeated) }),
        /duplicate ids/,
    );
});

test('an SBC absent from FFT is archived, not priced', async () => {
    const merged = await merge({
        meta: metaRecords(120).concat(metaRecord(500)),
    });
    const archived = merged.body.find(({ id }) => id === 500);

    assert.equal(archived.source, 'fft_missing');
    assert.equal(archived.active, false);
});

test('an untagged byte-array provider body is decoded, not walked as bytes', async () => {
    // Same defect as the publish response: undecoded, the harvester walks a
    // numeric array, finds no records, and blames the provider for "0 unique
    // usable records" while it was answering perfectly.
    const flow = pipeline({ env: env() });

    await flow.run('Config', 'config', [{}]);
    flow.set('Fetch FFT SBCs', {
        statusCode: 200,
        body: asBareBytes(fftRecords()),
    });
    flow.set('Fetch EasySBC Sets', {
        statusCode: 200,
        body: asBareBytes(metaRecords()),
    });
    await flow.run(
        'Merge Provider Sources',
        'merge-sources',
        flow.get('Fetch EasySBC Sets'),
    );

    assert.ok(
        flow.json('Merge Provider Sources').sourceAudit.exactMatches > 100,
    );
});

test('a provider list is never mistaken for encoded bytes', async () => {
    // The decoder must only claim an array when the bytes really decode to
    // JSON, or a legitimate provider response would be destroyed by the fix.
    const flow = pipeline({ env: env() });

    await flow.run('Config', 'config', [{}]);
    flow.set('Fetch FFT SBCs', { statusCode: 200, body: fftRecords() });
    flow.set('Fetch EasySBC Sets', { statusCode: 200, body: metaRecords() });
    await flow.run(
        'Merge Provider Sources',
        'merge-sources',
        flow.get('Fetch EasySBC Sets'),
    );

    assert.ok(
        flow.json('Merge Provider Sources').sourceAudit.exactMatches > 100,
    );
});

test('a provider body delivered as an unread stream is decoded', async () => {
    // The shape that broke the publish leg. A provider fetch can arrive the
    // same way, and undecoded the harvester reports "0 unique usable records".
    const flow = pipeline({ env: env() });

    await flow.run('Config', 'config', [{}]);
    flow.set('Fetch FFT SBCs', {
        statusCode: 200,
        body: asStream(fftRecords()),
    });
    flow.set('Fetch EasySBC Sets', {
        statusCode: 200,
        body: asStream(metaRecords()),
    });
    await flow.run(
        'Merge Provider Sources',
        'merge-sources',
        flow.get('Fetch EasySBC Sets'),
    );

    assert.ok(
        flow.json('Merge Provider Sources').sourceAudit.exactMatches > 100,
    );
});

test('a provider failure reports the body as text, not as raw bytes', async () => {
    // The FFT outage message read "body starts: null" while the real body sat
    // undecoded. A wall of integers is what cost two debugging rounds here.
    const flow = pipeline({ env: env() });

    await flow.run('Config', 'config', [{}]);
    flow.set('Fetch FFT SBCs', {
        statusCode: 500,
        body: asBareBytes({ error: 'Internal Server Error' }),
    });
    flow.set('Fetch EasySBC Sets', { statusCode: 200, body: metaRecords() });

    await assert.rejects(
        () =>
            flow.run(
                'Merge Provider Sources',
                'merge-sources',
                flow.get('Fetch EasySBC Sets'),
            ),
        /returned HTTP 500[\s\S]*Internal Server Error/,
    );
});
