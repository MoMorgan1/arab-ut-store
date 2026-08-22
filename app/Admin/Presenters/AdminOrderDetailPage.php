<?php

namespace App\Admin\Presenters;

use App\Admin\Support\OrderStatusTransitionRules;
use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\StaffAuditLog;
use App\Models\User;

final readonly class AdminOrderDetailPage
{
    public function __construct(
        private AdminShell $shell,
        private AdminOrderDetail $detailPresenter,
        private OrderStatusTransitionRules $rules,
    ) {}

    /**
     * @param  list<StaffAuditLog>|null  $auditLogs
     * @return array<string, mixed>
     */
    public function for(
        User $actor,
        string $locale,
        Order $order,
        ?array $auditLogs,
    ): array {
        $currentRouteName = (string) request()->route()?->getName();
        $prefix = str_starts_with($currentRouteName, 'localized.admin.')
            ? 'localized.admin.'
            : 'admin.';

        $allowedTargets = array_map(
            fn (OrderStatus $status): string => $status->value,
            $this->rules->allowedTargets($order->status),
        );

        return [
            'locale' => $locale,
            'direction' => $locale === 'en' ? 'ltr' : 'rtl',
            'adminUi' => (array) trans('admin', locale: $locale),
            ...$this->shell->for($actor, $locale),
            'order' => $this->detailPresenter->present($order, $locale, $auditLogs),
            'allowedTransitions' => $allowedTargets,
            'transitionUrl' => route($prefix.'orders.transitions.store', ['publicId' => (string) $order->public_id], absolute: false),
        ];
    }
}
