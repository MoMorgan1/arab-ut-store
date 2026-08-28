/* eslint-disable */
// Runs inside the Error Workflow, not the catalog workflow. It is reached by
// every failure the catalog run can produce, because v4 fails by throwing
// rather than routing a failureReason through in-flow IF gates.
const event = $input.first().json;
const execution = event.execution ?? {};
const workflow = event.workflow ?? {};
const error = execution.error ?? {};

const lines = [
    'كتالوج SBC وقف. ما تغيّر أي سعر.',
    'آخر كتالوج معتمد لا يزال شغّال.',
    '',
    `السبب: ${error.message || 'خطأ غير معروف'}`,
];

if (execution.lastNodeExecuted) {
    lines.push(`عند النود: ${execution.lastNodeExecuted}`);
}
if (workflow.name) {
    lines.push(`الوركفلو: ${workflow.name}`);
}
if (execution.url) {
    lines.push('', execution.url);
}

// Deliberately no throw here. This code runs inside the error execution, so a
// throw would only bury the original failure. An empty chat id makes the
// Telegram node itself go red, which stays visible in the executions list.
return [
    {
        json: {
            to: $env.OPS_TELEGRAM_CHAT_ID || '',
            body: lines.join('\n'),
            failureReason: error.message || null,
        },
    },
];
