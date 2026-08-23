<?php

use App\Customers\CustomerNumber;
use App\Enums\UserRole;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('customer_number', 16)->nullable()->unique()->after('public_id');
        });

        // Existing customers get a number too, so support can quote one for any
        // account rather than only those created after this deploy.
        $used = [];

        DB::table('users')
            ->select('id')
            ->where('role', UserRole::Customer->value)
            ->orderBy('id')
            ->chunkById(500, function ($rows) use (&$used): void {
                foreach ($rows as $row) {
                    do {
                        $candidate = CustomerNumber::candidate();
                    } while (isset($used[$candidate]));

                    $used[$candidate] = true;

                    DB::table('users')
                        ->where('id', $row->id)
                        ->update(['customer_number' => $candidate]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropUnique(['customer_number']);
            $table->dropColumn('customer_number');
        });
    }
};
