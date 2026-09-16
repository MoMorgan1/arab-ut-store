<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fulfillment_alarms', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('order_item_id')->constrained()->cascadeOnDelete();
            $table->string('kind');
            $table->timestamp('raised_at');
            $table->timestamp('notified_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->json('context')->nullable();
            $table->timestamps();

            // One row per item per kind, reused when the same silence returns.
            // This is a state table, not a log: what an operator needs is "is
            // this item silent right now and was I told", and a second row for
            // the same item answering the same question twice is how a panel
            // learns to double-count.
            $table->unique(['order_item_id', 'kind']);

            // The two reads: the admin panel counts what is still open, and the
            // sweep collects what is open and unsent.
            $table->index(['resolved_at', 'notified_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fulfillment_alarms');
    }
};
