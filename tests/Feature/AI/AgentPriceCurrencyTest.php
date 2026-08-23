<?php

declare(strict_types=1);

use App\Actions\AI\BuildAgentModelRequest;
use App\Models\AgentTurn;
use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Models\ExchangeRate;
use App\ValueObjects\Chat\ChatOwner;
use Illuminate\Support\Facades\Cache;

// BuildLivePriceContext caches the rendered table per currency for 60 seconds.
// Without this, a test that seeds a rate leaves a warm table behind for the next
// one, and the missing-rate case reads a block it should never have seen.
beforeEach(fn () => Cache::flush());

/**
 * The assistant's reply and the service cards under it must quote ONE currency.
 *
 * The cards are priced from the viewer's session display_currency. The prompt's
 * live price table was built from store.default_display_currency instead, so a
 * customer browsing in OMR was told a price in SAR directly above a card reading
 * OMR — both figures correct, in different currencies, in one message.
 */
function priceCurrencyInstructions(string $displayCurrency): string
{
    $conversation = ChatConversation::factory()->create(['locale' => 'ar']);
    $owner = ChatOwner::guest((string) $conversation->guest_key);
    $message = ChatMessage::factory()->customer()->agentEligible()
        ->for($conversation, 'conversation')->create(['content' => 'كم سعر الرايفلز؟']);
    $turn = AgentTurn::factory()->waiting()->for($conversation, 'conversation')->create([
        'first_customer_message_id' => $message->id,
        'last_customer_message_id' => $message->id,
    ]);

    return app(BuildAgentModelRequest::class)
        ->execute($turn, $owner, $displayCurrency)
        ->instructions;
}

function seedOmanRate(): void
{
    ExchangeRate::query()->create([
        'base_currency' => 'SAR',
        'quote_currency' => 'OMR',
        'rate' => '0.10250000',
        'source' => 'test',
        'fetched_at' => now(),
    ]);
}

it('builds the price table in the currency the viewer is browsing in', function (): void {
    seedOmanRate();

    $instructions = priceCurrencyInstructions('OMR');

    expect($instructions)->toContain('<live_prices>')
        ->and($instructions)->toContain('OMR')
        ->and($instructions)->not->toContain('SAR');
});

it('uses the store default when that is the currency the viewer is browsing in', function (): void {
    $instructions = priceCurrencyInstructions('SAR');

    expect($instructions)->toContain('<live_prices>')
        ->and($instructions)->toContain('SAR');
});

// Deliberately not covered here: what the block does when the viewer's currency
// has no fresh exchange rate. ConvertDisplayMoney::prepare() throws and
// BuildLivePriceContext::render() returns '' — but a block still rendered in a
// probe of that case, so something else supplies lines and the real behaviour is
// not yet understood. That path is unchanged by this fix; it needs its own
// investigation rather than an assertion written from a guess.
