/* eslint-disable */
// What Whapi answered, turned into the one field the store reads.
//
// The store's contract is narrow and deliberate: `acknowledged: true` means
// Whapi accepted the message, and nothing else does. A dead number, a Whapi
// outage, a timeout - all of them are `acknowledged: false`, which sends the
// delivery row back to `queued` on the store's own backoff. After ten attempts
// the store retires it and it surfaces on the admin queue-health panel. That
// is the designed path for a number nobody answers: it is a fact about the
// customer, not an incident, so it does not page anybody at 3am.
//
// The Send node therefore runs with onError: continueRegularOutput. A throw
// here would reach the Error Workflow and alert on every wrong number, ten
// times each. Verification still throws - a bad signature is an incident.

const sent = $('Already Sent?').first().json;
const response = $input.first().json ?? {};

// n8n puts the HTTP status under $json.error when the request failed with
// "never error" on, and under statusCode when "Full Response" is on. Read
// both, and treat an unreadable answer as a failure rather than a success.
const error = response.error ?? null;
const statusCode = Number(
    response.statusCode ?? error?.statusCode ?? error?.status ?? (error ? 0 : 200),
);
const accepted = Number.isFinite(statusCode) && statusCode >= 200 && statusCode < 300 && !error;

if (accepted) {
    // Remembered only now, and only here. A key written before the send would
    // make a failed attempt look like a delivered message on the retry.
    const memory = $getWorkflowStaticData('global');
    if (!Array.isArray(memory.sentKeys)) {
        memory.sentKeys = [];
    }
    if (!memory.sentKeys.includes(sent.idempotencyKey)) {
        memory.sentKeys.unshift(sent.idempotencyKey);
    }
    const limit = Number(sent.memoryLimit) || 2000;
    if (memory.sentKeys.length > limit) {
        memory.sentKeys.length = limit;
    }
}

function reason() {
    if (accepted) return null;
    if (error && typeof error.message === 'string' && error.message !== '') return error.message;
    if (statusCode >= 400 && statusCode < 500) return `whapi_rejected_${statusCode}`;
    if (statusCode >= 500) return `whapi_unavailable_${statusCode}`;
    return 'whapi_unreadable_answer';
}

// The number and the message body stop here: what leaves this node is what an
// operator may read in an execution list, and the execution list is the one
// place this workflow is forbidden to keep them (settings: save nothing).
return [
    {
        json: {
            acknowledged: accepted,
            idempotency_key: sent.idempotencyKey,
            notification_public_id: sent.notificationPublicId,
            order_number: sent.orderNumber,
            template: sent.template,
            status_code: statusCode,
            reason: reason(),
        },
    },
];
