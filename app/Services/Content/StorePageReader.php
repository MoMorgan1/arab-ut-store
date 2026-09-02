<?php

namespace App\Services\Content;

use App\Models\StorePage;
use LogicException;

final class StorePageReader
{
    /**
     * @return array{title: string, subtitle?: string, updated_label: string, blocks: list<array<string, mixed>>}
     */
    public function page(string $key, string $locale): array
    {
        /** @var StorePage|null $page */
        $page = StorePage::query()->where('key', $key)->first();

        if ($page === null) {
            throw new LogicException("The requested store page [{$key}] was not found.");
        }

        return $page->forLocale($locale);
    }
}
