<?php

namespace App\Http\Controllers\Store;

use App\Enums\DeliveryMode;
use App\Enums\Market;
use App\Enums\Platform;
use App\Enums\ServiceType;
use App\Http\Controllers\Controller;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\CartItemSecret;
use DateTimeImmutable;
use DateTimeInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Inertia\Inertia;
use Inertia\Response;

final class CartController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $activeCart = $request->user() === null ? null : Cart::query()
            ->activeForUser((int) $request->user()->id)
            ->with(['items.secret'])
            ->first();
        $safeCartItems = $activeCart?->items
            ->map(fn (CartItem $cartItem): array => $this->safeCartItem($cartItem))
            ->values()
            ->all() ?? [];

        $localized = $request->route('locale') === 'en';
        $homeUrl = $localized
            ? route('localized.home', ['locale' => 'en'], absolute: false)
            : route('home', absolute: false);

        return Inertia::render('store/cart', [
            'cartPage' => [
                'backUrl' => "{$homeUrl}#coins",
                'translations' => trans('store.cart_page'),
            ],
            'cart' => [
                'count' => count($safeCartItems),
                'currency' => 'SAR',
                'items' => $safeCartItems,
            ],
        ]);
    }

    /** @return array<string, mixed> */
    private function safeCartItem(CartItem $cartItem): array
    {
        $credentials = $this->safeCredentials($cartItem->secret);

        return [
            'id' => $cartItem->public_id,
            'quantity' => $cartItem->quantity,
            'unitPriceHalalah' => $cartItem->unit_price_halalah,
            'totalHalalah' => $cartItem->total_halalah,
            'configuration' => $this->safeConfiguration($cartItem->configuration),
            'credentials' => $credentials,
            'requiresCredentials' => $credentials === null,
        ];
    }

    /** @return array{maskedEmail: string, hasPassword: true, backupCodeCount: 5, retainedUntil: string}|null */
    private function safeCredentials(?CartItemSecret $secret): ?array
    {
        $retainedUntil = $secret?->getAttribute('retained_until');

        if ($secret === null
            || $secret->getRawOriginal('encrypted_payload') === null
            || $secret->getAttribute('deleted_at') !== null
            || ! $retainedUntil instanceof DateTimeInterface
            || $retainedUntil <= new DateTimeImmutable) {
            return null;
        }

        $summary = $secret->masked_summary;

        if (! is_array($summary)
            || ! is_string($summary['email'] ?? null)
            || strlen($summary['email']) > 254
            || ! $this->hasSafeMaskedEmail($summary['email'])
            || ($summary['has_password'] ?? null) !== true
            || ($summary['backup_code_count'] ?? null) !== 5) {
            return null;
        }

        return [
            'maskedEmail' => $summary['email'],
            'hasPassword' => true,
            'backupCodeCount' => 5,
            'retainedUntil' => $retainedUntil->format(DateTimeInterface::ATOM),
        ];
    }

    private function hasSafeMaskedEmail(string $maskedEmail): bool
    {
        return preg_match(
            '/\A[A-Za-z0-9]\*{3}@[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?(?:\.[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?)+\z/D',
            $maskedEmail,
        ) === 1;
    }

    /**
     * @param  array<string, mixed>|null  $configuration
     * @return array<string, int|string|null>
     */
    private function safeConfiguration(?array $configuration): array
    {
        if ($configuration === null) {
            return [];
        }

        return [
            ...$this->safeEnumField($configuration, 'service_type', ServiceType::cases()),
            ...$this->safeEnumField($configuration, 'platform', Platform::cases()),
            ...$this->safeEnumField($configuration, 'market', Market::cases()),
            ...$this->safeEnumField($configuration, 'delivery', DeliveryMode::cases()),
            ...$this->safeCoinsQuantity($configuration),
            ...$this->safeQuotedAt($configuration),
            ...$this->safePriceVersion($configuration),
        ];
    }

    /**
     * @param  array<string, mixed>  $configuration
     * @param  list<ServiceType|Platform|Market|DeliveryMode>  $allowedCases
     * @return array<string, string|null>
     */
    private function safeEnumField(array $configuration, string $field, array $allowedCases): array
    {
        if (! array_key_exists($field, $configuration)) {
            return [];
        }

        $configurationValue = $configuration[$field];

        if ($configurationValue === null) {
            return [$field => null];
        }

        $allowedValues = array_map(fn ($allowedCase): string => $allowedCase->value, $allowedCases);

        return is_string($configurationValue) && in_array($configurationValue, $allowedValues, true)
            ? [$field => $configurationValue]
            : [];
    }

    /**
     * @param  array<string, mixed>  $configuration
     * @return array{coins_quantity?: int}
     */
    private function safeCoinsQuantity(array $configuration): array
    {
        $quantity = $configuration['coins_quantity'] ?? null;
        $minimum = Config::integer('coins.quantity.minimum');
        $increment = Config::integer('coins.quantity.increment');
        $maximum = max(
            Config::integer('coins.platforms.playstation.maximum'),
            Config::integer('coins.platforms.pc.maximum'),
        );

        return is_int($quantity)
            && $quantity >= $minimum
            && $quantity <= $maximum
            && $quantity % $increment === 0
                ? ['coins_quantity' => $quantity]
                : [];
    }

    /**
     * @param  array<string, mixed>  $configuration
     * @return array{quoted_at?: string}
     */
    private function safeQuotedAt(array $configuration): array
    {
        $quotedAt = $configuration['quoted_at'] ?? null;

        if (! is_string($quotedAt)
            || DateTimeImmutable::createFromFormat(DateTimeInterface::ATOM, $quotedAt) === false
            || DateTimeImmutable::getLastErrors() !== false) {
            return [];
        }

        return ['quoted_at' => $quotedAt];
    }

    /**
     * @param  array<string, mixed>  $configuration
     * @return array{price_version?: int}
     */
    private function safePriceVersion(array $configuration): array
    {
        $priceVersion = $configuration['price_version'] ?? null;

        return is_int($priceVersion) && $priceVersion > 0
            ? ['price_version' => $priceVersion]
            : [];
    }
}
