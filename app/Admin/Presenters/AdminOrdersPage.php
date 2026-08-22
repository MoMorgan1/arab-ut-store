<?php

namespace App\Admin\Presenters;

use App\Admin\Queries\ListAdminOrders;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\Platform;
use App\Enums\ServiceType;
use App\Models\User;

final readonly class AdminOrdersPage
{
    public function __construct(
        private AdminShell $shell,
        private ListAdminOrders $ordersQuery,
    ) {}

    /**
     * @param array{
     *     search?: ?string,
     *     status?: ?string,
     *     service?: ?string,
     *     platform?: ?string,
     *     payment_status?: ?string,
     *     date_from?: ?string,
     *     date_to?: ?string,
     *     sort?: string,
     *     direction?: string,
     *     per_page?: int,
     *     page?: int
     * } $filters
     * @return array<string, mixed>
     */
    public function for(User $actor, string $locale, array $filters): array
    {
        $orderData = $this->ordersQuery->paginate($filters);

        return [
            'locale' => $locale,
            'direction' => $locale === 'en' ? 'ltr' : 'rtl',
            'adminUi' => (array) trans('admin', locale: $locale),
            ...$this->shell->for($actor, $locale),
            'orders' => $orderData['orders'],
            'pagination' => $orderData['pagination'],
            'filters' => $filters,
            'filterOptions' => [
                'statuses' => array_map(
                    fn (OrderStatus $status): array => [
                        'value' => $status->value,
                        'label' => (string) trans("admin.statuses.{$status->value}", locale: $locale),
                    ],
                    OrderStatus::cases(),
                ),
                'services' => array_map(
                    fn (ServiceType $service): array => [
                        'value' => $service->value,
                        'label' => (string) trans("admin.orders.services.{$service->value}", locale: $locale),
                    ],
                    ServiceType::cases(),
                ),
                'platforms' => array_map(
                    fn (Platform $platform): array => [
                        'value' => $platform->value,
                        'label' => (string) trans("admin.orders.platforms.{$platform->value}", locale: $locale),
                    ],
                    Platform::cases(),
                ),
                'paymentStatuses' => array_map(
                    fn (PaymentStatus $paymentStatus): array => [
                        'value' => $paymentStatus->value,
                        'label' => (string) trans("admin.statuses.{$paymentStatus->value}", locale: $locale),
                    ],
                    PaymentStatus::cases(),
                ),
                'perPageOptions' => [15, 25, 50, 100],
            ],
        ];
    }
}
