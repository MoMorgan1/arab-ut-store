<?php

return [
    'enabled' => (bool) env('CHAT_ENABLED', false),
    'demo_assistant' => (bool) env('CHAT_DEMO_ASSISTANT', false),
    'max_message_length' => 4000,
    'default_page_size' => 50,
    'auto_close_hours' => (int) env('CHAT_AUTO_CLOSE_HOURS', 24),
    'reopen_within_days' => (int) env('CHAT_REOPEN_WITHIN_DAYS', 7),
    'guest_retention_days' => (int) env('CHAT_GUEST_RETENTION_DAYS', 30),
    'user_retention_days' => (int) env('CHAT_USER_RETENTION_DAYS', 180),
];
