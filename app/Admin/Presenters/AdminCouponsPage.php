<?php

namespace App\Admin\Presenters;

use App\Admin\Queries\ListAdminCoupons;
use App\Models\User;

final readonly class AdminCouponsPage
{
    public function __construct(
        private AdminShell $shell,
        private ListAdminCoupons $couponsQuery,
    ) {}

    /**
     * @param  array{
     *     search?: ?string,
     *     sort?: string,
     *     direction?: string,
     *     per_page?: int,
     *     page?: int
     * }  $filters
     * @return array<string, mixed>
     */
    public function for(User $actor, string $locale, array $filters): array
    {
        $data = $this->couponsQuery->paginate($filters);

        return [
            'locale' => $locale,
            'direction' => $locale === 'en' ? 'ltr' : 'rtl',
            'adminUi' => (array) trans('admin', locale: $locale),
            ...$this->shell->for($actor, $locale),
            'coupons' => $data['coupons'],
            'pagination' => $data['pagination'],
            'counts' => [
                'total' => $data['totalCount'],
                'active' => $data['activeCount'],
            ],
            'filters' => $filters,
        ];
    }
}
