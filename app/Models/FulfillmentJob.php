<?php

namespace App\Models;

use App\Enums\DeliveryPhase;
use App\Enums\FulfillmentStatus;
use App\Enums\OrderHoldReason;
use App\Enums\Supplier;
use App\Enums\SupplierAction;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property FulfillmentStatus $status
 * @property Supplier|null $supplier
 * @property DeliveryPhase|null $delivery_phase
 * @property OrderHoldReason|null $hold_reason
 * @property array<string, mixed>|null $observation
 * @property array<int, mixed>|null $allowed_actions
 * @property bool $observation_supported
 */
class FulfillmentJob extends DomainModel
{
    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => FulfillmentStatus::class,
            'supplier' => Supplier::class,
            'delivery_phase' => DeliveryPhase::class,
            'hold_reason' => OrderHoldReason::class,
            'observation' => 'array',
            'allowed_actions' => 'array',
            'observation_supported' => 'boolean',
            'observed_at' => 'immutable_datetime',
            'last_viewed_at' => 'immutable_datetime',
            'leased_until' => 'immutable_datetime',
            'attempt_count' => 'integer',
            'coins_delivered' => 'integer',
            'coins_ordered' => 'integer',
            'challenges_solved' => 'integer',
            'challenges_requested' => 'integer',
            'poll_failure_count' => 'integer',
            'actual_cost_halalah' => 'integer',
            'next_poll_at' => 'immutable_datetime',
            'deadline_at' => 'immutable_datetime',
            'claimed_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
        ];
    }

    /**
     * The stored allowed-action values as enum cases.
     *
     * A stored action can outlive an enum change, so values that no longer map
     * to a case are dropped rather than failing the whole read.
     *
     * @return list<SupplierAction>
     */
    public function allowedActions(): array
    {
        $stored = $this->allowed_actions;

        if (! is_array($stored)) {
            return [];
        }

        $actions = [];

        foreach ($stored as $value) {
            if (! is_string($value)) {
                continue;
            }

            $action = SupplierAction::tryFrom($value);

            if ($action instanceof SupplierAction) {
                $actions[] = $action;
            }
        }

        return $actions;
    }

    /** @return BelongsTo<OrderItem, $this> */
    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class);
    }

    /** @return HasMany<FulfillmentAttempt, $this> */
    public function attempts(): HasMany
    {
        return $this->hasMany(FulfillmentAttempt::class);
    }
}
