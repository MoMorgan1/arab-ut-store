<?php

namespace Database\Factories;

use App\Models\StorePage;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StorePage>
 */
class StorePageFactory extends Factory
{
    protected $model = StorePage::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'key' => 'privacy',
            'title_ar' => 'سياسة الخصوصية',
            'title_en' => 'Privacy Policy',
            'subtitle_ar' => 'وصف توضيحي',
            'subtitle_en' => 'Explanatory subtitle',
            'updated_label_ar' => '٢ سبتمبر ٢٠٢٦',
            'updated_label_en' => '2 September 2026',
            'blocks_ar' => [
                ['type' => 'paragraph', 'content' => [['text' => 'محتوى تجريبي']]],
            ],
            'blocks_en' => [
                ['type' => 'paragraph', 'content' => [['text' => 'Test content']]],
            ],
        ];
    }
}
