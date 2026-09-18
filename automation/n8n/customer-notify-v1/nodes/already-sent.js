/* eslint-disable */
// The second line of defence against sending one message twice, and only the
// second.
//
// The first is the store's: one delivery row per transition, a unique index on
// its idempotency key, and a row that is marked `sent` before the event is
// marked processed. `Customer Notifier v2` had this check and nothing else,
// which is exactly why it was not enough - $getWorkflowStaticData does not
// survive an n8n restart, so a restart meant a customer read the same message
// twice. What survives here is the store's database.
//
// So this is a cheap guard against the one case the store cannot see: it sent,
// Whapi accepted, and our answer never arrived - a timeout or a dropped
// connection - so the store retries a message that already went out. That
// retry carries the same key, and this catches it.
//
// The memory is bounded. Keys are kept newest-first and cut at MAX_KEYS, which
// at a handful of messages a day is months of history in a few kilobytes.
const MAX_KEYS = 2000;

const item = $input.first().json;
const key = String(item.idempotencyKey);

const memory = $getWorkflowStaticData('global');
if (!Array.isArray(memory.sentKeys)) {
    memory.sentKeys = [];
}

const alreadySent = memory.sentKeys.includes(key);

return [
    {
        json: {
            ...item,
            alreadySent,
            // Read by Record Result, which is the node allowed to write the
            // key - a message is remembered when it is sent, never when it is
            // merely considered.
            memoryLimit: MAX_KEYS,
        },
    },
];
