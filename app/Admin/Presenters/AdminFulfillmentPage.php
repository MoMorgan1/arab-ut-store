<?php

namespace App\Admin\Presenters;

use App\Admin\Queries\ListAdminFulfillment;
use App\Enums\DeliveryPhase;
use App\Enums\FulfillmentAlarmKind;
use App\Enums\FulfillmentStatus;
use App\Enums\ServiceType;
use App\Enums\Supplier;
use App\Models\User;

final readonly class AdminFulfillmentPage
{
    public function __construct(
        private AdminShell $shell,
        private ListAdminFulfillment $query,
    ) {}

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function for(User $actor, string $locale, array $filters, bool $withCost, bool $canAct): array
    {
        /** @var array{search?: ?string, supplier?: ?string, phase?: ?string, status?: ?string, alarm?: ?string, hold?: ?string, service?: ?string, paid_from?: ?string, paid_to?: ?string, sort?: string, direction?: string, per_page?: int, page?: int} $queryFilters */
        $queryFilters = $filters;
        $page = $this->query->paginate($queryFilters, $withCost, $canAct);

        $currentRouteName = (string) request()->route()?->getName();
        $prefix = str_starts_with($currentRouteName, 'localized.admin.')
            ? 'localized.admin.'
            : 'admin.';

        return [
            'locale' => $locale,
            'direction' => $locale === 'en' ? 'ltr' : 'rtl',
            'adminUi' => (array) trans('admin', locale: $locale),
            ...$this->shell->for($actor, $locale),
            'items' => $page['items'],
            'pagination' => $page['pagination'],
            'filters' => $filters,
            'filterOptions' => $this->filterOptions($locale),
            // Two capabilities the page reads to decide what to render. They
            // never grant anything: the cost is already absent from every row
            // the actor may not see it on, and the action routes authorize
            // again. These only stop the screen offering a control that would
            // 403.
            'canSeeCost' => $withCost,
            'canAct' => $canAct,
            'orderUrlTemplate' => route($prefix.'orders.show', ['order' => '__ID__'], absolute: false),
            'resendUrlTemplate' => route($prefix.'fulfillment.resend', ['item' => '__ID__'], absolute: false),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function filterOptions(string $locale): array
    {
        $copy = static fn (string $key): string => (string) trans('admin.fulfillment.'.$key, locale: $locale);

        return [
            'suppliers' => [
                ['value' => 'all', 'label' => $copy('filterSupplierAll')],
                ...array_map(static fn (Supplier $supplier): array => [
                    'value' => $supplier->value,
                    'label' => strtoupper($supplier->value),
                ], Supplier::cases()),
            ],
            'phases' => [
                ['value' => 'all', 'label' => $copy('filterPhaseAll')],
                ...array_map(static fn (DeliveryPhase $phase): array => [
                    'value' => $phase->value,
                    'label' => $copy('phase.'.$phase->value),
                ], DeliveryPhase::cases()),
            ],
            'statuses' => [
                ['value' => 'all', 'label' => $copy('filterStatusAll')],
                // Failed is absent by owner decision: nothing ever assigns it,
                // so the chip could never match. See the form request.
                ...array_values(array_map(static fn (FulfillmentStatus $status): array => [
                    'value' => $status->value,
                    'label' => (string) trans('admin.statuses.'.$status->value, locale: $locale),
                ], array_filter(
                    FulfillmentStatus::cases(),
                    static fn (FulfillmentStatus $status): bool => $status !== FulfillmentStatus::Failed,
                ))),
            ],
            'alarms' => [
                ['value' => 'all', 'label' => $copy('filterAlarmAll')],
                ['value' => 'any', 'label' => $copy('filterAlarmAny')],
                ...array_map(static fn (FulfillmentAlarmKind $kind): array => [
                    'value' => $kind->value,
                    'label' => $copy('alarm.'.$kind->value),
                ], FulfillmentAlarmKind::cases()),
                ['value' => 'none', 'label' => $copy('filterAlarmNone')],
            ],
            'services' => [
                ['value' => 'all', 'label' => $copy('filterServiceAll')],
                ['value' => ServiceType::Coins->value, 'label' => (string) trans('admin.orders.services.coins', locale: $locale)],
                ['value' => ServiceType::Sbc->value, 'label' => (string) trans('admin.orders.services.sbc', locale: $locale)],
            ],
            'holds' => [
                ['value' => 'all', 'label' => $copy('filterHoldAll')],
                ['value' => 'held', 'label' => $copy('filterHoldHeld')],
                ['value' => 'clear', 'label' => $copy('filterHoldClear')],
            ],
            'reasonCodes' => array_map(static fn (string $code): array => [
                'value' => $code,
                'label' => $copy('reasonCodes.'.$code),
            ], ['never_placed', 'callback_lost', 'supplier_stalled', 'customer_report']),
            'perPageOptions' => [15, 25, 50, 100],
        ];
    }
}
