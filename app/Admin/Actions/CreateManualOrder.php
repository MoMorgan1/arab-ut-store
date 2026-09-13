<?php

namespace App\Admin\Actions;

use App\Actions\Fulfillment\RecordSupplierPlacement;
use App\Admin\Audit\StaffAuditEvent;
use App\Admin\ManualOrder\ManualOrderDraft;
use App\Admin\ManualOrder\ManualOrderItemDraft;
use App\Admin\ManualOrder\ManualOrderPlacement;
use App\Checkout\OrderNumber;
use App\Enums\AdminPermission;
use App\Enums\OrderItemStatus;
use App\Enums\OrderStatus;
use App\Enums\OrderStatusHistoryStatus;
use App\Enums\PaymentStatus;
use App\Enums\UserRole;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderItemSecret;
use App\Models\User;
use App\Support\SafeOrderItemConfiguration;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Writes an order the store did not sell.
 *
 * This is not a second checkout, and it does not go through `PlaceOrder`, which
 * cannot serve it: that action requires an active cart, a verified phone, a
 * live reprice, a 5 SAR Paylink minimum, and it always creates a payment. Five
 * of those are refusals rather than preferences for an order that has no cart,
 * may be for an imported customer whose phone was never verified here, carries
 * an overridable price, and as a gift has no payment at all. The reasoning and
 * the line references are in
 * `docs/decisions/2026-09-13-manual-order-does-not-reuse-place-order.md`.
 *
 * What is reused rather than reimplemented: the order number, the order-item
 * field shape, the configuration allowlist, and the idempotency discipline.
 * Pricing, cashback, loyalty and reconciliation are untouched - a manual order
 * reaches them as an ordinary order, because its channel is not
 * `salla_import`.
 */
