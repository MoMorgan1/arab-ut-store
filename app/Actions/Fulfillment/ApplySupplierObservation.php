<?php

namespace App\Actions\Fulfillment;

use App\Actions\Orders\InviteOrderReview;
use App\Enums\DeliveryPhase;
use App\Enums\FulfillmentStatus;
use App\Enums\OrderItemStatus;
use App\Enums\OrderStatus;
use App\Enums\OrderStatusHistoryStatus;
use App\Enums\SupplierAction;
use App\Loyalty\Actions\AccrueOrderCashback;
use App\Models\FulfillmentJob;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderStatusHistory;
use App\Suppliers\Translation\TranslatedState;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

final class ApplySupplierObservation
{
    public function __construct(
        private readonly AccrueOrderCashback $accrueOrderCashback,
        private readonly InviteOrderReview $inviteOrderReview,
    ) {}

    /**
     * Applies a supplier observation to canonical order and fulfillment state.
     *
     * @param  array<string, mixed>  $rawPayload
     */
    public function execute(
        FulfillmentJob $job,
        TranslatedState $state,
        CarbonImmutable $observedAt,
        array $rawPayload,
    ): void {
        // Rule 4: A supplier can never produce Refunded.
        // Refunded is a store-owned financial status that only refund flows can set.
        if ($state->status === OrderStatus::Refunded) {
            throw new DomainException('A supplier observation cannot produce a refunded status.');
        }

        // Fast path: if the job already has a newer observation, return immediately.
        if ($job->observed_at !== null && $job->observed_at->isAfter($observedAt)) {
            return;
        }

        $item = OrderItem::query()->find($job->order_item_id);

        if (! $item instanceof OrderItem) {
            return;
        }

        $orderId = $item->order_id;

        DB::transaction(function () use ($job, $state, $observedAt, $rawPayload, $orderId): void {
            // Lock order first, then items ordered by ID, then the fulfillment job.
            // Consistent lock acquisition order prevents deadlocks between concurrent writers.
            /** @var Order $order */
            $order = Order::query()
                ->where('id', $orderId)
                ->lockForUpdate()
                ->firstOrFail();

            /** @var Collection<int, OrderItem> $items */
            $items = OrderItem::query()
                ->where('order_id', $order->id)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            /** @var FulfillmentJob $lockedJob */
            $lockedJob = FulfillmentJob::query()
                ->where('id', $job->id)
                ->lockForUpdate()
                ->firstOrFail();

            // Rule 1: An older observation is discarded under the lock.
            // Protects against races between background sweeps and manual customer refreshes.
            if ($lockedJob->observed_at !== null && $lockedJob->observed_at->isAfter($observedAt)) {
                return;
            }

            // Rule 2: A terminal order never moves.
            // Completed, Cancelled, and Refunded are permanent terminal states.
            $orderIsTerminal = in_array($order->status, [
                OrderStatus::Completed,
                OrderStatus::Cancelled,
                OrderStatus::Refunded,
            ], true);

            if ($orderIsTerminal) {
                // Storing the observation on the job preserves diagnostic context for staff
                // and auditing without mutating the order or its items.
                $this->persistJobObservation(
                    job: $lockedJob,
                    state: $state,
                    observedAt: $observedAt,
                    rawPayload: $rawPayload,
                    withheldDueToAdmin: false,
                    orderIsTerminal: true,
                );

                return;
            }

            /** @var OrderItem|null $targetItem */
            $targetItem = $items->firstWhere('id', $lockedJob->order_item_id);

            if (! $targetItem instanceof OrderItem) {
                return;
            }

            // Rule 3: A manual admin hold wins.
            // An admin hold is marked by the latest OrderStatusHistory row having source = 'admin'
            // while the item or order is currently in WaitingForCustomer.
            $itemIsAdminHold = false;
            if ($targetItem->status === OrderItemStatus::WaitingForCustomer) {
                /** @var OrderStatusHistory|null $latestItemHistory */
                $latestItemHistory = OrderStatusHistory::query()
                    ->where('order_item_id', $targetItem->id)
                    ->latest('id')
                    ->first();

                if (($latestItemHistory?->metadata['source'] ?? null) === 'admin') {
                    $itemIsAdminHold = true;
                }
            }

            $orderIsAdminHold = false;
            if ($order->status === OrderStatus::WaitingForCustomer) {
                /** @var OrderStatusHistory|null $latestOrderHistory */
                $latestOrderHistory = OrderStatusHistory::query()
                    ->where('order_id', $order->id)
                    ->whereNull('order_item_id')
                    ->latest('id')
                    ->first();

                if (($latestOrderHistory?->metadata['source'] ?? null) === 'admin') {
                    $orderIsAdminHold = true;
                }
            }

            // An order-level admin hold withholds item moves and hold fields just like an item-level hold
            $isAdminHold = $itemIsAdminHold || $orderIsAdminHold;

            // Update item status if not withheld by an active admin hold
            if (! $isAdminHold) {
                $targetItemStatus = OrderItemStatus::from($state->status->value);

                if ($targetItemStatus !== $targetItem->status) {
                    $previousItemStatus = $targetItem->status;
                    $targetItem->status = $targetItemStatus;
                    $targetItem->save();

                    OrderStatusHistory::query()->create([
                        'order_id' => $order->id,
                        'order_item_id' => $targetItem->id,
                        'actor_user_id' => null,
                        'status' => OrderStatusHistoryStatus::from($targetItemStatus->value),
                        'note_ar' => null,
                        'note_en' => null,
                        'metadata' => [
                            'source' => 'supplier',
                            'previous_status' => $previousItemStatus->value,
                            'new_status' => $targetItemStatus->value,
                        ],
                    ]);
                }
            }

            // Rule 5: Items aggregate to the order conservatively.
            if (! $isAdminHold) {
                $targetOrderStatus = $this->aggregateOrderStatus($items, $order->status);

                if ($targetOrderStatus !== $order->status) {
                    $previousOrderStatus = $order->status;
                    $order->status = $targetOrderStatus;

                    if ($targetOrderStatus === OrderStatus::Completed) {
                        $order->completed_at = now();
                    }

                    $order->save();

                    OrderStatusHistory::query()->create([
                        'order_id' => $order->id,
                        'order_item_id' => null,
                        'actor_user_id' => null,
                        'status' => OrderStatusHistoryStatus::from($targetOrderStatus->value),
                        'note_ar' => null,
                        'note_en' => null,
                        'metadata' => [
                            'source' => 'supplier',
                            'previous_status' => $previousOrderStatus->value,
                            'new_status' => $targetOrderStatus->value,
                        ],
                    ]);

                    // Rule 6: Completion effects fire exactly once when reaching Completed
                    if ($targetOrderStatus === OrderStatus::Completed) {
                        $this->accrueOrderCashback->execute($order);
                        $this->inviteOrderReview->execute($order);
                    }
                }
            }

            // Persist the observation to the fulfillment job
            $this->persistJobObservation(
                job: $lockedJob,
                state: $state,
                observedAt: $observedAt,
                rawPayload: $rawPayload,
                withheldDueToAdmin: $isAdminHold,
                orderIsTerminal: false,
            );
        }, attempts: 3);

        $job->refresh();
    }

