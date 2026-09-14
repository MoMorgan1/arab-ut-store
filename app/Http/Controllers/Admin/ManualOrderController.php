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
use App\Exceptions\ManualOrderPlacementRefused;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\CreateManualOrderRequest;
use App\Models\ProductVariant;
use App\Models\User;
use App\Support\PublicHandle\CustomerHandle;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;
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

        $draft = $this->draft($request);

        try {
            $order = $this->createManualOrder->execute(
                $actor,
                $customer,
                $this->customerLocale(),
                $draft,
                $request->ip(),
            );
        } catch (ManualOrderPlacementRefused $refused) {
            // A reference already used, or a challenge the supplier cannot
            // solve, is mistyping - not an outside failure. It comes back as an
            // error on the field at fault, with every other field still filled.
            throw ValidationException::withMessages([
                $this->placementField($draft, $refused->supplierOrderId) => [
                    $this->placementMessage($refused->outcome),
                ],
            ]);
        }

        return redirect()
            ->route($this->routePrefix($request).'orders.show', ['order' => $order->order_number])
            ->with('status', 'order-created');
    }

    /**
     * Names the item whose reference was refused, so the message lands on the
     * card that carries it rather than at the top of a form with ten of them.
     */
    private function placementField(ManualOrderDraft $draft, string $supplierOrderId): string
    {
        foreach ($draft->items as $index => $item) {
            if ($item->placement?->supplierOrderId === $supplierOrderId) {
                return "items.{$index}.placement.supplier_order_id";
            }
        }

        return 'items';
    }

    /**
     * Written for the person filling the form. Every outcome the drawer can
     * actually provoke is named; the rest are shapes its own validation and
     * `ManualOrderOptions` already prevent, so they share one honest fallback
     * rather than a wrong specific guess.
     */
    private function placementMessage(string $outcome): string
    {
        return match ($outcome) {
            'supplier_reference_conflict' => 'That reference is already on another order.',
            'item_conflict', 'placement_conflict' => 'This item already has a supplier reference.',
            'challenge_ids_required' => 'A challenge already placed needs the challenge IDs, or nothing can track it.',
            'challenge_ids_not_permitted' => 'The coins phase carries no challenge IDs.',
            'invalid_challenge_ids' => 'Those challenge IDs are not the IDs the supplier issues.',
            'supplier_cannot_solve_challenges' => 'This supplier does not deliver challenges.',
            'service_has_no_challenge' => 'This service has no challenge phase.',
            'not_automated' => 'Only Coins and SBC are delivered by a supplier, so only they carry a reference.',
            default => 'The supplier reference could not be recorded, so the order was not created.',
        };
    }

    /**
     * `orders.locale` is the CUSTOMER's language, not the staff member's: it
     * builds the tracking link they open (`IssueOrderTrackingLink:113`) and the
     * Paylink line titles. Both admin prefixes are registered as `en`
     * (routes/admin.php:620) because the admin is English-only, so reading the
     * route here would have stamped every manual order English and handed the
     * owner an English link to send a Gulf customer.
     *
     * `store.default_locale` rather than `app.locale`, because the framework
     * rewrites the latter to whatever the current request is being served in -
     * inside an admin request it reads `en`, which is the bug this replaces.
     */
    private function customerLocale(): string
    {
        return config('store.default_locale') === 'en' ? 'en' : 'ar';
    }

    /**
     * Keeps the redirect on the prefix the form was submitted from. Derived
     * from the route name for the same reason `AdminCouponsPage` does it: both
     * admin prefixes carry the locale `en`, so the locale cannot tell them
     * apart and `/admin` would bounce to `/en/admin`.
     */
    private function routePrefix(CreateManualOrderRequest $request): string
    {
        return str_starts_with((string) $request->route()?->getName(), 'localized.admin.')
            ? 'localized.admin.'
            : 'admin.';
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
