<?php

namespace App\Actions\Fulfillment;

use App\Actions\Pricing\ReadSupplierCostTable;
use App\Enums\DeliveryMode;
use App\Enums\DeliveryPhase;
use App\Enums\Platform;
use App\Enums\ServiceType;
use App\Exceptions\PlacementRequestIncomplete;
use App\Models\FulfillmentPlacement;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderItemSecret;
use App\Models\SecretAccessLog;
use App\Support\Orders\AwaitingPlacement;
use App\ValueObjects\Pricing\SupplierCostTable;
use Carbon\CarbonInterface;
use DomainException;

/**
 * The items block of a placement request, composed at send time.
 *
 * The outbox row for a paid order names the order and nothing more. Everything
 * a supplier needs - the configuration, the budget, and the EA account the ADR
 * of 2026-09-12 lets travel in this payload - is read here, when the request
 * is about to leave, and never persisted with the row:
 *
 * - the credentials come from `order_item_secrets`, decrypted for the request
 *   only and each read written to `secret_access_logs`, so a retried send
 *   carries a correction the customer made since the last attempt rather than
 *   replaying what was true at payment;
 * - the budget comes from the newest applied pricing run, the way v14 read the
 *   price sheet when it placed rather than when the order arrived;
 * - an item that gained a fulfillment job meanwhile (staff pasted a reference)
 *   or was cancelled is left out, because `AwaitingPlacement` is re-read.
 *
 * Anything missing stops the whole request, not one item: half an order at a
 * supplier with the other half silently dropped is the failure this exists
 * to prevent, and a released outbox row with a named reason is the honest
 * outcome.
 */
