<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_number_sequence', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('next_value');
            $table->timestamps();
        });

        // A single row that checkout locks and increments. Deriving the number
        // from MAX(order_number) instead would hand the same number to two
        // checkouts running at once, and the orders table is not a sequence:
        // the existing rows carry the older random format.
        DB::table('order_number_sequence')->insert([
            'next_value' => 1000,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('order_number_sequence');
    }
};
