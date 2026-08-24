<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('external_refs', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->string('source')->default('salla');
            $table->string('entity'); // 'customer' | 'order'
            $table->string('external_id');
            $table->unsignedBigInteger('internal_id');
            $table->timestamps();

            $table->unique(['source', 'entity', 'external_id']);
            $table->index(['entity', 'internal_id']);
        });

        Schema::create('import_batches', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->string('source')->default('salla');
            $table->string('filename');
            $table->string('checksum', 64);
            $table->string('status')->default('completed');
            $table->unsignedInteger('created_count')->default(0);
            $table->unsignedInteger('updated_count')->default(0);
            $table->unsignedInteger('skipped_count')->default(0);
            $table->unsignedInteger('conflict_count')->default(0);
            $table->json('report')->nullable();
            $table->boolean('dry_run')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('import_batches');
        Schema::dropIfExists('external_refs');
    }
};