final readonly class ComposePlacementRequest
{
    public const string ACCESS_PURPOSE = 'fulfillment_placement';

    /** The purpose logged when the account is read for the solve request. */
    public const string CHALLENGE_ACCESS_PURPOSE = 'fulfillment_challenge';

    private const string SBC_EXTERNAL_ID_PREFIX = 'easysbc-sbc-';

    public function __construct(private ReadSupplierCostTable $readCostTable) {}

    /**
     * @return list<array<string, mixed>> one entry per item awaiting placement, possibly none
     *
     * @throws PlacementRequestIncomplete
     */
    public function execute(Order $order, CarbonInterface $now): array
    {
        $items = AwaitingPlacement::items($order);

        if ($items->isEmpty()) {
            return [];
        }

        try {
            $costs = $this->readCostTable->execute();
        } catch (DomainException $exception) {
            throw new PlacementRequestIncomplete('budget_unavailable', $exception->getMessage());
        }

        $composed = [];

        foreach ($items as $item) {
            $composed[] = $this->item($item, $costs, $now);
        }

        return $composed;
    }

    /**
     * The item block of a solve request (`challenge.ready`): the set and how
     * many times to solve it, the coins placement that funded it, and the EA
     * account as it stands now. No budget - the coins are already bought, and
     * FFT prices the solve itself.
     *
     * @return array<string, mixed>
     *
     * @throws PlacementRequestIncomplete
     */
    public function challenge(OrderItem $item, CarbonInterface $now): array
    {
        if ($item->service_type !== ServiceType::Sbc) {
            throw new PlacementRequestIncomplete('service_has_no_challenge', "Order item {$item->public_id} is not a challenge.");
        }

        $configuration = is_array($item->configuration) ? $item->configuration : [];
        $funding = $item->fulfillmentJob?->placements()
            ->where('delivery_phase', DeliveryPhase::Coins->value)
            ->orderBy('id')
            ->first();

        if (! $funding instanceof FulfillmentPlacement) {
            throw new PlacementRequestIncomplete('funding_missing', "Order item {$item->public_id} has no coins placement to solve against.");
        }

        $secret = $this->secret($item);

        return [
            'order_item_public_id' => (string) $item->public_id,
            'service' => $item->service_type->value,
            'platform' => $item->platform->value,
            'supplier_platform' => $this->supplierPlatform($item),
            'quantity' => (int) $item->quantity,
            'sbc' => [
                'set_id' => $this->challengeSetId($item),
                'times_to_solve' => max(1, (int) ($configuration['completion_count'] ?? 1)) * max(1, (int) $item->quantity),
            ],
            'funding' => [
                'supplier' => $funding->supplier->value,
                'supplier_order_id' => (string) $funding->supplier_order_id,
            ],
            'account' => $this->account($item, $secret, $now, self::CHALLENGE_ACCESS_PURPOSE),
        ];
    }

    /**
     * @return array<string, mixed>
     *
     * @throws PlacementRequestIncomplete
     */
    private function item(OrderItem $item, SupplierCostTable $costs, CarbonInterface $now): array
    {
        $configuration = is_array($item->configuration) ? $item->configuration : [];
        $secret = $this->secret($item);
        $account = $this->account($item, $secret, $now);

        $block = [
            'order_item_public_id' => (string) $item->public_id,
            'service' => $item->service_type->value,
            'platform' => $item->platform->value,
            'supplier_platform' => $this->supplierPlatform($item),
            'quantity' => (int) $item->quantity,
        ];

        try {
            if ($item->service_type === ServiceType::Coins) {
                $coins = (int) ($configuration['coins_quantity'] ?? 0);
                $delivery = DeliveryMode::tryFrom((string) ($configuration['delivery'] ?? ''));

                if ($coins <= 0) {
                    throw new PlacementRequestIncomplete('configuration_incomplete', "Order item {$item->public_id} has no coins quantity.");
                }

                $block['coins'] = [
                    'quantity' => $coins,
                    'delivery' => $delivery?->value,
                ];
                $block['budget'] = $costs->forCoins($item->platform, $delivery, $coins);
            } else {
                $block['sbc'] = [
                    'set_id' => $this->challengeSetId($item),
                    'times_to_solve' => max(1, (int) ($configuration['completion_count'] ?? 1)) * max(1, (int) $item->quantity),
                ];
                $block['budget'] = $costs->forChallenge($item->platform);
            }
        } catch (DomainException $exception) {
            throw new PlacementRequestIncomplete('budget_unavailable', $exception->getMessage());
        }

        $block['budget']['pricing_version'] = $costs->pricingVersion;
        $block['account'] = $account;

        return $block;
    }

    /**
     * Both suppliers speak `PS` and `PC`, and the Coins catalogue sells on
     * exactly those two platforms. Anything else has no supplier to go to and
     * is refused rather than mapped to a guess.
     *
     * @throws PlacementRequestIncomplete
     */
    private function supplierPlatform(OrderItem $item): string
    {
        return match ($item->platform) {
            Platform::PlayStation => 'PS',
            Platform::Pc => 'PC',
            default => throw new PlacementRequestIncomplete(
                'platform_unsupported',
                "Order item {$item->public_id} is on {$item->platform->value}, which no supplier serves.",
            ),
        };
    }

    /**
     * The SBC catalogue keys each challenge product by the EasySBC id, which
     * the catalogue's join check proves equal to FFT's `setID`
     * (`automation/n8n/sbc-catalog-v1/README.md`). A challenge whose product
     * cannot say which challenge it is cannot be placed.
     *
     * @throws PlacementRequestIncomplete
     */
    private function challengeSetId(OrderItem $item): int
    {
        $externalId = $item->productVariant?->product?->getAttribute('external_id');

        if (is_string($externalId)
            && str_starts_with($externalId, self::SBC_EXTERNAL_ID_PREFIX)
            && ctype_digit($setId = substr($externalId, strlen(self::SBC_EXTERNAL_ID_PREFIX)))) {
            return (int) $setId;
        }

        throw new PlacementRequestIncomplete(
            'challenge_unknown',
            "Order item {$item->public_id} names no supplier challenge id.",
        );
    }

    /** @throws PlacementRequestIncomplete */
    private function secret(OrderItem $item): OrderItemSecret
    {
        $secret = $item->secret;

        if (! $secret instanceof OrderItemSecret) {
            throw new PlacementRequestIncomplete('credentials_missing', "Order item {$item->public_id} holds no EA account.");
        }

        $retainedUntil = $secret->getAttribute('retained_until');

        if ($secret->deleted_at !== null
            || ($retainedUntil instanceof CarbonInterface && $retainedUntil->isPast())) {
            throw new PlacementRequestIncomplete('credentials_purged', "The EA account for order item {$item->public_id} was purged.");
        }

        return $secret;
    }

    /**
     * Decrypts for this request only and records that it did. A manual order
     * may legitimately hold an email without a password (staff signed in
     * themselves), but that item is not one a supplier can be sent.
     *
     * @return array<string, mixed>
     *
     * @throws PlacementRequestIncomplete
     */
    private function account(OrderItem $item, OrderItemSecret $secret, CarbonInterface $now, string $purpose = self::ACCESS_PURPOSE): array
    {
        $payload = is_array($secret->encrypted_payload) ? $secret->encrypted_payload : [];
        $email = $payload['ea_email'] ?? null;
        $password = $payload['ea_password'] ?? null;

        if (! is_string($email) || $email === '' || ! is_string($password) || $password === '') {
            throw new PlacementRequestIncomplete(
                'credentials_incomplete',
                "Order item {$item->public_id} holds no EA password to place with.",
            );
        }

        $codes = $payload['backup_codes'] ?? [];
        $codes = is_array($codes) ? array_values(array_filter($codes, 'is_string')) : [];
        $balance = $payload['current_balance'] ?? null;

        SecretAccessLog::query()->create([
            'order_item_secret_id' => $secret->id,
            'user_id' => null,
            'purpose' => $purpose,
            'case_reference' => null,
            'ip_address' => null,
            'accessed_at' => $now,
        ]);

        return [
            'ea_email' => $email,
            'ea_password' => $password,
            'backup_codes' => $codes,
            'current_balance' => is_int($balance) ? $balance : null,
            'credential_version' => (int) $secret->version,
        ];
    }
}
