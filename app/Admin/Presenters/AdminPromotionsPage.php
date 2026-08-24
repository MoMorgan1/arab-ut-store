<?php

namespace App\Admin\Presenters;

use App\Admin\Queries\ListAdminPromotions;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use stdClass;

final readonly class AdminPromotionsPage
{
    public function __construct(
        private AdminShell $shell,
        private ListAdminPromotions $promotionsQuery,
    ) {}

    /**
     * @param  array{
     *     search?: ?string,
     *     status?: ?string,
     *     sort?: string,
     *     direction?: string,
     *     per_page?: int,
     *     page?: int
     * }  $filters
     * @return array<string, mixed>
     */
    public function for(User $actor, string $locale, array $filters): array
    {
        $data = $this->promotionsQuery->paginate($filters);

        $currentRouteName = (string) request()->route()?->getName();
        $prefix = str_starts_with($currentRouteName, 'localized.admin.')
            ? 'localized.admin.'
            : 'admin.';

        return [
            'locale' => $locale,
            'direction' => $locale === 'en' ? 'ltr' : 'rtl',
            'adminUi' => (array) trans('admin', locale: $locale),
            ...$this->shell->for($actor, $locale),
            'promotions' => $data['promotions'],
            'pagination' => $data['pagination'],
            'counts' => [
                'total' => $data['totalCount'],
                'active' => $data['activeCount'],
                'scheduled' => $data['scheduledCount'],
                'paused' => $data['pausedCount'],
                'ended' => $data['endedCount'],
            ],
            'categories' => $this->categories(),
            'products' => $this->products(),
            'createUrl' => route($prefix.'marketing.promotions.store', absolute: false),
            'updateUrlTemplate' => route($prefix.'marketing.promotions.update', ['publicId' => '__ID__'], absolute: false),
            'statusUrlTemplate' => route($prefix.'marketing.promotions.status.store', ['publicId' => '__ID__'], absolute: false),
            'filters' => $filters,
        ];
    }

    /** @return list<array{id: string, name: string}> */
    private function categories(): array
    {
        $rows = DB::table('categories')
            ->orderBy('name_en')
            ->orderBy('id')
            ->get(['public_id', 'name_en']);

        return array_values(array_map(
            fn (stdClass $row): array => [
                'id' => (string) $row->public_id,
                'name' => (string) $row->name_en,
            ],
            $rows->all(),
        ));
    }

    /** @return list<array{id: string, name: string, priceHalalah: int}> */
    private function products(): array
    {
        $effectivePriceSubquery = DB::table('product_variants')
            ->selectRaw('MIN(COALESCE(admin_price_halalah, sale_price_halalah, price_halalah))')
            ->whereColumn('product_id', 'products.id')
            ->where('is_active', true)
            ->whereRaw('COALESCE(admin_price_halalah, sale_price_halalah, price_halalah) > 0');

        $rows = DB::table('products')
            ->select(['public_id', 'name_en'])
            ->selectSub($effectivePriceSubquery, 'price_halalah')
            ->where('is_visible', true)
            ->whereNull('archived_at')
            ->whereNull('admin_hidden_at')
            ->orderBy('name_en')
            ->orderBy('id')
            ->get();

        return array_values(array_map(
            fn (stdClass $row): array => [
                'id' => (string) $row->public_id,
                'name' => (string) $row->name_en,
                'priceHalalah' => (int) ($row->price_halalah ?? 0),
            ],
            $rows->all(),
        ));
    }
}
