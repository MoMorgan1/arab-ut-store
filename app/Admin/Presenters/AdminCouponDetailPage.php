<?php

namespace App\Admin\Presenters;

use App\Admin\Queries\ReadAdminCouponPerformance;
use App\Enums\ServiceType;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * @phpstan-import-type AdminCouponPerformanceResult from ReadAdminCouponPerformance
 */
final readonly class AdminCouponDetailPage
{
    public function __construct(
        private AdminShell $shell,
    ) {}

    /**
     * @param  AdminCouponPerformanceResult  $data
     * @return array<string, mixed>
     */
    public function for(User $actor, string $locale, array $data): array
    {
        $currentRouteName = (string) request()->route()?->getName();
        $prefix = str_starts_with($currentRouteName, 'localized.admin.')
            ? 'localized.admin.'
            : 'admin.';

        $coupon = $data['coupon'];
        $kpis = $data['kpis'];

        $presentedKpis = [
            'usedCount' => $kpis['usedCount'],
            'usageLimit' => $kpis['usageLimit'],
            'uniqueCustomers' => $kpis['uniqueCustomers'],
            'revenueAttributed' => [
                'amountMinor' => (string) $kpis['revenueAttributedHalalah'],
                'currency' => 'SAR',
            ],
            'totalDiscountGiven' => [
                'amountMinor' => (string) $kpis['totalDiscountHalalah'],
                'currency' => 'SAR',
            ],
            'totalRedemptions' => $kpis['totalRedemptions'],
            'releasedRedemptionsCount' => $kpis['releasedRedemptionsCount'],
        ];

        $presentedRecentRedemptions = array_map(function (array $r): array {
            return [
                'id' => $r['id'],
                'orderId' => $r['orderId'],
                'orderNumber' => $r['orderNumber'],
                'orderStatus' => $r['orderStatus'],
                'isPaid' => $r['isPaid'],
                'paidAt' => $r['paidAt'],
                'orderTotal' => [
                    'amountMinor' => (string) $r['orderTotalHalalah'],
                    'currency' => 'SAR',
                ],
                'discount' => [
                    'amountMinor' => (string) $r['discountHalalah'],
                    'currency' => 'SAR',
                ],
                'customer' => $r['customer'],
                'redeemedAt' => $r['redeemedAt'],
            ];
        }, $data['recentRedemptions']);

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
            'coupon' => $coupon,
            'kpis' => $presentedKpis,
            'rules' => $data['rulesSummary'],
            'chart' => $data['chart'],
            'recentRedemptions' => $presentedRecentRedemptions,
            'categories' => $this->categories(),
            'products' => $this->products(),
            'serviceTypes' => $serviceOptions,
            'updateUrl' => route($prefix.'marketing.coupons.update', ['publicId' => $coupon['id']], absolute: false),
            'statusUrl' => route($prefix.'marketing.coupons.status.store', ['publicId' => $coupon['id']], absolute: false),
            'duplicateUrl' => route($prefix.'marketing.coupons.duplicate', ['publicId' => $coupon['id']], absolute: false),
            'listUrl' => route($prefix.'marketing.coupons', absolute: false),
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
