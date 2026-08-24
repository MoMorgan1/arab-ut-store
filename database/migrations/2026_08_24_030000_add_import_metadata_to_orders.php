<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Provenance for an imported order whose money was converted.
     *
     * Salla orders arrive in fourteen currencies. They are converted to SAR at
     * import so they count toward lifetime spend and loyalty tiers like any
     * other order - the tier queries only ever look at SAR. Converting is
     * lossy and one-way, so the original currency, the original amount and the
     * exact rate used are recorded here: without them nobody could later audit
     * a total, re-run the conversion at a better rate, or answer a customer
     * asking why their order reads 102.20 SAR when they paid 8.37 KWD.
     */
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->json('import_metadata')->nullable()->after('currency');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropColumn('import_metadata');
        });
    }
};
