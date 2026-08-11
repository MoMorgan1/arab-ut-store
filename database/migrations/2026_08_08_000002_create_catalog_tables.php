<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\ColumnDefinition;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @var array<string, list<string>> */
    private array $nonnegativeColumns = [
        'product_variants' => ['price_halalah', 'sale_price_halalah'],
        'price_proposals' => ['current_price_halalah', 'proposed_price_halalah'],
        'price_history' => ['price_halalah', 'sale_price_halalah'],
    ];

    public function up(): void
    {
        Schema::create('catalog_sources', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->string('key')->unique();
            $table->string('name');
            $table->string('authority')->default('automation');
            $table->boolean('is_enabled')->default(true)->index();
            $table->timestamps();
        });

        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('source_id')->nullable()->constrained('catalog_sources')->restrictOnDelete();
            $table->string('external_id')->nullable();
            $table->string('slug')->unique();
            $table->string('name_ar');
            $table->string('name_en');
            $table->text('description_ar')->nullable();
            $table->text('description_en')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_visible')->default(true)->index();
            $table->timestamps();
            $table->unique(['source_id', 'external_id']);
        });

        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('category_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('source_id')->nullable()->constrained('catalog_sources')->restrictOnDelete();
            $table->string('external_id')->nullable();
            $table->string('slug')->unique();
            $table->string('service_type')->index();
            $table->string('authority')->default('manual')->index();
            $table->string('name_ar');
            $table->string('name_en');
            $table->text('description_ar')->nullable();
            $table->text('description_en')->nullable();
            $table->boolean('is_visible')->default(true)->index();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamp('archived_at')->nullable()->index();
            $table->timestamps();
            $table->unique(['source_id', 'external_id']);
        });

        Schema::create('product_variants', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('source_id')->nullable()->constrained('catalog_sources')->restrictOnDelete();
            $table->string('external_id')->nullable();
            $table->string('sku')->unique();
            $table->string('service_type');
            $table->string('platform');
            $table->string('market');
            $table->string('authority')->default('manual');
            $table->string('name_ar')->nullable();
            $table->string('name_en')->nullable();
            $table->unsignedInteger('quantity_k')->nullable();
            $this->nonnegativeMoneyColumn($table, 'price_halalah');
            $this->nonnegativeMoneyColumn($table, 'sale_price_halalah')->nullable();
            $table->unsignedInteger('price_version')->default(1);
            $table->json('configuration')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['source_id', 'external_id']);
            $table->index(['service_type', 'platform', 'is_active']);
        });

        Schema::create('product_media', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('disk')->default('public');
            $table->string('path');
            $table->string('alt_ar')->nullable();
            $table->string('alt_en')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->unique(['product_id', 'path']);
        });

        Schema::create('catalog_sync_runs', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('source_id')->constrained('catalog_sources')->cascadeOnDelete();
            $table->string('run_id')->unique();
            $table->string('status')->index();
            $table->boolean('is_complete_snapshot')->default(true);
            $table->unsignedInteger('source_count')->default(0);
            $table->unsignedInteger('applied_count')->default(0);
            $table->unsignedInteger('held_count')->default(0);
            $table->unsignedInteger('failed_count')->default(0);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('catalog_sync_items', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('catalog_sync_run_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('product_variant_id')->nullable()->constrained()->nullOnDelete();
            $table->string('external_id')->index();
            $table->string('outcome')->index();
            $table->json('payload')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();
            $table->unique(['catalog_sync_run_id', 'external_id']);
        });

        Schema::create('price_rules', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('product_variant_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('service_type')->nullable()->index();
            $table->string('platform')->nullable()->index();
            $table->json('configuration');
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
        });

        Schema::create('price_runs', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('source_id')->nullable()->constrained('catalog_sources')->nullOnDelete();
            $table->string('run_id')->unique();
            $table->string('status')->index();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('price_proposals', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('price_run_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_variant_id')->constrained()->cascadeOnDelete();
            $this->nonnegativeMoneyColumn($table, 'current_price_halalah');
            $this->nonnegativeMoneyColumn($table, 'proposed_price_halalah');
            $table->unsignedInteger('expected_version');
            $table->string('outcome')->index();
            $table->text('reason')->nullable();
            $table->timestamps();
            $table->unique(['price_run_id', 'product_variant_id']);
        });

        Schema::create('price_history', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('product_variant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('price_run_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('changed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $this->nonnegativeMoneyColumn($table, 'price_halalah');
            $this->nonnegativeMoneyColumn($table, 'sale_price_halalah')->nullable();
            $table->unsignedInteger('version');
            $table->timestamp('effective_at')->index();
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['product_variant_id', 'version']);
        });

        $this->enforceNonnegativeMoney();
        $this->enforceCompleteSourceIdentities();
    }

    public function down(): void
    {
        foreach (['price_history', 'price_proposals', 'price_runs', 'price_rules', 'catalog_sync_items', 'catalog_sync_runs', 'product_media', 'product_variants', 'products', 'categories', 'catalog_sources'] as $table) {
            Schema::dropIfExists($table);
        }
    }

    private function enforceNonnegativeMoney(): void
    {
        $driver = DB::connection()->getDriverName();

        foreach ($this->nonnegativeColumns as $table => $columns) {
            foreach ($columns as $column) {
                $name = "{$table}_{$column}_nonnegative";

                if (in_array($driver, ['mysql', 'mariadb'], true)) {
                    DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$name} CHECK ({$column} BETWEEN 0 AND 9223372036854775807)");
                }
            }
        }
    }

    private function enforceCompleteSourceIdentities(): void
    {
        $driver = DB::connection()->getDriverName();

        foreach (['categories', 'products', 'product_variants'] as $table) {
            if ($driver === 'sqlite') {
                $condition = '(NEW.source_id IS NULL AND NEW.external_id IS NOT NULL) OR (NEW.source_id IS NOT NULL AND NEW.external_id IS NULL)';
                DB::statement("CREATE TRIGGER {$table}_source_identity_insert BEFORE INSERT ON {$table} WHEN {$condition} BEGIN SELECT RAISE(ABORT, 'source identity must be complete'); END");
                DB::statement("CREATE TRIGGER {$table}_source_identity_update BEFORE UPDATE OF source_id, external_id ON {$table} WHEN {$condition} BEGIN SELECT RAISE(ABORT, 'source identity must be complete'); END");
            } elseif (in_array($driver, ['mysql', 'mariadb'], true)) {
                DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$table}_source_identity_complete CHECK ((source_id IS NULL AND external_id IS NULL) OR (source_id IS NOT NULL AND external_id IS NOT NULL))");
            }
        }
    }

    private function nonnegativeMoneyColumn(Blueprint $table, string $column): ColumnDefinition
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            return $table->rawColumn($column, "integer check ({$column} between 0 and 9223372036854775807)");
        }

        return $table->bigInteger($column);
    }
};
