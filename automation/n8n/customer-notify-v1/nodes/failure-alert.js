/* eslint-disable */
// Runs inside the Error Workflow, not in customer-notify. Only a real incident
// reaches it: a rejected signature, a malformed request, a Config node nobody
// filled in. A wrong number or a Whapi outage does NOT - those answer the store
// `acknowledged: false` and it retries on its own, so they never page anybody.
//
// Execution data is not saved on customer-notify (the request carries the
// customer's name, number and message), so this is the only record of the
// failure. It carries the order number and never the recipient.
const event = $input.first().json;
const execution = event.execution ?? {};
const workflow = event.workflow ?? {};
const error = execution.error ?? {};
const message = error.message || 'خطأ غير معروف';

const lines = [
    'customer-notify وقف - رسالة العميل ما اتبعتتش.',
    'المتجر هيعيد المحاولة تلقائياً، وبعد عشر محاولات هتظهر في لوحة صحة الطابور.',
    '',
    `السبب: ${message}`,
];

if (/CONFIGURE_|not set in the Config node/.test(message)) {
    lines.push('في قيمة في نود Config لسه ما اتلصقتش.');
}
if (/Signature|Key does not match/.test(message)) {
    lines.push('المفتاح أو السر في n8n مختلف عن اللي في المتجر.');
}
if (execution.lastNodeExecuted) {
    lines.push(`عند النود: ${execution.lastNodeExecuted}`);
}
if (workflow.name) {
    lines.push(`الوركفلو: ${workflow.name}`);
}

// Deliberately no throw: this runs inside the error execution, so a throw
// would only bury the original failure.
return [
    {
        json: {
            body: lines.join('\n'),
            failureReason: error.message || null,
        },
    },
];
