<?php

return [
    'enabled' => (bool) env('CHAT_ENABLED', false),
    'demo_assistant' => (bool) env('CHAT_DEMO_ASSISTANT', false),
    'max_message_length' => 4000,
    'default_page_size' => 50,
    'reopen_within_days' => (int) env('CHAT_REOPEN_WITHIN_DAYS', 7),
];
