<?php

namespace App\Actions\Checkout;

use App\Actions\Cart\RepriceCart;
use App\Checkout\AppliedCoupon;
use App\Checkout\CheckoutResult;
use App\Checkout\DiscountEngine;
use App\Checkout\DiscountResult;
use App\Checkout\OrderNumber;
use App\Enums\CouponRejection;
use App\Enums\OrderItemStatus;
use App\Enums\OrderStatus;
use App\Enums\OrderStatusHistoryStatus;
use App\Enums\PaymentStatus;
use App\Enums\Platform;
use App\Enums\ServiceType;
use App\Enums\WalletEntryType;
use App\Exceptions\Checkout\CartRepriced;
use App\Exceptions\Checkout\CheckoutUnavailable;
use App\Exceptions\Checkout\CouponRejected;
use App\Exceptions\Checkout\PricingRunInProgress;
use App\Exceptions\Checkout\StaleCartCoupon;
use App\Exceptions\IdempotencyConflict;
use App\Loyalty\Support\WalletLedgerWriter;
use App\Marketing\PromotionPrice;
use App\Marketing\PromotionPricing;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\CartItemSecret;
use App\Models\Coupon;
use App\Models\CouponRedemption;
use App\Models\FulfillmentAttachment;
use App\Models\IdempotencyKey;
use App\Models\IntegrationEvent;
use App\Models\Order;
use App\Models\OrderItemSecret;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Models\WalletEntry;
use App\Security\CheckoutFingerprint;
use App\Support\SafeOrderItemConfiguration;
use App\ValueObjects\Cart\CartItemPrice;
use App\ValueObjects\Cart\CartOwner;
use App\ValueObjects\Cart\CartRepricing;
use App\ValueObjects\Cart\ManualServiceCredentials;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use JsonException;

