<?php

use App\Actions\AI\CreateOrRecoverAgentTurn;
use App\Actions\AI\StreamAgentTurn;
use App\Contracts\AI\AgentModelResolver;
use App\Enums\AI\AgentTurnStatus;
use App\Models\AgentRun;
use App\Models\AgentTurn;
use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Queries\AI\PendingAgentMessages;
use App\ValueObjects\Chat\ChatOwner;
use Illuminate\Support\Carbon;
use Tests\Support\AI\ScriptedAgentModel;
use Tests\Support\AI\ScriptedAgentModelResolver;

afterEach(function (): void {
    Carbon::setTestNow();
});

test('backlog of 25 messages drains in chunks of default 24 across two turns then goes idle', function () {
    Carbon::setTestNow('2026-08-21 12:00:00');
    config()->set('ai-assistant.provider', 'fake');
    config()->set('ai-assistant.max_context_messages', 24);

    $conversation = ChatConversation::factory()->create();
    $owner = ChatOwner::guest((string) $conversation->guest_key);

    for ($i = 1; $i <= 25; $i++) {
        ChatMessage::factory()->customer()->agentEligible()
            ->for($conversation, 'conversation')->create([
                'content' => "Customer backlog message {$i}",
                'created_at' => now()->subSeconds(10),
            ]);
    }

    $resolver = new ScriptedAgentModelResolver(
        ScriptedAgentModel::completed(['Backlog response.']),
    );
    app()->instance(AgentModelResolver::class, $resolver);

    $first = app(CreateOrRecoverAgentTurn::class)->execute($conversation, $owner);
    expect($first->turn)->not->toBeNull();
    iterator_to_array(app(StreamAgentTurn::class)->execute($first->turn, $owner, 'SAR'));
    $firstHasPending = app(PendingAgentMessages::class)->existsAfter(
        $conversation,
        (int) $first->turn->last_customer_message_id,
    );

    $second = app(CreateOrRecoverAgentTurn::class)->execute($conversation, $owner);
    expect($second->turn)->not->toBeNull();
    iterator_to_array(app(StreamAgentTurn::class)->execute($second->turn, $owner, 'SAR'));
    $secondHasPending = app(PendingAgentMessages::class)->existsAfter(
        $conversation,
        (int) $second->turn->last_customer_message_id,
    );

    $third = app(CreateOrRecoverAgentTurn::class)->execute($conversation, $owner);

    expect($firstHasPending)->toBeTrue()
        ->and($secondHasPending)->toBeFalse()
        ->and($third->turn)->toBeNull()
        ->and($resolver->resolutionCalls)->toBe(2)
        ->and(AgentTurn::query()->where('conversation_id', $conversation->id)->count())->toBe(2)
        ->and(AgentRun::query()->count())->toBe(2);

    $turns = AgentTurn::query()->where('conversation_id', $conversation->id)->orderBy('id')->get();
    expect($turns[0]->status)->toBe(AgentTurnStatus::Completed)
        ->and($turns[0]->last_customer_message_id - $turns[0]->first_customer_message_id + 1)->toBe(24)
        ->and($turns[1]->status)->toBe(AgentTurnStatus::Completed)
        ->and($turns[1]->last_customer_message_id - $turns[1]->first_customer_message_id + 1)->toBe(1);
});

test('backlog chunking honors custom configured max context messages limit of 10', function () {
    Carbon::setTestNow('2026-08-21 12:00:00');
    config()->set('ai-assistant.provider', 'fake');
    config()->set('ai-assistant.max_context_messages', 10);

    $conversation = ChatConversation::factory()->create();
    $owner = ChatOwner::guest((string) $conversation->guest_key);

    for ($i = 1; $i <= 25; $i++) {
        ChatMessage::factory()->customer()->agentEligible()
            ->for($conversation, 'conversation')->create([
                'content' => "Custom backlog message {$i}",
                'created_at' => now()->subSeconds(10),
            ]);
    }

    $resolver = new ScriptedAgentModelResolver(
        ScriptedAgentModel::completed(['Custom backlog response.']),
    );
    app()->instance(AgentModelResolver::class, $resolver);

    $first = app(CreateOrRecoverAgentTurn::class)->execute($conversation, $owner);
    expect($first->turn)->not->toBeNull();
    iterator_to_array(app(StreamAgentTurn::class)->execute($first->turn, $owner, 'SAR'));

    $second = app(CreateOrRecoverAgentTurn::class)->execute($conversation, $owner);
    expect($second->turn)->not->toBeNull();
    iterator_to_array(app(StreamAgentTurn::class)->execute($second->turn, $owner, 'SAR'));

    $third = app(CreateOrRecoverAgentTurn::class)->execute($conversation, $owner);
    expect($third->turn)->not->toBeNull();
    iterator_to_array(app(StreamAgentTurn::class)->execute($third->turn, $owner, 'SAR'));

    $fourth = app(CreateOrRecoverAgentTurn::class)->execute($conversation, $owner);

    expect($fourth->turn)->toBeNull()
        ->and($resolver->resolutionCalls)->toBe(3)
        ->and(AgentTurn::query()->where('conversation_id', $conversation->id)->count())->toBe(3)
        ->and(AgentRun::query()->count())->toBe(3);

    $turns = AgentTurn::query()->where('conversation_id', $conversation->id)->orderBy('id')->get();
    expect($turns[0]->last_customer_message_id - $turns[0]->first_customer_message_id + 1)->toBe(10)
        ->and($turns[1]->last_customer_message_id - $turns[1]->first_customer_message_id + 1)->toBe(10)
        ->and($turns[2]->last_customer_message_id - $turns[2]->first_customer_message_id + 1)->toBe(5);
});
