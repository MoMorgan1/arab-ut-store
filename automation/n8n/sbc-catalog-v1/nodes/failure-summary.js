/* eslint-disable */
const item = $input.first().json;

return [
    {
        json: {
            status: 'failed_closed',
            alerted: false,
            failureReason: item.failureReason,
        },
    },
];
