<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('store_pages')) {
            Schema::create('store_pages', function (Blueprint $table) {
                $table->id();
                $table->ulid('public_id')->unique();
                $table->string('key')->unique();
                $table->string('title_ar');
                $table->string('title_en');
                $table->string('subtitle_ar')->nullable();
                $table->string('subtitle_en')->nullable();
                $table->string('updated_label_ar');
                $table->string('updated_label_en');
                $table->json('blocks_ar');
                $table->json('blocks_en');
                $table->timestamps();
            });
        }

        DB::transaction(function (): void {
            if (DB::table('store_pages')->count() > 0) {
                return;
            }

            /** @var array<string, array{title: string, subtitle?: string, updated_label: string, blocks: list<array<string, mixed>>}> $arPages */
            $arPages = require database_path('seeders/data/store_pages/ar.php');
            /** @var array<string, array{title: string, subtitle?: string, updated_label: string, blocks: list<array<string, mixed>>}> $enPages */
            $enPages = require database_path('seeders/data/store_pages/en.php');
            $now = now();

            $keys = ['privacy', 'returns', 'warranty', 'terms', 'ea_backup_codes'];

            foreach ($keys as $key) {
                $ar = $arPages[$key];
                $en = $enPages[$key];

                DB::table('store_pages')->insert([
                    'public_id' => (string) Str::ulid(),
                    'key' => $key,
                    'title_ar' => $ar['title'],
                    'title_en' => $en['title'],
                    'subtitle_ar' => $ar['subtitle'] ?? null,
                    'subtitle_en' => $en['subtitle'] ?? null,
                    'updated_label_ar' => $ar['updated_label'],
                    'updated_label_en' => $en['updated_label'],
                    'blocks_ar' => json_encode($ar['blocks'], JSON_THROW_ON_ERROR),
                    'blocks_en' => json_encode($en['blocks'], JSON_THROW_ON_ERROR),
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('store_pages');
    }
};
