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
            ],
            'categories' => $this->categories(),
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
}
