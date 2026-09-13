<?php

namespace App\Http\Controllers\Admin;

use App\Admin\Actions\CreateManualOrder;
use App\Admin\ManualOrder\ManualOrderDraft;
use App\Admin\ManualOrder\ManualOrderItemDraft;
use App\Admin\ManualOrder\ManualOrderPayment;
use App\Admin\ManualOrder\ManualOrderPlacement;
use App\Enums\DeliveryPhase;
use App\Enums\Platform;
use App\Enums\ServiceType;
use App\Enums\Supplier;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\CreateManualOrderRequest;
use App\Models\ProductVariant;
use App\Models\User;
use App\Support\PublicHandle\CustomerHandle;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use RuntimeException;

/**
 * Creates a manual order from the drawer on the orders screen.
 *
 * Everything consequential lives in `CreateManualOrder`; this turns a validated
 * request into the draft that action takes, and resolves the two things only an
 * HTTP boundary knows about - the customer behind a public handle, and the
 * catalogue variant behind one.
 */
final class ManualOrderController extends Controller
{
    public function __construct(
        private readonly CreateManualOrder $createManualOrder,
    ) {}

    public function store(CreateManualOrderRequest $request): RedirectResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        $customer = CustomerHandle::resolveForAdmin((string) $request->validated('customer_id'));

        $order = $this->createManualOrder->execute(
            $actor,
            $customer,
            $request->route('locale') === 'en' ? 'en' : 'ar',
            $this->draft($request),
            $request->ip(),
        );

        $prefix = $request->route('locale') === 'en' ? '/en/admin' : '/admin';

        return redirect()
            ->to("{$prefix}/orders/{$order->order_number}")
            ->with('status', 'order-created');
    }

    private function draft(CreateManualOrderRequest $request): ManualOrderDraft
    {
        $isGift = (bool) $request->validated('is_gift');

        /** @var array<int, array<string, mixed>> $items */
        $items = $request->validated('items');

        return new ManualOrderDraft(
            $isGift,
            $this->payment($request, $isGift),
            array_values(array_map(
                fn (array $item): ManualOrderItemDraft => $this->item($item),
                $items,
            )),
        );
    }

    private function payment(CreateManualOrderRequest $request, bool $isGift): ?ManualOrderPayment
    {
        if ($isGift) {
            return null;
        }

        /** @var array<string, mixed> $payment */
        $payment = $request->validated('payment');

        return new ManualOrderPayment(
            (int) $payment['amount_halalah'],
            (string) $payment['reference'],
            CarbonImmutable::parse((string) $payment['received_at']),
        );
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function item(array $item): ManualOrderItemDraft
    {
        $service = ServiceType::from((string) $item['service_type']);
        $platform = Platform::from((string) $item['platform']);
        $variant = $this->variant($item['product_variant_id'] ?? null);

        /** @var array<string, mixed> $configuration */
        $configuration = is_array($item['configuration'] ?? null) ? $item['configuration'] : [];

        return new ManualOrderItemDraft(
            $service,
            $platform,
            $variant?->id,
            // A variant's own sku when one was picked, and a marked snapshot
            // when the order does not name a catalogue row. The column is a
            // required text snapshot rather than a foreign key, which is what
            // lets a manual order exist without one.
            $variant instanceof ProductVariant ? $variant->sku : 'MANUAL-'.strtoupper($service->value),
            $variant?->product->name_ar ?? $this->fallbackName($service, 'ar'),
            $variant?->product->name_en ?? $this->fallbackName($service, 'en'),
            (int) $item['price_halalah'],
            // The service and platform are written into the configuration too,
            // because the allowlist carries them and every reader of a stored
            // configuration expects them there.
            [
                ...$configuration,
                'service_type' => $service->value,
                'platform' => $platform->value,
            ],
            is_array($item['credentials'] ?? null) && $item['credentials'] !== [] ? $item['credentials'] : null,
            $this->placement($item['placement'] ?? null),
        );
    }

    private function variant(mixed $handle): ?ProductVariant
    {
        if (! is_string($handle) || $handle === '') {
            return null;
        }

        /** @var ProductVariant|null $variant */
        $variant = ProductVariant::query()
            ->with('product')
            ->where('public_id', $handle)
            ->first();

        if (! $variant instanceof ProductVariant) {
            throw new RuntimeException('The product chosen no longer exists.');
        }

        return $variant;
    }

    private function placement(mixed $placement): ?ManualOrderPlacement
    {
        if (! is_array($placement) || $placement === []) {
            return null;
        }

        $challengeIds = $placement['challenge_ids'] ?? [];

        return new ManualOrderPlacement(
            Supplier::from((string) $placement['supplier']),
            (string) $placement['supplier_order_id'],
            DeliveryPhase::from((string) $placement['delivery_phase']),
            is_array($challengeIds) ? array_values(array_map('strval', $challengeIds)) : [],
        );
    }

    private function fallbackName(ServiceType $service, string $locale): string
    {
        return trans("admin.orders.services.{$service->value}", locale: $locale);
    }
}
