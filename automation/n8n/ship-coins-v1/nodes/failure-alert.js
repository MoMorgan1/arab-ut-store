/* eslint-disable */
// Runs inside the Error Workflow, not in ship-coins. It is reached by every
// failure a run can produce - a rejected signature, an unpriced challenge, a
// supplier that returned no reference, a report the store refused, a timeout -
// because ship-coins fails by throwing rather than routing failures through
// IF gates. The message thrown carries the order number, which is why every
// throw in nodes/*.js starts with "[stage] order AUT-…".
//
// Execution data is not saved on ship-coins (the EA account travels in the
// request), so this is the only record of the failure. It goes to Telegram,
// not WhatsApp (owner decision, 2026-09-14).
const event = $input.first().json;
const execution = event.execution ?? {};
const workflow = event.workflow ?? {};
const error = execution.error ?? {};
const message = error.message || 'خطأ غير معروف';

const lines = [
    'ship-coins وقف - الشحنة ما اتنفذتش.',
    'المتجر هيعيد المحاولة تلقائياً بالبنود اللي لسه ما اتنفذتش.',
    '',
    `السبب: ${message}`,
];

if (/429|[Tt]oo [Mm]any/.test(message)) {
    lines.push('غالباً ضغط طلبات (rate limit) - المحاولة الجاية بعد دقيقة.');
}
if (execution.lastNodeExecuted) {
    lines.push(`عند النود: ${execution.lastNodeExecuted}`);
}
if (workflow.name) {
    lines.push(`الوركفلو: ${workflow.name}`);
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
