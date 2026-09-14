<?php

namespace App\Admin\ManualOrder;

use App\Enums\Platform;
use App\Enums\ServiceType;

/**
 * One line of a manual order.
 *
 * `priceHalalah` is the whole line, not a unit price multiplied by a quantity.
 * That follows the shape the coins path already writes - `quantity => 1` with
 * the quoted total in `unit_price_halalah` (`AddCoinsToCart.php:152`) - because
 * the amount bought lives in the configuration for every service this store
 * sells, and inventing a second money model for staff-written orders is how the
 * two start disagreeing.
 */
final readonly class ManualOrderItemDraft
{
    /**
     * @param  array<string, mixed>  $configuration  already projected through the
     *                                               order-item allowlist
     * @param  array<string, mixed>|null  $credentials  ea_email, and optionally
     *                                                  ea_password and backup_codes
     */
    public function __construct(
        public ServiceType $serviceType,
        public Platform $platform,
        public ?int $productVariantId,
        public string $sku,
        public string $nameAr,
        public string $nameEn,
        public int $priceHalalah,
        public array $configuration,
        public ?array $credentials,
        public ?ManualOrderPlacement $placement,
    ) {}

    /**
     * Whether this service is delivered by a supplier bot at all. The three
     * manual services are delivered by a person, so no reference exists to
     * paste and no fulfillment job is created for them.
     */
    public function isAutomated(): bool
    {
        return in_array($this->serviceType, [ServiceType::Coins, ServiceType::Sbc], true);
    }
}
