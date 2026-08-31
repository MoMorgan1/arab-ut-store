<?php

declare(strict_types=1);

namespace App\Actions\AI;

use App\Enums\Platform;
use App\Services\Catalog\CoinsCatalogReader;

/**
 * Offers to put a fully configured service in the customer's cart, without
 * leaving the chat.
 *
 * Like every other block on an assistant message, the offer is derived on the
 * server from the customer's own words — the model never authors one. It is
 * emitted only when the customer has named a configuration the store can
 * actually price and sell; a half-named one is a question for
 * BuildAssistantChoices to ask, not a button.
 *
 * The offer carries no price and no credentials. The price is live data
 * resolved when the panel renders, and the credentials the store requires at
 * cart-add time are typed into the panel and posted straight to the cart
 * endpoint — they never enter a chat message, the transcript, or a model
 * prompt.
 */
final readonly class BuildAssistantCartOffer
{
    public function __construct(
        private SelectSupportKnowledge $selectKnowledge,
        private SelectServiceOptions $selectOptions,
        private CoinsCatalogReader $catalog,
    ) {}

    /**
     * Topics that mean "this customer is shopping for coins". A warranty or
     * refund question brushes the coins vocabulary too, so the offer follows
     * the primary topic rather than any mention.
     */
    private const COIN_TOPICS = ['coins-service', 'coins-speeds'];

    /**
     * @return array{
     *     version: string,
     *     service: string,
     *     selection: array{platform: string, delivery?: string, quantity: int}
     * }|null
     */
    public function execute(string $customerText): ?array
    {
        if (trim($customerText) === '') {
            return null;
        }

        $topics = $this->selectKnowledge->execute($customerText, 1);
        $primary = $topics[0] ?? null;

        if ($primary === null || ! in_array($primary->id, self::COIN_TOPICS, true)) {
            return null;
        }

        $options = $this->selectOptions->execute($customerText, 'coins');
        $selection = $this->coinsSelection($options);

        if ($selection === null) {
            return null;
        }

        return [
            'version' => 'cart.v1',
            'service' => 'coins',
            'selection' => $selection,
        ];
    }

    /**
     * Whether the store will actually sell this amount on this route.
     *
     * The caps differ per delivery speed — console normal stops well below
     * console fast — so a quantity the customer can legitimately name is not
     * always one the endpoint accepts. Offering it anyway ships a button that
     * can only ever fail, with a validation error that maps to no field the
     * panel can highlight.
     */
    private function sellable(int $quantity, int $maximum): bool
    {
        return $quantity <= $maximum
            && $this->catalog->quantityRules()->accepts($quantity);
    }

    /**
     * A cart-ready coins selection, or null when the customer has not named
     * enough for the store to price it.
     *
     * PC is sold at a single speed and carries no delivery choice, so a PC
     * selection is complete without one. A console order is not: normal and
     * fast are different products at different prices, and guessing which one
     * the customer meant would put the wrong item in their cart.
     *
     * @param  array<string, mixed>  $options
     * @return array{platform: string, delivery?: string, quantity: int, requiresBalance: bool}|null
     */
    private function coinsSelection(array $options): ?array
    {
        $platform = $options['platform'] ?? null;
        $quantity = $options['quantity'] ?? null;

        if (! is_string($platform) || ! is_int($quantity)) {
            return null;
        }

        if ($platform === Platform::Pc->value) {
            return $this->sellable($quantity, (int) config('coins.platforms.pc.maximum'))
                ? ['platform' => $platform, 'quantity' => $quantity, 'requiresBalance' => false]
                : null;
        }

        if ($platform !== Platform::PlayStation->value) {
            return null;
        }

        $delivery = $options['delivery'] ?? null;

        if (! is_string($delivery)) {
            return null;
        }

        $maximum = config("coins.platforms.playstation.deliveries.{$delivery}.maximum");

        if (! is_int($maximum) || ! $this->sellable($quantity, $maximum)) {
            return null;
        }

        return [
            'platform' => $platform,
            'delivery' => $delivery,
            'quantity' => $quantity,
            // Resolved when the offer is made: the panel must ask for the
            // same credentials the cart endpoint will demand.
            'requiresBalance' => $delivery === 'fast'
                && $this->catalog->requiresCurrentBalance(),
        ];
    }
}