final readonly class PlaceOrder
{
    private const SCOPE = 'checkout';

    private const PAYLINK_MINIMUM_HALALAH = 500;

    public function __construct(
        private RepriceCart $repriceCart,
        private PromotionPricing $promotionPricing,
        private DiscountEngine $discountEngine,
        private WalletLedgerWriter $walletLedgerWriter,
    ) {}

    /**
     * @param  int|null  $expectedPayableHalalah  the cash payable the cart showed the
     *                                            customer, after the wallet deduction.
     * @param  int|null  $expectedOrderTotalHalalah  the order total the cart showed, before
     *                                               the wallet. Both are required together: the wallet absorbs
     *                                               movement in the first, so a fully covered cart computes a
     *                                               payable of zero whatever the order total does. Pinning only
     *                                               the payable would let a wallet be debited at a figure the
     *                                               customer never saw.
     */
    public function execute(
        User $user,
        string $locale,
        string $idempotencyKey,
        ?int $expectedPayableHalalah = null,
        ?int $expectedOrderTotalHalalah = null,
    ): CheckoutResult {
        if (! in_array($locale, ['ar', 'en'], true)) {
            throw new CheckoutUnavailable('The checkout locale is invalid.');
        }

        if (! is_string($user->phone)
            || preg_match('/\A\+[1-9][0-9]{7,14}\z/D', $user->phone) !== 1
            || $user->phone_verified_at === null) {
            throw new CheckoutUnavailable('A verified mobile number is required.');
        }

        try {
            return DB::transaction(
                fn (): CheckoutResult => $this->store(
                    $user,
                    $locale,
                    $idempotencyKey,
                    $expectedPayableHalalah,
                    $expectedOrderTotalHalalah,
                ),
                attempts: 3,
            );
        } catch (StaleCartCoupon $stale) {
            $this->detachCoupon($stale->cartId);

            throw new CheckoutUnavailable($stale->failure);
        } catch (CartRepriced $repriced) {
            // The coupon no longer qualifies for this cart, so it has to go
            // whether or not the customer confirms - leaving it attached would
            // reproduce the same refusal on every retry.
            if ($repriced->couponRemoved) {
                $this->detachCoupon($repriced->cartId);
            }

            throw $repriced;
        }
    }

    private function detachCoupon(int $cartId): void
    {
        Cart::query()
            ->whereKey($cartId)
            ->where('coupon_id', '>', 0)
            ->update(['coupon_id' => null]);
    }

    private function store(
        User $user,
        string $locale,
        string $idempotencyKey,
        ?int $expectedPayableHalalah = null,
        ?int $expectedOrderTotalHalalah = null,
    ): CheckoutResult {
        $scope = self::SCOPE.':user:'.$user->id;
        $existing = IdempotencyKey::query()->where('key', $idempotencyKey)->lockForUpdate()->first();

        if ($existing instanceof IdempotencyKey) {
            if ($existing->scope !== $scope) {
                throw new IdempotencyConflict;
            }

            return $this->replay($existing);
        }

        $cart = Cart::query()
            ->activeForOwner(CartOwner::user($user->id))
            ->lockForUpdate()
            ->first();

        if (! $cart instanceof Cart) {
            throw new CheckoutUnavailable('The active cart is unavailable.');
        }

        $cart->load([
            'items' => fn ($query) => $query->orderBy('id'),
            'items.secret',
            'items.squadImage',
        ]);

        if ($cart->items->isEmpty()) {
            throw new CheckoutUnavailable('The cart is empty.');
        }

        $claim = $this->claim(
            $idempotencyKey,
            $scope,
            CheckoutFingerprint::generate($cart, $locale, (string) config('app.key')),
        );
        // One repricing under locks, shared by every item below, so nothing can
        // read a different price than the total the customer is asked to confirm.
        $repricing = $this->repriceCart->execute($cart, lock: true);
        $snapshots = $cart->items->map(fn (CartItem $item): array => $this->validateItem($item, $repricing));

        $coupon = null;
        if ($cart->coupon_id !== null) {
            $coupon = Coupon::query()
                ->whereKey((int) $cart->coupon_id)
                ->with('targets')
                ->lockForUpdate()
                ->first();

            if (! $coupon instanceof Coupon) {
                throw new StaleCartCoupon((int) $cart->id, 'The applied coupon is unavailable.');
            }
        }

        $couponRemoved = false;

        try {
            $discountResult = $this->discountEngine->calculateForSnapshots($snapshots, $coupon, $user);
        } catch (CouponRejected $exception) {
            if ($exception->reason !== CouponRejection::Minimum) {
                throw new StaleCartCoupon(
                    (int) $cart->id,
                    'The applied coupon is no longer valid.',
                    previous: $exception,
                );
            }

            // Repricing dropped the cart under the coupon's minimum. The engine
            // throws before it produces any total, so ask it again without the
            // coupon - the customer has to see what they would actually pay.
            $discountResult = $this->discountEngine->calculateForSnapshots($snapshots, null, $user);
            $couponRemoved = true;
        }

        $subtotal = $discountResult->promotedSubtotalHalalah;
        $appliedCoupon = $discountResult->appliedCoupon;
        $discountHalalah = $appliedCoupon instanceof AppliedCoupon
            ? $appliedCoupon->discountHalalah
            : 0;
        $totalHalalah = $discountResult->payableTotalHalalah;

        $walletAccount = null;
        $walletPart = 0;

        if ((bool) $cart->use_wallet) {
            $walletAccount = $this->walletLedgerWriter->lockAccountFor($user->id);
            $walletBalance = max(0, (int) $walletAccount->balance_halalah);
            $walletPart = min($walletBalance, $totalHalalah);
        }

        $paymentHalalah = $totalHalalah - $walletPart;

        // Refuse before the wallet is debited: everything below this line moves
        // money. Both figures are compared - the wallet absorbs movement in the
        // payable, so the order total is what catches a price change on a fully
        // covered cart.
        $totalsMoved = ($expectedOrderTotalHalalah !== null && $expectedOrderTotalHalalah !== $totalHalalah)
            || ($expectedPayableHalalah !== null && $expectedPayableHalalah !== $paymentHalalah);

        if ($couponRemoved || $totalsMoved) {
            throw new CartRepriced(
                (int) $cart->id,
                $totalHalalah,
                $expectedOrderTotalHalalah ?? $totalHalalah,
                $paymentHalalah,
                $expectedPayableHalalah ?? $paymentHalalah,
                $couponRemoved,
            );
        }

        // Checked after the confirmation gate: a downward reprice can newly trip
        // these floors, and the customer should meet the new total first rather
        // than a bare refusal that never mentions the price moved.
        if ($subtotal < self::PAYLINK_MINIMUM_HALALAH) {
            throw new CheckoutUnavailable('The order total is below the Paylink minimum.');
        }

        if ($paymentHalalah > 0 && $paymentHalalah < self::PAYLINK_MINIMUM_HALALAH) {
            $gapHalalah = self::PAYLINK_MINIMUM_HALALAH - $paymentHalalah;
            // Integer-only: halalah never becomes a float, and the currency word
            // lives in the translated string so Arabic does not carry "SAR".
            $formattedGap = intdiv($gapHalalah, 100).'.'.str_pad((string) ($gapHalalah % 100), 2, '0', STR_PAD_LEFT);
            throw new CheckoutUnavailable((string) trans('store.checkout.paylink_minimum_gap', ['gap' => $formattedGap], locale: $locale));
        }

        $fullyPaidByWallet = $paymentHalalah === 0;

        $order = Order::create([
            'user_id' => $user->id,
            'order_number' => OrderNumber::generate(),
            'status' => $fullyPaidByWallet ? OrderStatus::Received : OrderStatus::PendingPayment,
            'locale' => $locale,
            'currency' => 'SAR',
            'subtotal_halalah' => $subtotal,
            'discount_halalah' => $discountHalalah,
            'wallet_halalah' => $walletPart,
            'payment_halalah' => $paymentHalalah,
            'total_halalah' => $totalHalalah,
            'placed_at' => now(),
            'paid_at' => $fullyPaidByWallet ? now() : null,
        ]);

        foreach ($snapshots as $index => $snapshot) {
            $this->createOrderItem(
                $order,
                $snapshot,
                $fullyPaidByWallet ? OrderItemStatus::Received : OrderItemStatus::PendingPayment,
                $discountResult,
                $index,
            );
        }

        if ($appliedCoupon instanceof AppliedCoupon) {
            $this->recordCouponRedemption($order, $appliedCoupon);
        }

        if ($walletPart > 0 && $walletAccount !== null) {
            $reference = "order-wallet:{$order->id}";
            $existingDebit = $this->walletLedgerWriter->lockedEntryByReference($reference);

            if (! $existingDebit instanceof WalletEntry) {
                $this->walletLedgerWriter->append($walletAccount, [
                    'type' => WalletEntryType::Debit,
                    'amount_halalah' => $walletPart,
                    'balance_delta_halalah' => -$walletPart,
                    'order_id' => $order->id,
                    'refund_id' => null,
                    'created_by_user_id' => null,
                    'reference' => $reference,
                    'metadata' => [
                        'order_number' => $order->order_number,
                    ],
                ]);
            }
        }

        if ($fullyPaidByWallet) {
            $payment = $order->payments()->create([
                'provider' => 'wallet',
                'provider_payment_id' => null,
                'status' => PaymentStatus::Paid,
                'currency' => 'SAR',
                'amount_halalah' => 0,
                'captured_halalah' => 0,
                'refunded_halalah' => 0,
                'idempotency_key' => 'wallet:'.hash('sha256', $scope.'|'.$idempotencyKey),
                'provider_metadata' => null,
                'paid_at' => now(),
            ]);
            $order->statusHistory()->create([
                'actor_user_id' => $user->id,
                'status' => OrderStatusHistoryStatus::Received,
                'metadata' => ['source' => 'wallet'],
            ]);
            IntegrationEvent::create([
                'event_id' => (string) Str::ulid(),
                'event_type' => 'order.paid',
                'aggregate_type' => 'order',
                'aggregate_id' => $order->public_id,
                'schema_version' => 1,
                'payload' => [
                    'order_public_id' => $order->public_id,
                    'order_number' => $order->order_number,
                    'locale' => $order->locale,
                    'currency' => $order->currency,
                    'total_halalah' => $order->total_halalah,
                    'item_count' => $order->items()->count(),
                ],
                'status' => 'pending',
                'idempotency_key' => 'order-paid:'.$order->id,
                'attempts' => 0,
                'available_at' => now(),
            ]);
        } else {
            $payment = $order->payments()->create([
                'provider' => 'paylink',
                'provider_payment_id' => null,
                'status' => PaymentStatus::Pending,
                'currency' => 'SAR',
                'amount_halalah' => $paymentHalalah,
                'captured_halalah' => 0,
                'refunded_halalah' => 0,
                'idempotency_key' => 'paylink:'.hash('sha256', $scope.'|'.$idempotencyKey),
                'provider_metadata' => null,
            ]);
            $order->statusHistory()->create([
                'actor_user_id' => $user->id,
                'status' => OrderStatusHistoryStatus::PendingPayment,
                'metadata' => ['source' => 'checkout'],
            ]);
        }

        $cart->update(['status' => 'converted']);
        $this->completeClaim($claim, $order, $payment);

        return new CheckoutResult($order, $payment, false);
    }

    private function recordCouponRedemption(Order $order, AppliedCoupon $appliedCoupon): void
    {
        $order->discounts()->create([
            'coupon_id' => $appliedCoupon->couponId,
            'type' => $appliedCoupon->discountType,
            'label_ar' => 'كوبون الخصم '.$appliedCoupon->code,
            'label_en' => 'Coupon '.$appliedCoupon->code,
            'amount_halalah' => $appliedCoupon->discountHalalah,
            'metadata' => array_filter([
                'code' => $appliedCoupon->code,
                'allocations' => $appliedCoupon->allocations !== [] ? $appliedCoupon->allocations : null,
            ]),
        ]);

        CouponRedemption::create([
            'public_id' => (string) Str::ulid(),
            'coupon_id' => $appliedCoupon->couponId,
            'user_id' => $order->user_id,
            'order_id' => $order->id,
        ]);
    }

    private function claim(string $key, string $scope, string $requestHash): IdempotencyKey
    {
        DB::table('idempotency_keys')->insertOrIgnore([
            'public_id' => (string) Str::ulid(),
            'key' => $key,
            'scope' => $scope,
            'request_hash' => $requestHash,
            'expires_at' => now()->addDay(),
            'created_at' => now(),
        ]);
        $claim = IdempotencyKey::query()->where('key', $key)->lockForUpdate()->firstOrFail();

        if ($claim->scope !== $scope || ! hash_equals((string) $claim->request_hash, $requestHash)) {
            throw new IdempotencyConflict;
        }

        return $claim;
    }

    /** @return array<string, mixed> */
    private function validateItem(CartItem $item, CartRepricing $repricing): array
    {
        $configuration = $item->configuration;

        if (! is_array($configuration)
            || ! isset($configuration['service_type'], $configuration['platform'], $configuration['price_version'])
            || ! is_string($configuration['service_type'])
            || ! is_string($configuration['platform'])
            || ! is_int($configuration['price_version'])) {
            throw new CheckoutUnavailable('A cart item is invalid.');
        }

        $service = ServiceType::tryFrom($configuration['service_type']);
        $platform = Platform::tryFrom($configuration['platform']);

        if (! $service instanceof ServiceType || ! $platform instanceof Platform) {
            throw new CheckoutUnavailable('A cart item is invalid.');
        }

        $price = $repricing->for($item);

        if ($price->pricingRunInProgress) {
            throw new PricingRunInProgress;
        }

        if (! $price->isPriced() || ! $price->variant instanceof ProductVariant) {
            throw new CheckoutUnavailable('A cart item is unavailable.');
        }

        $variant = $price->variant;

        if (! $variant->product instanceof Product) {
            throw new CheckoutUnavailable('A cart item is unavailable.');
        }

        // The stored price is no longer compared against the live one: the live
        // one is adopted, and the customer confirms the resulting total before
        // anything is charged. Quantity is still ours to police.
        if ($item->quantity < 1
            || (in_array($service, [
                ServiceType::Coins,
                ServiceType::Sbc,
                ServiceType::FutChampions,
                ServiceType::Rivals,
            ], true) && $item->quantity !== 1)) {
            throw new CheckoutUnavailable('A cart item is invalid.');
        }

        $isManualService = $this->isManualService($service);
        $secret = match (true) {
            $isManualService => $this->requiredManualSecret($item, $configuration),
            in_array($service, [ServiceType::Coins, ServiceType::Sbc], true) => $this->requiredSecret($item),
            default => null,
        };
        $attachment = $isManualService ? $this->requiredManualAttachment($item) : null;
        $category = $variant->product->category;

        return [
            'variant' => $variant,
            'service_type' => $service,
            'platform' => $platform,
            'quantity' => $item->quantity,
            'unit_price_halalah' => (int) $price->unitPriceHalalah,
            'total_halalah' => (int) $price->totalHalalah,
            'promotion' => $this->promotionPricing->resolve(
                $category?->id,
                $service,
                (int) $price->totalHalalah,
                $variant->product->id,
            ),
            'configuration' => $this->safeConfiguration(
                $this->withLiveVersions($configuration, $price, $service),
                $service,
            ),
            'secret' => $secret,
            'attachment' => $attachment,
        ];
    }

    /**
     * Stamp the order item with the versions it was actually charged at.
     * Carrying the cart's versions forward would document a price nobody paid.
     *
     * @param  array<string, mixed>  $configuration
     * @return array<string, mixed>
     */
    private function withLiveVersions(array $configuration, CartItemPrice $price, ServiceType $service): array
    {
        $configuration['price_version'] = (int) $price->priceVersion;

        if ($price->quotedAt !== null) {
            $configuration['quoted_at'] = $price->quotedAt;
        }

        // Only the manual services carry a schedule version; the other
        // allow-lists would drop the key silently.
        if ($this->isManualService($service) && $price->scheduleVersion !== null) {
            $configuration['schedule_version'] = $price->scheduleVersion;
        }

        return $configuration;
    }

    /** @param array<string, mixed> $configuration */
    private function requiredManualSecret(CartItem $item, array $configuration): CartItemSecret
    {
        $secret = $item->secret;
        $payload = $secret?->encrypted_payload;

        if (! $secret instanceof CartItemSecret
            || $secret->deleted_at !== null
            || ! is_array($payload)) {
            throw new CheckoutUnavailable('Manual-service account details are required.');
        }

        try {
            $credentials = ManualServiceCredentials::fromValidated($payload);
        } catch (DomainException $exception) {
            throw new CheckoutUnavailable('Manual-service account details are invalid.', previous: $exception);
        }

        if ($credentials->payload() !== $payload
            || $credentials->maskedSummary() !== $secret->masked_summary
            || $payload['platform'] !== $configuration['platform']
            || ($payload['pc_store'] ?? null) !== $configuration['pc_store']) {
            throw new CheckoutUnavailable('Manual-service account details are invalid.');
        }

        return $secret;
    }

    private function requiredManualAttachment(CartItem $item): FulfillmentAttachment
    {
        $attachment = FulfillmentAttachment::query()
            ->where('cart_item_id', $item->id)
            ->whereNull('order_item_id')
            ->where('kind', 'squad_image')
            ->lockForUpdate()
            ->first();

        if (! $attachment instanceof FulfillmentAttachment) {
            throw new CheckoutUnavailable('A squad image is required.');
        }

        $disk = Storage::disk($attachment->disk);

        if ($attachment->disk !== 'local'
            || ! in_array($attachment->mime_type, ['image/jpeg', 'image/png', 'image/webp'], true)
            || $attachment->bytes < 1
            || $attachment->bytes > 5 * 1024 * 1024
            || preg_match('/\A[a-f0-9]{64}\z/D', $attachment->sha256) !== 1
            || ! $disk->exists($attachment->path)) {
            throw new CheckoutUnavailable('The squad image is unavailable.');
        }

        $path = $disk->path($attachment->path);
        $sha256 = hash_file('sha256', $path);

        if (! is_string($sha256)
            || ! hash_equals($attachment->sha256, $sha256)
            || $disk->size($attachment->path) !== $attachment->bytes) {
            throw new CheckoutUnavailable('The squad image is invalid.');
        }

        return $attachment;
    }

    private function isManualService(ServiceType $service): bool
    {
        return in_array($service, [ServiceType::FutChampions, ServiceType::Rivals], true);
    }

    private function requiredSecret(CartItem $item): CartItemSecret
    {
        $secret = $item->secret;
        $payload = $secret?->encrypted_payload;

        if (! $secret instanceof CartItemSecret
            || $secret->deleted_at !== null
            || ! is_array($payload)
            || ! isset($payload['ea_email'], $payload['ea_password'], $payload['backup_codes'])
            || ! is_string($payload['ea_email'])
            || filter_var($payload['ea_email'], FILTER_VALIDATE_EMAIL) === false
            || ! is_string($payload['ea_password'])
            || $payload['ea_password'] === ''
            || ! is_array($payload['backup_codes'])
            || count($payload['backup_codes']) !== 3
            || count(array_unique($payload['backup_codes'])) !== 3
            || collect($payload['backup_codes'])->contains(fn (mixed $code): bool => ! is_string($code)
                || preg_match('/\A[0-9]{8}\z/D', $code) !== 1)) {
            throw new CheckoutUnavailable('EA account details are required.');
        }

        return $secret;
    }

    /** @param array<string, mixed> $configuration
     * @return array<string, mixed>
     */
    private function safeConfiguration(array $configuration, ServiceType $service): array
    {
        return SafeOrderItemConfiguration::project($configuration, $service);
    }

    /** @param array<string, mixed> $snapshot */
    private function createOrderItem(
        Order $order,
        array $snapshot,
        OrderItemStatus $status = OrderItemStatus::PendingPayment,
        ?DiscountResult $discountResult = null,
        int|string|null $lineId = null,
    ): void {
        /** @var ProductVariant $variant */
        $variant = $snapshot['variant'];
        /** @var Product $product */
        $product = $variant->product;
        /** @var PromotionPrice|null $promotion */
        $promotion = $lineId !== null && $discountResult instanceof DiscountResult
            ? $discountResult->linePromotion($lineId)
            : ($snapshot['promotion'] instanceof PromotionPrice ? $snapshot['promotion'] : null);
        $promotionDiscountHalalah = $lineId !== null && $discountResult instanceof DiscountResult
            ? ($discountResult->linePromotionDiscounts[$lineId] ?? 0)
            : ($promotion instanceof PromotionPrice ? $promotion->discountHalalah : 0);
        $orderItem = $order->items()->create([
            'product_variant_id' => $variant->id,
            'sku' => $variant->sku,
            'name_ar' => $product->name_ar,
            'name_en' => $product->name_en,
            'service_type' => $snapshot['service_type'],
            'platform' => $snapshot['platform'],
            'status' => $status,
            'quantity' => $snapshot['quantity'],
            'unit_price_halalah' => $snapshot['unit_price_halalah'],
            'subtotal_halalah' => $snapshot['total_halalah'],
            'discount_halalah' => $promotionDiscountHalalah,
            'promotion_id' => $promotion?->promotion->id,
            'promotion_discount_halalah' => $promotionDiscountHalalah,
            'total_halalah' => (int) $snapshot['total_halalah'] - $promotionDiscountHalalah,
            'configuration' => $snapshot['configuration'],
        ]);

        if ($snapshot['secret'] instanceof CartItemSecret) {
            $payload = $snapshot['secret']->encrypted_payload;

            if (! is_array($payload)) {
                throw new CheckoutUnavailable('EA account details are required.');
            }

            $secret = new OrderItemSecret([
                'order_item_id' => $orderItem->id,
                'masked_summary' => $snapshot['secret']->masked_summary,
                'retained_until' => $snapshot['secret']->retained_until,
                'deleted_at' => null,
            ]);
            $secret->encrypted_payload = $payload;
            $secret->save();
        }

        if ($snapshot['attachment'] instanceof FulfillmentAttachment) {
            $snapshot['attachment']->update([
                'cart_item_id' => null,
                'order_item_id' => $orderItem->id,
            ]);
        }
    }

    private function completeClaim(IdempotencyKey $claim, Order $order, Payment $payment): void
    {
        $claim->forceFill([
            'response_status' => 201,
            'response_body' => json_encode([
                'orderId' => $order->public_id,
                'paymentId' => $payment->public_id,
            ], JSON_THROW_ON_ERROR),
        ])->save();
    }

    private function replay(IdempotencyKey $claim): CheckoutResult
    {
        try {
            $body = json_decode((string) $claim->response_body, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new IdempotencyConflict;
        }

        if (! is_array($body)
            || ! is_string($body['orderId'] ?? null)
            || ! is_string($body['paymentId'] ?? null)) {
            throw new IdempotencyConflict;
        }

        $order = Order::query()->where('public_id', $body['orderId'])->first();
        $payment = Payment::query()
            ->where('public_id', $body['paymentId'])
            ->where('order_id', $order?->id)
            ->first();

        if (! $order instanceof Order || ! $payment instanceof Payment) {
            throw new IdempotencyConflict;
        }

        return new CheckoutResult($order, $payment, true);
    }
}
