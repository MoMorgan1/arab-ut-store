<?php

declare(strict_types=1);

use App\Actions\AI\BuildAgentModelRequest;
use App\Models\AgentTurn;
use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\ValueObjects\Chat\ChatOwner;

function knowledgeGroundedInstructions(string $question, string $locale = 'ar'): string
{
    $conversation = ChatConversation::factory()->create(['locale' => $locale]);
    $owner = ChatOwner::guest((string) $conversation->guest_key);
    $message = ChatMessage::factory()->customer()->agentEligible()
        ->for($conversation, 'conversation')->create(['content' => $question]);
    $turn = AgentTurn::factory()->waiting()->for($conversation, 'conversation')->create([
        'first_customer_message_id' => $message->id,
        'last_customer_message_id' => $message->id,
    ]);

    return app(BuildAgentModelRequest::class)->execute($turn, $owner)->instructions;
}

test('the topics a question is about are injected with their ids', function () {
    $instructions = knowledgeGroundedInstructions('كم مدة الضمان بعد الشحن؟');

    expect($instructions)->toContain('<store_knowledge>')
        ->toContain('[id: warranty]')
        ->toContain('192 ساعة')
        ->toContain('</store_knowledge>');
});

test('an English conversation receives the English side of the topic', function () {
    $instructions = knowledgeGroundedInstructions('how long is the warranty?', 'en');

    expect($instructions)->toContain('[id: warranty]')
        ->toContain('192 hours')
        ->not->toContain('192 ساعة');
});

test('a question about nothing in the corpus injects no block', function () {
    // The prompt itself names the delimiter, so the injected topics are what
    // distinguishes a grounded turn from an ungrounded one.
    expect(knowledgeGroundedInstructions('السلام عليكم'))->not->toContain('[id: ');
});

test('grounding can be switched off without touching the prompt', function () {
    config()->set('ai-assistant.knowledge_max_topics', 0);

    expect(knowledgeGroundedInstructions('كم مدة الضمان بعد الشحن؟'))
        ->not->toContain('[id: ');
});

test('the injected block never exceeds the configured topic count', function () {
    config()->set('ai-assistant.knowledge_max_topics', 2);

    $instructions = knowledgeGroundedInstructions('الضمان والاسترجاع والكوينز والتحديات');

    expect(substr_count($instructions, '[id: '))->toBe(2);
});