    /**
     * Aggregates item statuses to an order status conservatively.
     *
     * Preference order:
     * 1. Any item WaitingForCustomer -> WaitingForCustomer (customer action takes priority).
     * 2. Every item Completed -> Completed.
     * 3. Any item InProgress or Received -> InProgress.
     * 4. Never move the order to Cancelled from an observation.
     *
     * @param  Collection<int, OrderItem>  $items
     */
    private function aggregateOrderStatus(Collection $items, OrderStatus $currentOrderStatus): OrderStatus
    {
        if ($items->isEmpty()) {
            return $currentOrderStatus;
        }

        if ($items->contains(fn (OrderItem $item): bool => $item->status === OrderItemStatus::WaitingForCustomer)) {
            return OrderStatus::WaitingForCustomer;
        }

        if ($items->every(fn (OrderItem $item): bool => $item->status === OrderItemStatus::Completed)) {
            return OrderStatus::Completed;
        }

        if ($items->contains(fn (OrderItem $item): bool => in_array($item->status, [OrderItemStatus::InProgress, OrderItemStatus::Received], true))) {
            return OrderStatus::InProgress;
        }

        // Never move an order to Cancelled due to a supplier observation
        return $currentOrderStatus;
    }

    /**
     * Persists observation diagnostics, masked payload, and progress onto the job.
     *
     * @param  array<string, mixed>  $rawPayload
     */
    private function persistJobObservation(
        FulfillmentJob $job,
        TranslatedState $state,
        CarbonImmutable $observedAt,
        array $rawPayload,
        bool $withheldDueToAdmin,
        bool $orderIsTerminal,
    ): void {
        $job->observed_at = $observedAt;
        $job->observed_state = $state->observedState;
        $job->observation_supported = $state->supported;

        // Rule 7: Mask sensitive customer data before storing in the JSON column
        $maskedObservation = $this->maskRawPayload($rawPayload);
        if ($withheldDueToAdmin) {
            $maskedObservation['_service'] = [
                'withheld' => true,
                'withheld_reason' => 'admin_hold',
            ];
        }
        $job->observation = $maskedObservation;

        if (! $withheldDueToAdmin) {
            $job->hold_reason = $state->holdReason;
            $job->allowed_actions = array_map(
                fn (SupplierAction $action): string => $action->value,
                $state->allowedActions,
            );
        }

        // Progress counters are only updated when non-null so earlier phases are not erased
        if ($state->coinsDelivered !== null) {
            $job->coins_delivered = $state->coinsDelivered;
        }
        if ($state->coinsOrdered !== null) {
            $job->coins_ordered = $state->coinsOrdered;
        }
        if ($state->challengesSolved !== null) {
            $job->challenges_solved = $state->challengesSolved;
        }
        if ($state->challengesRequested !== null) {
            $job->challenges_requested = $state->challengesRequested;
        }

        if ($orderIsTerminal) {
            $job->next_poll_at = null;
            if ($state->status === OrderStatus::Completed) {
                $job->status = FulfillmentStatus::Completed;
                $job->completed_at = $job->completed_at ?? now();
            }
            $job->save();

            return;
        }

        // Rule 8: A later phase re-opens polling without un-completing the earlier phase
        $isChallenge = $this->isChallengePhase($job, $state, $rawPayload);

        if ($isChallenge) {
            $job->delivery_phase = DeliveryPhase::Challenge;

            if ($job->completed_at !== null && $state->status !== OrderStatus::Completed) {
                $job->completed_at = null;
                $job->next_poll_at = now();
                if ($job->status === FulfillmentStatus::Completed) {
                    $job->status = FulfillmentStatus::InProgress;
                }
            }
        }

        // Job lifecycle transitions based on translated status
        if ($state->status === OrderStatus::Completed) {
            $job->status = FulfillmentStatus::Completed;
            $job->completed_at = $job->completed_at ?? now();
            $job->next_poll_at = null;
        } elseif ($state->status === OrderStatus::Cancelled) {
            $job->status = FulfillmentStatus::Cancelled;
            $job->next_poll_at = null;
        } elseif ($state->status === OrderStatus::WaitingForCustomer) {
            $job->status = FulfillmentStatus::WaitingForCustomer;
        } elseif ($state->status === OrderStatus::InProgress && $job->completed_at === null) {
            $job->status = FulfillmentStatus::InProgress;
        }

        $job->save();
    }

