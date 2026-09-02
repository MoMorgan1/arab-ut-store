<?php

namespace App\Models;

use Database\Factories\StorePageFactory;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $public_id
 * @property string $key
 * @property string $title_ar
 * @property string $title_en
 * @property string|null $subtitle_ar
 * @property string|null $subtitle_en
 * @property string $updated_label_ar
 * @property string $updated_label_en
 * @property list<array<string, mixed>> $blocks_ar
 * @property list<array<string, mixed>> $blocks_en
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class StorePage extends DomainModel
{
    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'blocks_ar' => 'array',
            'blocks_en' => 'array',
        ];
    }

    /**
     * @return array{title: string, subtitle?: string, updated_label: string, blocks: list<array<string, mixed>>}
     */
    public function forLocale(string $locale): array
    {
        $title = $locale === 'en' && filled($this->title_en)
            ? (string) $this->title_en
            : (string) $this->title_ar;

        $subtitle = $locale === 'en' && filled($this->subtitle_en)
            ? (string) $this->subtitle_en
            : ($this->subtitle_ar ? (string) $this->subtitle_ar : null);

        $updatedLabel = $locale === 'en' && filled($this->updated_label_en)
            ? (string) $this->updated_label_en
            : (string) $this->updated_label_ar;

        /** @var list<array<string, mixed>> $blocks */
        $blocks = $locale === 'en' && ! empty($this->blocks_en)
            ? $this->blocks_en
            : $this->blocks_ar;

        $page = [
            'title' => $title,
            'updated_label' => $updatedLabel,
            'blocks' => $blocks,
        ];

        if ($subtitle !== null && $subtitle !== '') {
            $page['subtitle'] = $subtitle;
        }

        return $page;
    }

    protected static function newFactory(): StorePageFactory
    {
        return StorePageFactory::new();
    }
}
