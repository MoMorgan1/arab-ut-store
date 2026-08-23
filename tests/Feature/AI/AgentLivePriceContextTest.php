<?php

declare(strict_types=1);

use App\Actions\AI\BuildAgentModelRequest;
use App\Actions\AI\CreateOrRecoverAgentTurn;
use App\Models\AgentTurn;
use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Models\ExchangeRate;
use App\ValueObjects\Chat\ChatOwner;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

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

    return app(BuildAgentModelRequest::class)->execute($turn, $owner)->instructions;
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

function livePricedInstructionsIn(?string $displayCurrency): string
{
    $conversation = ChatConversation::factory()->create(['locale' => 'ar']);
    $owner = ChatOwner::guest((string) $conversation->guest_key);
    $message = ChatMessage::factory()->customer()->agentEligible()
        ->for($conversation, 'conversation')->create(['content' => 'كم سعر الرايفلز؟']);
    $turn = AgentTurn::factory()->waiting()->for($conversation, 'conversation')->create([
        'first_customer_message_id' => $message->id,
        'last_customer_message_id' => $message->id,
        'display_currency' => $displayCurrency,
    ]);

    Cache::flush();

    return app(BuildAgentModelRequest::class)->execute($turn, $owner)->instructions;
}

test('the price table is built in the currency the customer is browsing in', function () {
    // The card beside the reply shows the session currency. Quoting SAR next to
    // an AED card asks the customer to reconcile two different numbers for the
    // same thing.
    ExchangeRate::create([
        'base_currency' => 'SAR',
        'quote_currency' => 'AED',
        'rate' => '0.98000000',
        'source' => 'test',
        'fetched_at' => now(),
    ]);

    $instructions = livePricedInstructionsIn('AED');

    expect($instructions)->toContain('<live_prices>')
        ->and($instructions)->toContain('AED');
});

test('a turn with no recorded currency falls back to the store default', function () {
    // Turns claimed before the column existed carry nothing; guessing a
    // currency for them would be worse than the store default.
    $instructions = livePricedInstructionsIn(null);

    expect($instructions)->toContain('<live_prices>')
        ->and($instructions)->toContain('SAR');
});

/** Claims debounce, so the turn only exists after the quiet window passes. */
function claimTurnWithCurrency(?string $displayCurrency): ?AgentTurn
{
    Carbon::setTestNow('2026-08-23 12:00:00');
    config()->set('ai-assistant.turn_debounce_ms', 1500);

    $conversation = ChatConversation::factory()->create(['locale' => 'ar']);
    $owner = ChatOwner::guest((string) $conversation->guest_key);
    ChatMessage::factory()->customer()->agentEligible()
        ->for($conversation, 'conversation')
        ->create(['content' => 'كم سعر الرايفلز؟', 'created_at' => now()]);

    $claim = app(CreateOrRecoverAgentTurn::class)->execute($conversation, $owner, $displayCurrency);
    expect($claim->turn)->toBeNull();

    test()->travel(1500)->milliseconds();

    return app(CreateOrRecoverAgentTurn::class)
        ->execute($conversation, $owner, $displayCurrency)
        ->turn;
}

test('the recorded currency is the one the session was using', function () {
    expect(claimTurnWithCurrency('AED')?->display_currency)->toBe('AED');

    Carbon::setTestNow();
});

test('an unsupported currency is dropped rather than recorded', function () {
    // A currency the converter cannot honour would build no price table at
    // all; recording nothing falls back to the store default instead.
    expect(claimTurnWithCurrency('XXX')?->display_currency)->toBeNull();

    Carbon::setTestNow();
});
