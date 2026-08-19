<?php

return [
    'enabled' => (bool) env('CHAT_ENABLED', false),
    'demo_assistant' => (bool) env('CHAT_DEMO_ASSISTANT', false),
    'max_message_length' => 4000,
    'default_page_size' => 50,
];
