/* eslint-disable */
const response = $input.first().json;
const signed = $('Sign Catalog Snapshot').first().json;
const submitted = signed.catalogSnapshot;
const statusCode = Number(response.statusCode || 0);
let body = response.body ?? response;

if (typeof body === 'string') {
    try {
        body = JSON.parse(body);
    } catch {}
}

const completed =
    statusCode === 201 &&
    body?.data?.runId === submitted.runId &&
    body?.data?.status === 'completed' &&
    Number.isInteger(body?.data?.applied) &&
    Number.isInteger(body?.data?.archived);
const replayed =
    statusCode === 409 && body?.error?.code === 'catalog_snapshot_replayed';
const publishOk = completed || replayed;

return [
    {
        json: {
            publishOk,
            replayed,
            failureReason: publishOk
                ? null
                : `Laravel did not confirm the SBC catalog snapshot (HTTP ${statusCode || 'unknown'})`,
            publishResponse: body,
            catalogSnapshot: submitted,
            sourceCount: signed.sourceCount,
            eligibleCount: signed.eligibleCount,
        },
    },
];
