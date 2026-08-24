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

it('prices SBC challenges in the viewer currency too', function (): void {
    // sbcLines() reads the catalogue directly instead of taking the prepared
    // converter, and it passed store.default_display_currency. So even after the
    // controller started handing down the viewer's currency, an SBC question
    // still answered in SAR. Ask about the challenges specifically: a question
    // that only matches `rivals` never renders an sbc line, so the earlier tests
    // could not have caught this.
    seedOmanRate();

    // The test catalogue ships no SBC products, so an sbc line only exists if
    // this test creates one.
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

    $conversation = ChatConversation::factory()->create(['locale' => 'ar']);
    $owner = ChatOwner::guest((string) $conversation->guest_key);
    $message = ChatMessage::factory()->customer()->agentEligible()
        ->for($conversation, 'conversation')->create(['content' => 'كم سعر تحديات SBC؟']);
    $turn = AgentTurn::factory()->waiting()->for($conversation, 'conversation')->create([
        'first_customer_message_id' => $message->id,
        'last_customer_message_id' => $message->id,
    ]);

    $instructions = app(BuildAgentModelRequest::class)
        ->execute($turn, $owner, 'OMR')
        ->instructions;

    expect($instructions)->toContain('sbc | ')
        ->and($instructions)->not->toContain('SAR');
});

// Deliberately not covered here: what the block does when the viewer's currency
// has no fresh exchange rate. ConvertDisplayMoney::prepare() throws and
// BuildLivePriceContext::render() returns '', so the expected answer is "no
// block at all" — but a probe of that case still produced one, which means the
// test database has a rate for every display currency and the probe never
// exercised the path it claimed to. Left uncovered rather than asserted from a
// guess; the path is untouched by this fix.
