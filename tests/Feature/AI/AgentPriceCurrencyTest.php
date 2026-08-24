<?php

declare(strict_types=1);

use App\Actions\AI\BuildAgentModelRequest;
use App\Enums\Platform;
use App\Enums\ServiceType;
use App\Models\AgentTurn;
use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Models\ExchangeRate;
use App\Models\Product;
use App\Models\ProductVariant;
use App\ValueObjects\Chat\ChatOwner;

/**
 * The assistant's reply and the service cards under it must quote ONE currency.
 *
 * The cards are priced from the viewer's session display_currency. The prompt's
 * live price table was built from store.default_display_currency instead, so a
 * customer browsing in OMR was told a price in SAR directly above a card reading
 * OMR — both figures correct, in different currencies, in one message.
 *
 * NEVER assert `toContain('<live_prices>')` to prove a table was injected.
 * support-v6 discusses the block by name in its own prose, so that string is
 * present on every single turn whether a table rendered or not. Assert on a
 * price LINE (`rivals | Division`) or on the closing tag, which the prose does
 * not contain.
 */
function priceCurrencyInstructions(string $displayCurrency, string $question = 'كم سعر الرايفلز؟'): string
{
    $conversation = ChatConversation::factory()->create(['locale' => 'ar']);
    $owner = ChatOwner::guest((string) $conversation->guest_key);
    $message = ChatMessage::factory()->customer()->agentEligible()
        ->for($conversation, 'conversation')->create(['content' => $question]);
    $turn = AgentTurn::factory()->waiting()->for($conversation, 'conversation')->create([
        'first_customer_message_id' => $message->id,
        'last_customer_message_id' => $message->id,
    ]);

    return app(BuildAgentModelRequest::class)
        ->execute($turn, $owner, $displayCurrency)
        ->instructions;
}

function seedDisplayRate(string $currency, string $rate): void
{
    ExchangeRate::query()->create([
        'base_currency' => 'SAR',
        'quote_currency' => $currency,
        'rate' => $rate,
        'source' => 'test',
        'fetched_at' => now(),
    ]);
}

it('prices the table in the currency the viewer is browsing in', function (): void {
    seedDisplayRate('OMR', '0.10250000');

    $instructions = priceCurrencyInstructions('OMR');

    expect($instructions)->toContain('</live_prices>')
        ->and($instructions)->toContain('rivals | Division')
        ->and($instructions)->toContain('OMR')
        ->and($instructions)->not->toContain('SAR');
});

it('follows the viewer currency rather than any single hardcoded one', function (): void {
    // The OMR case above cannot tell "reads the parameter" apart from "hardcoded
    // to OMR". A second, different currency can. Asserting the absence of both
    // SAR (the old store default) and OMR (the other test's currency) is what
    // makes this pair meaningful.
    seedDisplayRate('AED', '0.98000000');

    $instructions = priceCurrencyInstructions('AED');

    expect($instructions)->toContain('rivals | Division')
        ->and($instructions)->toContain('AED')
        ->and($instructions)->not->toContain('SAR')
        ->and($instructions)->not->toContain('OMR');
});

it('prices SBC challenges in the viewer currency too', function (): void {
    // sbcLines() reads the catalogue directly instead of taking the prepared
    // converter, and it passed store.default_display_currency. So even once the
    // controller began handing down the viewer's currency, an SBC question still
    // answered in SAR. A rivals question never renders an sbc line, so the two
    // tests above could not have caught it.
    seedDisplayRate('OMR', '0.10250000');

    // The test catalogue ships no SBC products; without this there is no sbc
    // line to check and the assertion would pass on an empty block.
    $product = Product::factory()->create([
        'service_type' => ServiceType::Sbc,
        'slug' => 'currency-probe',
        'name_ar' => 'تحدي الاختبار',
        'name_en' => 'Probe Challenge',
        'is_visible' => true,
        'archived_at' => null,
    ]);
    ProductVariant::factory()->for($product)->create([
        'service_type' => ServiceType::Sbc,
        'platform' => Platform::PlayStation,
        'price_halalah' => 9_000,
        'sale_price_halalah' => null,
        'is_active' => true,
    ]);

    $instructions = priceCurrencyInstructions('OMR', 'كم سعر تحديات SBC؟');

    expect($instructions)->toContain('sbc | ')
        ->and($instructions)->not->toContain('SAR');
});

it('renders no price table at all when the viewer currency has no fresh rate', function (): void {
    // No rate seeded. ConvertDisplayMoney::prepare() throws, render() returns ''
    // and no table is injected. Verified directly: the context string is empty
    // and only the prompt's own prose mentions the block by name. This is the
    // correct failure — falling back to SAR is the original defect.
    $instructions = priceCurrencyInstructions('OMR');

    expect($instructions)->not->toContain('</live_prices>')
        ->and($instructions)->not->toContain('rivals | Division')
        ->and($instructions)->not->toContain('OMR ')
        ->and($instructions)->not->toContain('SAR ');
});
