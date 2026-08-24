<?php

use App\Actions\AI\StreamAgentTurn;
use App\Enums\Chat\ChatSenderType;
use App\Models\AgentRun;
use App\Models\AgentTurn;
use App\Models\ChatConversation;
use App\Models\User;
use App\ValueObjects\Chat\ChatOwner;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

test('database rows, logs, and streamed events do not contain secrets, safety identifiers, prompt text, or raw provider errors', function () {
    $canaryApiKey = 'sk-canary-secret-api-key-987654321';
    $canarySafetyId = str_repeat('c', 64);
    $canaryPromptText = 'CANARY_SENSITIVE_CUSTOMER_PROMPT_12345';
    $canaryRawProviderError = 'CANARY_RAW_INTERNAL_PROVIDER_STACK_TRACE_54321';

    config()->set('services.openai.key', $canaryApiKey);
    config()->set('ai-assistant.enabled', true);
    config()->set('ai-assistant.rollout', 'public');
    config()->set('ai-assistant.provider', 'openai');

    $user = User::factory()->create();
    $owner = ChatOwner::user($user->id);

    $conversation = ChatConversation::query()->create([
        'user_id' => $user->id,
        'public_id' => (string) strtolower(str_repeat('d', 26)),
    ]);

    $message = $conversation->messages()->create([
        'sender_type' => ChatSenderType::Customer,
        'content' => $canaryPromptText,
        'agent_eligible_at' => now(),
    ]);

    Http::fake([
        'https://api.openai.com/v1/responses' => Http::response(
            "data: {\"type\":\"error\",\"error\":{\"code\":\"server_error\",\"message\":\"{$canaryRawProviderError}\"}}\n\n",
            200,
            ['Content-Type' => 'text/event-stream'],
        ),
    ]);

    $loggedMessages = [];
    Log::listen(function ($log) use (&$loggedMessages) {
        $loggedMessages[] = $log->message.' '.json_encode($log->context);
    });

    $turn = AgentTurn::factory()->create([
        'conversation_id' => $conversation->id,
        'first_customer_message_id' => $message->id,
        'last_customer_message_id' => $message->id,
    ]);
    $events = iterator_to_array(app(StreamAgentTurn::class)->execute($turn, $owner, 'SAR'));

    // Check database rows
    $runs = AgentRun::all();
    expect($runs)->not->toBeEmpty();

    $canaries = [
        $canaryApiKey,
        $canarySafetyId,
        $canaryRawProviderError,
    ];

    foreach ($runs as $run) {
        $runAttributesJson = json_encode($run->toArray());
        foreach ($canaries as $canary) {
            expect($runAttributesJson)->not->toContain($canary);
        }
        expect($runAttributesJson)->not->toContain($canaryPromptText);
    }

    // Check logs
    $allLogs = implode("\n", $loggedMessages);
    foreach ($canaries as $canary) {
        expect($allLogs)->not->toContain($canary);
    }
    expect($allLogs)->not->toContain($canaryPromptText);

    // Check streamed events
    $eventsJson = json_encode($events);
    foreach ($canaries as $canary) {
        expect($eventsJson)->not->toContain($canary);
    }
});
