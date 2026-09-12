<?php

namespace App\Suppliers\Translation;

use App\Enums\OrderHoldReason;
use App\Enums\OrderStatus;
use App\Enums\SupplierAction;

/**
 * The canonical meaning of one supplier observation.
 *
 * Supplier codes are carried in $observedState for diagnosis only; nothing here
 * is a customer-facing string. $allowedActions is empty whenever the
 * observation cannot be trusted, and progress counters stay null when the
 * payload does not carry them.
 */
final readonly class TranslatedState
{
    /**
     * @param  list<SupplierAction>  $allowedActions
     */
    public function __construct(
        public OrderStatus $status,
        public ?OrderHoldReason $holdReason,
        public array $allowedActions,
        public bool $supported,
        public ?string $observedState,
        public ?int $coinsDelivered = null,
        public ?int $coinsOrdered = null,
        public ?int $challengesSolved = null,
        public ?int $challengesRequested = null,
    ) {}

    /**
     * Whether there is something the customer can do right now.
     */
    public function actionable(): bool
    {
        return $this->allowedActions !== [];
    }
}