final readonly class CreateManualOrder
{
    public function __construct(
        private RecordStaffAudit $audit,
        private RecordSupplierPlacement $recordSupplierPlacement,
    ) {}

    /**
     * @throws AuthorizationException when the actor may not create orders, or
     *                                the subject is not a customer account
     */
    public function execute(
        User $actor,
        User $customer,
        string $locale,
        ManualOrderDraft $draft,
        ?string $ipAddress = null,
    ): Order {
        if (! $actor->can(AdminPermission::OrdersCreate->value)) {
            throw new AuthorizationException('Creating an order requires the orders.create permission.');
        }

        // A manual order lands on somebody's account and shows up in their
        // order history, their loyalty spend and their cashback. Only a
        // customer account has those, so a staff or service account is refused
        // rather than quietly given a storefront order.
        if ($customer->role !== UserRole::Customer) {
            throw new AuthorizationException('A manual order can only be created for a customer account.');
        }

        if ($draft->items === []) {
            throw new RuntimeException('A manual order needs at least one item.');
        }

        $this->assertPaymentMatchesType($draft);

        return DB::transaction(function () use ($actor, $customer, $locale, $draft, $ipAddress): Order {
            $order = $this->writeOrder($customer, $locale, $draft);

            foreach ($draft->items as $item) {
                $this->writeItem($order, $draft, $item);
            }

            if ($draft->payment !== null) {
                $this->writePayment($order, $draft);
            }

            $this->recordHistory($order, $actor, $draft);

            $this->audit->execute($actor, $order, new StaffAuditEvent(
                'orders.manual_created',
                $this->auditMetadata($order, $draft),
                $ipAddress,
            ));

            return $order;
        });
    }

    /**
     * A gift has no payment and a transfer has one for the whole total. Those
     * are the only two shapes, and the pair is what distinguishes a gift in the
     * data afterwards (owner decision, 2026-09-13) - so a transfer whose
     * payment does not cover its items would silently create an order that
     * reads as neither.
     */
    private function assertPaymentMatchesType(ManualOrderDraft $draft): void
    {
        if ($draft->isGift && $draft->payment !== null) {
            throw new RuntimeException('A gift records no payment.');
        }

        if (! $draft->isGift && $draft->payment === null) {
            throw new RuntimeException('A bank transfer records the payment that arrived.');
        }

        if ($draft->payment !== null && $draft->payment->amountHalalah !== $draft->subtotalHalalah()) {
            throw new RuntimeException('The payment recorded does not match the order total.');
        }
    }

    private function writeOrder(User $customer, string $locale, ManualOrderDraft $draft): Order
    {
        $subtotal = $draft->subtotalHalalah();

        return Order::create([
            'user_id' => $customer->id,
            'order_number' => OrderNumber::generate(),
            // `manual` is the third channel value, beside `store` and
            // `salla_import`. Every rule that today asks whether an order is
            // imported therefore treats this one as an ordinary order:
            // cashback accrues, a review can be invited, and staff can
            // transition it.
            'channel' => 'manual',
            'status' => OrderStatus::Received,
            'locale' => $locale,
            'currency' => 'SAR',
            'subtotal_halalah' => $subtotal,
            'discount_halalah' => 0,
            'wallet_halalah' => 0,
            'payment_halalah' => $subtotal,
            'total_halalah' => $subtotal,
            'placed_at' => now(),
            // Nothing is owed on either shape: a transfer already arrived and a
            // gift never will. Leaving this null would park the order in the
            // storefront's "awaiting payment" reading of its own status.
            'paid_at' => now(),
        ]);
    }

    private function writeItem(Order $order, ManualOrderDraft $draft, ManualOrderItemDraft $item): void
    {
        $lineTotal = $draft->lineTotalHalalah($item);

        /** @var OrderItem $orderItem */
        $orderItem = $order->items()->create([
            'product_variant_id' => $item->productVariantId,
            'sku' => $item->sku,
            'name_ar' => $item->nameAr,
            'name_en' => $item->nameEn,
            'service_type' => $item->serviceType,
            'platform' => $item->platform,
            'status' => OrderItemStatus::Received,
            'quantity' => 1,
            'unit_price_halalah' => $lineTotal,
            'subtotal_halalah' => $lineTotal,
            'discount_halalah' => 0,
            'promotion_id' => null,
            'promotion_discount_halalah' => 0,
            'total_halalah' => $lineTotal,
            // Through the same allowlist checkout writes, so a key can only be
            // persisted when the admin detail screen is also allowed to show it.
            'configuration' => SafeOrderItemConfiguration::project(
                $item->configuration,
                $item->serviceType,
            ),
        ]);

        $this->writeCredentials($orderItem, $item);

        if ($item->placement instanceof ManualOrderPlacement) {
            $this->recordPlacement($orderItem, $item->placement);
        }
    }

    /**
     * The EA email is required and the password is not (owner decision,
     * 2026-09-13): on an item staff already placed by hand they have signed in
     * themselves, and the store has no call left to make with the password. The
     * email is kept because it is what the supplier's own progress names and
     * what the customer sees on their tracking page.
     */
    private function writeCredentials(OrderItem $orderItem, ManualOrderItemDraft $item): void
    {
        if ($item->credentials === null) {
            return;
        }

        $email = $item->credentials['ea_email'] ?? null;

        if (! is_string($email) || $email === '') {
            return;
        }

        $password = $item->credentials['ea_password'] ?? null;
        $backupCodes = $item->credentials['backup_codes'] ?? [];
        $backupCodes = is_array($backupCodes) ? array_values(array_filter($backupCodes, 'is_string')) : [];

        $payload = ['ea_email' => $email, 'backup_codes' => $backupCodes];

        if (is_string($password) && $password !== '') {
            $payload['ea_password'] = $password;
        }

        $secret = new OrderItemSecret([
            'order_item_id' => $orderItem->id,
            // The summary says what is held without holding it, and it is read
            // by the screens that decide whether a credential fix is even
            // possible - so "no password" has to be visible as a fact.
            'masked_summary' => [
                'has_password' => array_key_exists('ea_password', $payload),
                'backup_code_count' => count($backupCodes),
            ],
            'retained_until' => null,
            'deleted_at' => null,
        ]);
        $secret->encrypted_payload = $payload;
        $secret->save();
    }

    /**
     * Records the reference through the action that already owns this,
     * `RecordSupplierPlacement`.
     *
     * The first version of this method wrote the `FulfillmentJob` row itself
     * and claimed the `(supplier, supplier_order_id)` unique index on that
     * table would refuse a duplicate reference. A probe of the test schema
     * showed the index is not there: `2026_09_12_000003:34` drops it and moves
     * supplier-reference uniqueness to `fulfillment_placements`, because a job
     * mirrors only its first placement. So the hand-written version created a
     * job with no placement row, skipped every rule that action enforces, and
     * would have let the same reference be pasted onto two orders.
     *
     * Calling it instead means a manual placement is subject to exactly the
     * same refusals as one arriving from the automation: not an automated
     * service, a supplier that cannot solve challenges, an unpaid order, a
     * reference already used. Its own transaction nests as a savepoint inside
     * this one, so a refusal takes the whole order with it rather than leaving
     * an order whose item has nowhere to be tracked.
     */
    private function recordPlacement(OrderItem $orderItem, ManualOrderPlacement $placement): void
    {
        $result = $this->recordSupplierPlacement->execute([
            'order_item_public_id' => $orderItem->public_id,
            'supplier' => $placement->supplier->value,
            'supplier_order_id' => $placement->supplierOrderId,
            'delivery_phase' => $placement->phase->value,
            'challenge_ids' => $placement->challengeIds,
        ]);

        if ($result['outcome'] === 'recorded' || $result['outcome'] === 'replayed') {
            return;
        }

        throw new RuntimeException(sprintf(
            'The supplier reference %s could not be recorded: %s.',
            $placement->supplierOrderId,
            $result['outcome'],
        ));
    }

    private function writePayment(Order $order, ManualOrderDraft $draft): void
    {
        $payment = $draft->payment;

        if ($payment === null) {
            return;
        }

        $order->payments()->create([
            // A third provider beside `wallet` and `paylink`. The column is a
            // free string, as those two are.
            'provider' => 'bank_transfer',
            // The bank's reference, which is what ties this row to a statement
            // line. It also makes the provider/payment-id unique index do
            // something useful: the same transfer cannot be recorded twice.
            'provider_payment_id' => $payment->reference,
            'status' => PaymentStatus::Paid,
            'currency' => 'SAR',
            'amount_halalah' => $payment->amountHalalah,
            // Captured, not merely authorised - the money is already in the
            // account. This is also what `EligibleOrderSpend` sums, so a
            // transfer counts towards loyalty exactly like a card payment.
            'captured_halalah' => $payment->amountHalalah,
            'refunded_halalah' => 0,
            'idempotency_key' => 'bank_transfer:'.hash('sha256', $order->id.'|'.$payment->reference),
            'provider_metadata' => null,
            'authorized_at' => $payment->receivedAt,
            'paid_at' => $payment->receivedAt,
        ]);
    }

    private function recordHistory(Order $order, User $actor, ManualOrderDraft $draft): void
    {
        $order->statusHistory()->create([
            'actor_user_id' => $actor->id,
            'status' => OrderStatusHistoryStatus::Received,
            'metadata' => ['source' => $draft->isGift ? 'manual_gift' : 'manual_bank_transfer'],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function auditMetadata(Order $order, ManualOrderDraft $draft): array
    {
        return [
            'order_number' => $order->order_number,
            'customer_id' => $order->user_id,
            'is_gift' => $draft->isGift,
            'total_halalah' => $order->total_halalah,
            'item_count' => count($draft->items),
            'services' => array_map(
                fn ($service): string => $service->value,
                $draft->serviceTypes(),
            ),
            'placed_by_hand' => $draft->hasPlacements(),
            'payment_reference' => $draft->payment?->reference,
        ];
    }
}
