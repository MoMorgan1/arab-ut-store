<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function (): void {
            if (DB::table('faq_entries')->count() > 0) {
                return;
            }

            /** @var list<array{question_ar: string, question_en: string, answer_ar: string, answer_en: string}> $entries */
            $entries = require database_path('seeders/data/faq_entries.php');
            $now = now();

            foreach ($entries as $index => $entry) {
                DB::table('faq_entries')->insert([
                    'public_id' => (string) Str::ulid(),
                    'question_ar' => $entry['question_ar'],
                    'question_en' => $entry['question_en'],
                    'answer_ar' => $entry['answer_ar'],
                    'answer_en' => $entry['answer_en'],
                    'sort_order' => ($index + 1) * 10,
                    'is_visible' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        });
    }

    public function down(): void
    {
        // Content migration: down() is intentionally a no-op.
    }
};