    /**
     * Determines whether the observation corresponds to a challenge fulfillment phase.
     *
     * @param  array<string, mixed>  $rawPayload
     */
    private function isChallengePhase(FulfillmentJob $job, TranslatedState $state, array $rawPayload): bool
    {
        if ($job->delivery_phase === DeliveryPhase::Challenge) {
            return true;
        }

        if ($state->challengesRequested !== null || $state->challengesSolved !== null) {
            return true;
        }

        if (isset($rawPayload['challenges']) && is_array($rawPayload['challenges'])) {
            return true;
        }

        $phase = $rawPayload['phase'] ?? $rawPayload['delivery_phase'] ?? null;
        if ($phase === DeliveryPhase::Challenge->value) {
            return true;
        }

        return $job->placements()->where('delivery_phase', DeliveryPhase::Challenge->value)->exists();
    }

    /**
     * The only supplier keys that may be stored, derived from live responses on
     * 2026-09-12 rather than from documentation.
     *
     * This is an allowlist on purpose, because the blocklist that preceded it
     * lost. It masked anything shaped like an email address and stored
     * everything else - and UTT's getOrder returns `passwordAccount`, the
     * customer's EA password in plaintext, on every single status poll. The
     * sweep reads that endpoint for the life of an order, so the old version
     * would have written a customer's password into our own database
     * repeatedly, in a column we keep forever for diagnosis.
     *
     * FFT's response likewise carries `toPay` and `sellerReceives` - what we
     * pay the supplier. Deliberately absent below: our margin has no business
     * in a row anything customer-facing might one day read.
     *
     * A key not named here is dropped, not masked, and dropped silently: the
     * key names themselves say what to go looking for.
     *
     * @var list<string>
     */
    private const array STORABLE_OBSERVATION_KEYS = [
        // FFT status vocabulary and progress
        'status',
        'accountCheck',
        'accountCheckLong',
        'economyState',
        'economyStateLong',
        'amountOrdered',
        'amount',
        'coinsUsed',
        'externalOrderID',
        'coinsCustomerAccount',
        'wasAborted',
        'knownClub',
        'cached',
        'simplifiedStatus',
        // Added by UttClient when it maps UTT into FFT's shape
        'platform',
        '_supplier',
        '_uttStatusOrder',
        '_uttIdOrder',
        // Challenge progress and details from sbcStatusBulkAPI
        'challengesDone',
        'totalChallenges',
        'challengesSubmitted',
        'timesSolved',
        'timesToSolve',
        'sbcStatus',
        'costCoins',
        'setId',
        'sbcSolveID',
    ];

