<?php

namespace App\Models;

use Database\Factories\FaqEntryFactory;
use Illuminate\Database\Eloquent\Builder;

class FaqEntry extends DomainModel
{
    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'is_visible' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /**
     * @param  Builder<FaqEntry>  $query
     * @return Builder<FaqEntry>
     */
    public function scopeVisible(Builder $query): Builder
    {
        return $query->where('is_visible', true);
    }

    public function question(string $locale): string
    {
        if ($locale === 'en' && filled($this->question_en)) {
            return (string) $this->question_en;
        }

        return (string) $this->question_ar;
    }

    public function answer(string $locale): string
    {
        if ($locale === 'en' && filled($this->answer_en)) {
            return (string) $this->answer_en;
        }

        return (string) $this->answer_ar;
    }

    protected static function newFactory(): FaqEntryFactory
    {
        return FaqEntryFactory::new();
    }
}
