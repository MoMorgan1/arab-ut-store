<?php

namespace App\Actions\Checkout;

use App\Actions\Pricing\QuoteCoins;
use App\Checkout\CheckoutResult;
use App\Enums\DeliveryMode;
use App\Enums\OrderItemStatus;
use App\Enums\OrderStatus;
use App\Enums\OrderStatusHistoryStatus;
use App\Enums\PaymentStatus;
use App\Enums\Platform;
use App\Enums\ServiceType;
use App\Exceptions\Checkout\CheckoutUnavailable;
use App\Exceptions\IdempotencyConflict;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\CartItemSecret;
use App\Models\IdempotencyKey;
use App\Models\Order;
use App\Models\OrderItemSecret;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Security\CheckoutFingerprint;
use App\ValueObjects\Cart\CartOwner;
use App\ValueObjects\Pricing\SbcCompletionPricing;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use JsonException;

final readonly class PlaceOrder
{
    private const SCOPE = 'checkout';

    public function __construct(private QuoteCoins $quoteCoins) {}

    public function execute(User $user, string $locale, string $idempotencyKey): CheckoutResult
    {
        if (! in_array($locale, ['ar', 'en'], true)) {
            throw new CheckoutUnavailable('The checkout locale is invalid.');
        }

        if (! is_string($user->phone)
            || preg_match('/\A\+[1-9][0-9]{7,14}\z/D', $user->phone) !== 1
            || $user->phone_verified_at === null) {
            throw new CheckoutUnavailable('A verified mobile number is required.');
        }

        return DB::transaction(
            fn (): CheckoutResult => $this->store($user, $locale, $idempotencyKey),
            attempts: 3,
        );
    }

    private function store(User $user, string $locale, string $idempotencyKey): CheckoutResult
    {
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

        $cart->load(['items' => fn ($query) => $query->orderBy('id'), 'items.secret']);

        if ($cart->items->isEmpty()) {
            throw new CheckoutUnavailable('The cart is empty.');
        }

        $claim = $this->claim(
            $idempotencyKey,
            $scope,
            CheckoutFingerprint::generate($cart, $locale, (string) config('app.key')),
        );
        $snapshots = $cart->items->map(fn (CartItem $item): array => $this->validateItem($item));
        $subtotal = (int) $snapshots->sum('total_halalah');

        if ($subtotal < 500) {
            throw new CheckoutUnavailable('The order total is below the Paylink minimum.');
        }

        $order = Order::create([
            'user_id' => $user->id,
            'order_number' => 'AUT-'.Str::upper((string) Str::ulid()),
            'status' => OrderStatus::PendingPayment,
            'locale' => $locale,
            'currency' => 'SAR',
            'subtotal_halalah' => $subtotal,
            'discount_halalah' => 0,
            'wallet_halalah' => 0,
            'payment_halalah' => $subtotal,
            'total_halalah' => $subtotal,
            'placed_at' => now(),
        ]);

        foreach ($snapshots as $snapshot) {
            $this->createOrderItem($order, $snapshot);
        }

        $payment = $order->payments()->create([
            'provider' => 'paylink',
            'provider_payment_id' => null,
            'status' => PaymentStatus::Pending,
            'currency' => 'SAR',
            'amount_halalah' => $subtotal,
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
        $cart->update(['status' => 'converted']);
        $this->completeClaim($claim, $order, $payment);

        return new CheckoutResult($order, $payment, false);
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
    private function validateItem(CartItem $item): array
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

        $variant = ProductVariant::query()
            ->whereKey($item->product_variant_id)
            ->where('is_active', true)
            ->with('product.category')
            ->lockForUpdate()
            ->first();

        if (! $variant instanceof ProductVariant
            || ! $variant->product instanceof Product
            || ! $variant->product->is_visible
            || $variant->product->archived_at !== null
            || ($variant->product->category !== null && ! $variant->product->category->is_visible)
            || $variant->service_type !== $service
            || $variant->product->service_type !== $service
            || $variant->platform !== $platform) {
            throw new CheckoutUnavailable('A cart item is unavailable.');
        }

        [$currentUnit, $currentTotal] = $this->currentPrices(
            $item,
            $variant,
            $service,
            $platform,
            $configuration,
        );

        if ($configuration['price_version'] !== $variant->price_version
            || $item->quantity < 1
            || (in_array($service, [ServiceType::Coins, ServiceType::Sbc], true) && $item->quantity !== 1)
            || $item->unit_price_halalah !== $currentUnit
            || $item->total_halalah !== $currentTotal) {
            throw new CheckoutUnavailable('The cart price has changed.');
        }

        $secret = in_array($service, [ServiceType::Coins, ServiceType::Sbc], true)
            ? $this->requiredSecret($item)
            : null;

        return [
            'variant' => $variant,
            'service_type' => $service,
            'platform' => $platform,
            'quantity' => $item->quantity,
            'unit_price_halalah' => $item->unit_price_halalah,
            'total_halalah' => $item->total_halalah,
            'configuration' => $this->safeConfiguration($configuration, $service),
            'secret' => $secret,
        ];
    }

    /** @param array<string, mixed> $configuration
     * @return array{int, int}
     */
    private function currentPrices(
        CartItem $item,
        ProductVariant $variant,
        ServiceType $service,
        Platform $platform,
        array $configuration,
    ): array {
        if ($service === ServiceType::Coins) {
            $quantity = $configuration['coins_quantity'] ?? null;
            $delivery = $configuration['delivery'] ?? null;

            if (! is_int($quantity)) {
                throw new CheckoutUnavailable('A cart item is invalid.');
            }

            $deliveryMode = is_string($delivery) ? DeliveryMode::tryFrom($delivery) : null;
            $quote = $this->quoteCoins->execute($platform, $deliveryMode, $quantity);

            if ($quote->variantId !== $variant->public_id || $quote->priceVersion !== $variant->price_version) {
                throw new CheckoutUnavailable('The cart price has changed.');
            }

            return [$quote->total->halalah(), $quote->total->halalah()];
        }

        $effective = $variant->sale_price_halalah ?? $variant->price_halalah;

        if ($service === ServiceType::Sbc) {
            $completionCount = $configuration['completion_count'] ?? null;

            if (! is_int($completionCount) || $completionCount < 1 || $completionCount > 100) {
                throw new CheckoutUnavailable('A cart item is invalid.');
            }

            try {
                $pricing = SbcCompletionPricing::fromConfiguration(
                    is_array($variant->configuration) ? $variant->configuration : [],
                    $effective,
                    requireDeclared: false,
                );
            } catch (DomainException $exception) {
                throw new CheckoutUnavailable('A cart item is invalid.', previous: $exception);
            }

            $tierTotal = $pricing->tierTotal($completionCount);

            if ($tierTotal === null) {
                throw new CheckoutUnavailable('The cart price has changed.');
            }

            return [$tierTotal, $tierTotal];
        }

        return [$effective, $effective * $item->quantity];
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
        $keys = ['service_type', 'platform', 'market', 'quoted_at', 'price_version'];

        if ($service === ServiceType::Coins) {
            array_push($keys, 'delivery', 'coins_quantity');
        }

        if ($service === ServiceType::Sbc) {
            $keys[] = 'completion_count';
        }

        return array_intersect_key($configuration, array_flip($keys));
    }

    /** @param array<string, mixed> $snapshot */
    private function createOrderItem(Order $order, array $snapshot): void
    {
        /** @var ProductVariant $variant */
        $variant = $snapshot['variant'];
        /** @var Product $product */
        $product = $variant->product;
        $orderItem = $order->items()->create([
            'product_variant_id' => $variant->id,
            'sku' => $variant->sku,
            'name_ar' => $product->name_ar,
            'name_en' => $product->name_en,
            'service_type' => $snapshot['service_type'],
            'platform' => $snapshot['platform'],
            'status' => OrderItemStatus::PendingPayment,
            'quantity' => $snapshot['quantity'],
            'unit_price_halalah' => $snapshot['unit_price_halalah'],
            'subtotal_halalah' => $snapshot['total_halalah'],
            'discount_halalah' => 0,
            'total_halalah' => $snapshot['total_halalah'],
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
