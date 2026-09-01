<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_variants', function (Blueprint $table): void {
            $table->string('sbc_category')->nullable()->after('configuration');
            $table->index(['product_id', 'is_active', 'sbc_category']);
        });

        $driver = DB::connection()->getDriverName();
        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            DB::statement("UPDATE product_variants SET sbc_category = JSON_UNQUOTE(JSON_EXTRACT(configuration, '$.sbcCategory')) WHERE configuration IS NOT NULL AND JSON_EXTRACT(configuration, '$.sbcCategory') IS NOT NULL");
        } elseif ($driver === 'sqlite') {
            DB::statement("UPDATE product_variants SET sbc_category = json_extract(configuration, '$.sbcCategory') WHERE configuration IS NOT NULL AND json_extract(configuration, '$.sbcCategory') IS NOT NULL");
        }
    }

    public function down(): void
    {
        // MySQL and MariaDB back the product_id foreign key with an index. The
        // table was created with the implicit one InnoDB adds for a constraint,
        // and InnoDB silently dropped it when up() added a composite index
        // starting with product_id, since that could serve the key instead.
        // Dropping the composite now would leave the key with nothing (error
        // 1553), so give it a standalone index back first. SQLite never had
        // the implicit index, so there is nothing to restore there.
        $driver = DB::connection()->getDriverName();
        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            Schema::table('product_variants', function (Blueprint $table): void {
                $table->index('product_id');
            });
        }

        Schema::table('product_variants', function (Blueprint $table): void {
            $table->dropIndex(['product_id', 'is_active', 'sbc_category']);
            $table->dropColumn('sbc_category');
        });
    }
};
