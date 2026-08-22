<?php

namespace App\Admin\Presenters;

use App\Admin\Queries\ListAdminCustomers;
use App\Models\User;

final readonly class AdminCustomersPage
{
    public function __construct(
        private AdminShell $shell,
        private ListAdminCustomers $customersQuery,
    ) {}

    /**
     * @param array{
     *     search?: ?string,
     *     status?: 'active'|'suspended'|null,
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
        $customerData = $this->customersQuery->paginate($filters);

        return [
            'locale' => $locale,
            'direction' => $locale === 'en' ? 'ltr' : 'rtl',
            'adminUi' => (array) trans('admin', locale: $locale),
            ...$this->shell->for($actor, $locale),
            'customers' => $customerData['customers'],
            'pagination' => $customerData['pagination'],
            'filters' => $filters,
            'filterOptions' => [
                'statuses' => [
                    [
                        'value' => 'active',
                        'label' => (string) trans('admin.customers.statusActive', locale: $locale),
                    ],
                    [
                        'value' => 'suspended',
                        'label' => (string) trans('admin.customers.statusSuspended', locale: $locale),
                    ],
                ],
                'perPageOptions' => [15, 25, 50, 100],
            ],
        ];
    }
}
