<?php

declare(strict_types=1);

use App\Actions\AI\BuildAgentModelRequest;
use App\Actions\AI\SelectSupportKnowledge;
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

    return app(BuildAgentModelRequest::class)->execute($turn, $owner, 'SAR')->instructions;
}

test('the topics a question is about are injected by title', function () {
    $instructions = knowledgeGroundedInstructions('كم مدة الضمان بعد الشحن؟');

    expect($instructions)->toContain('<store_knowledge>')
        ->not->toContain('[id: ')
        ->toContain('192 ساعة')
        ->toContain('</store_knowledge>');
});

test('an English conversation receives the English side of the topic', function () {
    $instructions = knowledgeGroundedInstructions('how long is the warranty?', 'en');

    expect($instructions)->toContain('192 hours')
        ->not->toContain('192 ساعة');
});

test('a question about nothing in the corpus injects no block', function () {
    // The prompt itself names the delimiter, so the injected topics are what
    // distinguishes a grounded turn from an ungrounded one.
    expect(knowledgeGroundedInstructions('السلام عليكم'))->not->toContain('</store_knowledge>');
});

test('grounding can be switched off without touching the prompt', function () {
    config()->set('ai-assistant.knowledge_max_topics', 0);

    expect(knowledgeGroundedInstructions('كم مدة الضمان بعد الشحن؟'))
        ->not->toContain('</store_knowledge>');
});

test('the injected block never exceeds the configured topic count', function () {
    config()->set('ai-assistant.knowledge_max_topics', 2);

    $question = 'الضمان والاسترجاع والكوينز والتحديات';
    $instructions = knowledgeGroundedInstructions($question);
    $block = substr($instructions, strpos($instructions, '<store_knowledge>'));

    $selected = app(SelectSupportKnowledge::class)->execute($question, 2);
    $unselected = app(SelectSupportKnowledge::class)->execute($question, 4);

    expect($selected)->toHaveCount(2);

    foreach ($selected as $topic) {
        expect($block)->toContain($topic->title('ar'));
    }

    foreach (array_slice($unselected, 2) as $topic) {
        expect($block)->not->toContain($topic->title('ar'));
    }
});
