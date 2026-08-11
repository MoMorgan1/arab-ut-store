<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('carts', function (Blueprint $table): void {
            $table->string('active_owner_key')->nullable()->unique()->after('session_key');
        });

        Schema::create('cart_item_secrets', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('cart_item_id')->unique()->constrained()->cascadeOnDelete();
            $table->longText('encrypted_payload')->nullable();
            $table->json('masked_summary')->nullable();
            $table->timestamp('retained_until')->nullable()->index();
            $table->timestamp('deleted_at')->nullable()->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cart_item_secrets');

        Schema::table('carts', function (Blueprint $table): void {
            $table->dropUnique(['active_owner_key']);
            $table->dropColumn('active_owner_key');
        });
    }
};
