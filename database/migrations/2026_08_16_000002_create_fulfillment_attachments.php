<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\ColumnDefinition;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fulfillment_attachments', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('cart_item_id')->nullable()->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('order_item_id')->nullable()->unique()->constrained()->cascadeOnDelete();
            $table->string('kind');
            $table->string('disk');
            $table->string('path')->unique();
            $table->string('mime_type', 64);
            $this->positiveBytesColumn($table);
            $table->char('sha256', 64);
            $table->timestamps();
            $table->index(['kind', 'created_at']);
        });

        $driver = DB::connection()->getDriverName();

        if ($driver === 'sqlite') {
            $invalidOwner = '(NEW.cart_item_id IS NULL AND NEW.order_item_id IS NULL) OR (NEW.cart_item_id IS NOT NULL AND NEW.order_item_id IS NOT NULL)';
            DB::statement("CREATE TRIGGER fulfillment_attachments_owner_insert BEFORE INSERT ON fulfillment_attachments WHEN {$invalidOwner} BEGIN SELECT RAISE(ABORT, 'fulfillment attachment must have exactly one owner'); END");
            DB::statement("CREATE TRIGGER fulfillment_attachments_owner_update BEFORE UPDATE OF cart_item_id, order_item_id ON fulfillment_attachments WHEN {$invalidOwner} BEGIN SELECT RAISE(ABORT, 'fulfillment attachment must have exactly one owner'); END");
        } elseif (in_array($driver, ['mysql', 'mariadb'], true)) {
            DB::statement('ALTER TABLE fulfillment_attachments ADD CONSTRAINT fulfillment_attachments_exactly_one_owner CHECK ((cart_item_id IS NULL) <> (order_item_id IS NULL))');
            DB::statement('ALTER TABLE fulfillment_attachments ADD CONSTRAINT fulfillment_attachments_bytes_valid CHECK (bytes BETWEEN 1 AND 5242880)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('fulfillment_attachments');
    }

    private function positiveBytesColumn(Blueprint $table): ColumnDefinition
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            return $table->rawColumn('bytes', 'integer not null check (bytes between 1 and 5242880)');
        }

        return $table->unsignedBigInteger('bytes');
    }
};
