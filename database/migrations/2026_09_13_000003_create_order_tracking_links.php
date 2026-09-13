<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_tracking_links', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('order_id')->unique()->constrained()->cascadeOnDelete();

            // Two columns carry the same token because they answer two different
            // questions. `token_hash` is what a request is looked up by: a stolen
            // database dump cannot be replayed against the route by reading a
            // column, because the only way back to the token is reversing a
            // SHA-256 digest, and not storing it queryable is the whole point.
            // The encrypted copy exists because the link has to be re-sendable:
            // the same link goes out with the first WhatsApp message and again
            // when support resends it, and a link the store cannot reconstruct
            // is one that breaks the second time anyone needs it. Encryption is
            // the same protection `order_item_secrets` already gives a far more
            // valuable secret, so this is the project's existing standard rather
            // than a new one.
            $table->string('token_hash', 64)->unique();
            $table->text('token_encrypted');
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_tracking_links');
    }
};
