<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reviews', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('order_item_id')->nullable()->constrained()->nullOnDelete();
            $table->string('reviewer_name');
            $table->unsignedTinyInteger('rating');
            $table->text('body_ar')->nullable();
            $table->text('body_en')->nullable();
            $table->string('source')->nullable();
            $table->boolean('is_visible')->default(true)->index();
            $table->timestamp('published_at')->nullable()->index();
            $table->timestamps();
        });

        Schema::create('faq_entries', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->string('question_ar');
            $table->string('question_en');
            $table->text('answer_ar');
            $table->text('answer_en');
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_visible')->default(true)->index();
            $table->timestamps();
        });

        Schema::create('exchange_rates', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->string('base_currency', 3)->default('SAR');
            $table->string('quote_currency', 3);
            $table->decimal('rate', 20, 8);
            $table->string('source');
            $table->timestamp('fetched_at')->index();
            $table->timestamps();
            $table->unique(['base_currency', 'quote_currency']);
        });

        $this->enforceValueRanges();
    }

    public function down(): void
    {
        Schema::dropIfExists('exchange_rates');
        Schema::dropIfExists('faq_entries');
        Schema::dropIfExists('reviews');
    }

    private function enforceValueRanges(): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'sqlite') {
            DB::statement("CREATE TRIGGER reviews_rating_range_insert BEFORE INSERT ON reviews WHEN NEW.rating < 1 OR NEW.rating > 5 BEGIN SELECT RAISE(ABORT, 'rating must be between 1 and 5'); END");
            DB::statement("CREATE TRIGGER reviews_rating_range_update BEFORE UPDATE OF rating ON reviews WHEN NEW.rating < 1 OR NEW.rating > 5 BEGIN SELECT RAISE(ABORT, 'rating must be between 1 and 5'); END");
            DB::statement("CREATE TRIGGER exchange_rates_rate_positive_insert BEFORE INSERT ON exchange_rates WHEN NEW.rate <= 0 BEGIN SELECT RAISE(ABORT, 'rate must be positive'); END");
            DB::statement("CREATE TRIGGER exchange_rates_rate_positive_update BEFORE UPDATE OF rate ON exchange_rates WHEN NEW.rate <= 0 BEGIN SELECT RAISE(ABORT, 'rate must be positive'); END");
        } elseif (in_array($driver, ['mysql', 'mariadb'], true)) {
            DB::statement('ALTER TABLE reviews ADD CONSTRAINT reviews_rating_range CHECK (rating BETWEEN 1 AND 5)');
            DB::statement('ALTER TABLE exchange_rates ADD CONSTRAINT exchange_rates_rate_positive CHECK (rate > 0)');
        }
    }
};
