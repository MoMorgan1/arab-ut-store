<?php

declare(strict_types=1);

use App\Actions\AI\BuildAgentModelRequest;
use App\Models\AgentTurn;
use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\ValueObjects\Chat\ChatOwner;

function livePricedInstructions(string $question, string $locale = 'ar'): string
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

test('a price question receives the live price table', function () {
    // The seeded catalogue carries manual service pricing, so at minimum the
    // play services appear; the block only exists when a price could be read.
    $instructions = livePricedInstructions('كم سعر الرايفلز؟');

    expect($instructions)->toContain('<live_prices>')
        ->toContain('rivals |')
        ->toContain('</live_prices>');
});

test('the table tells the model not to calculate an unlisted price', function () {
    expect(livePricedInstructions('كم سعر الرايفلز؟'))
        ->toContain('ولا تحسب أو تقدّر أي سعر غير مذكور');
});

test('an English conversation gets the English instruction line', function () {
    expect(livePricedInstructions('how much are rivals?', 'en'))
        ->toContain('Quote these exactly');
});

test('a question with no pricing relevance carries no price table', function () {
    // The table costs tokens on every turn it appears in, so it is only worth
    // attaching when the customer is asking about something the store sells.
    // The prompt itself names the block, so an attached table is what the
    // priced rows distinguish.
    expect(livePricedInstructions('نسيت كلمة المرور، وش أسوي؟'))
        ->not->toContain('rivals |');
});

test('a greeting carries neither knowledge nor prices', function () {
    $instructions = livePricedInstructions('السلام عليكم');

    expect($instructions)->not->toContain('[id: ')
        ->not->toContain('rivals |');
});
