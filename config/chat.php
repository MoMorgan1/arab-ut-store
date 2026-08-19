<?php

return [
    'enabled' => (bool) env('CHAT_ENABLED', false),
    'demo_assistant' => (bool) env('CHAT_DEMO_ASSISTANT', false),
    'session_key' => 'arabut_chat_guest_token',
    'active_conversation_session_key' => 'arabut_chat_active_conversation',
    'max_message_length' => 4000,
    'default_page_size' => 50,
];
