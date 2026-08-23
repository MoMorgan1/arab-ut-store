<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The same admin override as products, for categories.
     *
     * A category's `is_visible` is written by the catalog snapshot exactly the
     * way a product's is, so without this an admin could not take a whole
     * category off the storefront and have the decision survive a sync.
     * Hiding a category hides everything under it, because the product
     * visibility predicate checks the category too.
     */
    public function up(): void
    {
        Schema::table('categories', function (Blueprint $table): void {
            $table->timestamp('admin_hidden_at')->nullable()->after('is_visible')->index();
        });
    }

    public function down(): void
    {
        Schema::table('categories', function (Blueprint $table): void {
            $table->dropColumn('admin_hidden_at');
        });
    }
};