    /**
     * Reduces a supplier payload to the keys we are willing to keep, then masks
     * any address embedded in the free-text values that survive.
     *
     * `accountCheckLong` and `economyStateLong` are the supplier's own prose and
     * can mention the account, so the email sweep stays as a second layer over
     * the allowlist rather than instead of it.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function maskRawPayload(array $payload): array
    {
        $stored = [];

        foreach ($payload as $key => $value) {
            if (! in_array($key, self::STORABLE_OBSERVATION_KEYS, true)) {
                continue;
            }

            if (is_array($value)) {
                $stored[$key] = $this->maskRawPayload($value);
            } elseif (is_string($value)) {
                $stored[$key] = $this->maskStringEmails($value);
            } else {
                $stored[$key] = $value;
            }
        }

        return $stored;
    }

    /**
     * Sweeps a string for embedded email addresses and masks each match in place.
     */
    private function maskStringEmails(string $value): string
    {
        if (! str_contains($value, '@')) {
            return $value;
        }

        $replaced = preg_replace_callback(
            '/[a-z0-9._%+-]+@(?:[a-z0-9-]+\.)+[a-z]{2,}/i',
            function (array $matches): string {
                $email = $matches[0];
                $parts = explode('@', $email, 2);

                if (count($parts) !== 2) {
                    return $email;
                }

                [$local, $domain] = $parts;
                $firstChar = mb_substr($local, 0, 1);

                return $firstChar.'...@'.$domain;
            },
            $value
        );

        return is_string($replaced) ? $replaced : $value;
    }
}
