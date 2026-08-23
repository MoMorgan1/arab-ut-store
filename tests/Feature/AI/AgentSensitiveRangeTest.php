<?php

use App\Actions\AI\CreateOrRecoverAgentTurn;
use App\Actions\AI\StreamAgentTurn;
use App\Contracts\AI\AgentModelResolver;
use App\Enums\AI\AgentErrorCode;
use App\Enums\AI\AgentTurnStatus;
use App\Enums\AI\AppStreamEventType;
use App\Models\AgentRun;
use App\Models\AgentTurn;
use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\ValueObjects\AI\AppStreamEvent;
use App\ValueObjects\Chat\ChatOwner;
use Tests\Support\AI\ScriptedAgentModel;
use Tests\Support\AI\ScriptedAgentModelResolver;

test('sensitive range blocks before lazy resolver and a later harmless turn succeeds', function () {
    config()->set('ai-assistant.provider', 'fake');
    $conversation = ChatConversation::factory()->create();
    $sensitiveMessage = ChatMessage::factory()->customer()->agentEligible()
        ->for($conversation, 'conversation')->create([
            'content' => 'My password is SYNTHETIC_SECRET_VALUE',
            'created_at' => now()->subSeconds(5),
        ]);
    $sensitiveTurn = AgentTurn::factory()->for($conversation, 'conversation')->create([
        'first_customer_message_id' => $sensitiveMessage->id,
        'last_customer_message_id' => $sensitiveMessage->id,
    ]);
    $owner = ChatOwner::guest((string) $conversation->guest_key);
    $resolver = new ScriptedAgentModelResolver(
        ScriptedAgentModel::completed(['Harmless completion.']),
    );
    app()->instance(AgentModelResolver::class, $resolver);

    $events = iterator_to_array(app(StreamAgentTurn::class)->execute($sensitiveTurn, $owner, 'SAR'));

    expect($resolver->resolutionCalls)->toBe(0)
        ->and($sensitiveTurn->fresh()->status)->toBe(AgentTurnStatus::Failed)
        ->and($sensitiveTurn->fresh()->terminal_error_code)
        ->toBe(AgentErrorCode::SensitiveContentBlocked)
        ->and(AgentRun::query()->where('agent_turn_id', $sensitiveTurn->id)->count())->toBe(0)
        ->and(ChatMessage::query()
            ->whereBetween('id', [
                $sensitiveTurn->first_customer_message_id,
                $sensitiveTurn->last_customer_message_id,
            ])->whereNull('agent_prompt_blocked_at')->exists())->toBeFalse();

    $failedEvent = collect($events)->first(fn (AppStreamEvent $e): bool => $e->type === AppStreamEventType::Failed);
    expect($failedEvent)->not->toBeNull()
        ->and($failedEvent->errorCode)->toBe(AgentErrorCode::SensitiveContentBlocked);

    ChatMessage::factory()->customer()->agentEligible()
        ->for($conversation, 'conversation')->create([
            'content' => 'Harmless later request.',
            'created_at' => now()->subSeconds(2),
        ]);
    $next = app(CreateOrRecoverAgentTurn::class)->execute($conversation, $owner);
    expect($next->turn)->not->toBeNull();

    $nextEvents = iterator_to_array(app(StreamAgentTurn::class)->execute($next->turn, $owner, 'SAR'));

    expect($resolver->resolutionCalls)->toBe(1)
        ->and($next->turn->fresh()->status)->toBe(AgentTurnStatus::Completed)
        ->and($next->turn->fresh()->assistant_message_id)->not->toBeNull();

    $completedEvent = collect($nextEvents)->first(fn (AppStreamEvent $e): bool => $e->type === AppStreamEventType::Completed);
    expect($completedEvent)->not->toBeNull();
});
