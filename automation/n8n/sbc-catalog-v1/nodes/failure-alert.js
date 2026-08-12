/* eslint-disable */
const item = $input.first().json;
const reason = item.failureReason || 'SBC catalog workflow failed closed';
const to = $env.OPS_WHATSAPP_TARGET || '';

return [
    {
        json: {
            alertEnabled: Boolean(to),
            to,
            body: `SBC catalog failed closed. The last accepted catalog remains active. Reason: ${reason}`,
            failureReason: reason,
        },
    },
];
