<?php

namespace App\Admin\Presenters;

use App\Admin\Queries\ListAdminCoupons;
use App\Enums\ServiceType;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use stdClass;

final readonly class AdminCouponsPage
{
    public function __construct(
        private AdminShell $shell,
        private ListAdminCoupons $couponsQuery,
    ) {}

    /**
     * @param  array{
     *     search?: ?string,
     *     status?: ?string,
     *     scope?: ?string,
     *     discount_type?: ?string,
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

        $currentRouteName = (string) request()->route()?->getName();
        $prefix = str_starts_with($currentRouteName, 'localized.admin.')
            ? 'localized.admin.'
            : 'admin.';

        $serviceOptions = array_map(
            fn (ServiceType $st): array => [
                'value' => $st->value,
                'label' => (string) trans("admin.orders.services.{$st->value}", locale: $locale),
            ],
            ServiceType::cases(),
        );

        return [
            'locale' => $locale,
            'direction' => $locale === 'en' ? 'ltr' : 'rtl',
            'adminUi' => (array) trans('admin', locale: $locale),
            ...$this->shell->for($actor, $locale),
            'coupons' => $data['coupons'],
            'pagination' => $data['pagination'],
            'counts' => $data['counts'],
            'filters' => $filters,
            'filterOptions' => [
                'statuses' => [
                    ['value' => 'all', 'label' => (string) trans('admin.coupons.statusAll', locale: $locale)],
                    ['value' => 'active', 'label' => (string) trans('admin.coupons.statusActive', locale: $locale)],
                    ['value' => 'scheduled', 'label' => (string) trans('admin.coupons.statusScheduled', locale: $locale)],
                    ['value' => 'paused', 'label' => (string) trans('admin.coupons.statusPaused', locale: $locale)],
                    ['value' => 'expired', 'label' => (string) trans('admin.coupons.statusExpired', locale: $locale)],
                    ['value' => 'exhausted', 'label' => (string) trans('admin.coupons.statusExhausted', locale: $locale)],
                ],
                'scopes' => [
                    ['value' => 'order', 'label' => (string) trans('admin.coupons.scopeOrder', locale: $locale)],
                    ['value' => 'category', 'label' => (string) trans('admin.coupons.scopeCategory', locale: $locale)],
                    ['value' => 'product', 'label' => (string) trans('admin.coupons.scopeProduct', locale: $locale)],
                    ['value' => 'service', 'label' => (string) trans('admin.coupons.scopeService', locale: $locale)],
                ],
                'discountTypes' => [
                    ['value' => 'percent', 'label' => (string) trans('admin.coupons.typePercent', locale: $locale)],
                    ['value' => 'fixed', 'label' => (string) trans('admin.coupons.typeFixed', locale: $locale)],
                ],
                'perPageOptions' => [15, 25, 50, 100],
            ],
            'categories' => $this->categories(),
            'products' => $this->products(),
            'serviceTypes' => $serviceOptions,
            'createUrl' => route($prefix.'marketing.coupons.store', absolute: false),
            'updateUrlTemplate' => route($prefix.'marketing.coupons.update', ['publicId' => '__ID__'], absolute: false),
            'statusUrlTemplate' => route($prefix.'marketing.coupons.status.store', ['publicId' => '__ID__'], absolute: false),
            'duplicateUrlTemplate' => route($prefix.'marketing.coupons.duplicate', ['publicId' => '__ID__'], absolute: false),
            'showUrlTemplate' => route($prefix.'marketing.coupons.show', ['publicId' => '__ID__'], absolute: false),
            'confirmPasswordUrl' => route('password.confirm', absolute: false),
        ];
    }

    /** @return list<array{id: int, publicId: string, name: string}> */
    private function categories(): array
    {
        $rows = DB::table('categories')
            ->orderBy('name_en')
            ->orderBy('id')
            ->get(['id', 'public_id', 'name_en']);

        return array_values(array_map(
            fn (stdClass $row): array => [
                'id' => (int) $row->id,
                'publicId' => (string) $row->public_id,
                'name' => (string) $row->name_en,
            ],
            $rows->all(),
        ));
    }

    /** @return list<array{id: int, publicId: string, name: string}> */
    private function products(): array
    {
        $rows = DB::table('products')
            ->orderBy('name_en')
            ->orderBy('id')
            ->get(['id', 'public_id', 'name_en']);

        return array_values(array_map(
            fn (stdClass $row): array => [
                'id' => (int) $row->id,
                'publicId' => (string) $row->public_id,
                'name' => (string) $row->name_en,
            ],
            $rows->all(),
        ));
    }
}
