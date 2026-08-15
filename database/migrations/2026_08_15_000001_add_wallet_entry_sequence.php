<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const UNIQUE_INDEX = 'wallet_entries_account_sequence_unique';

    public function up(): void
    {
        $this->dropLedgerProtection();

        try {
            Schema::table('wallet_entries', function (Blueprint $table): void {
                $table->unsignedBigInteger('sequence')->nullable()->after('wallet_account_id');
            });

            $this->backfillSequences();

            Schema::table('wallet_entries', function (Blueprint $table): void {
                $table->unsignedBigInteger('sequence')->nullable(false)->change();
                $table->unique(['wallet_account_id', 'sequence'], self::UNIQUE_INDEX);
            });
        } finally {
            $this->restoreLedgerProtection();
        }
    }

    public function down(): void
    {
        $this->dropLedgerProtection();

        try {
            Schema::table('wallet_entries', function (Blueprint $table): void {
                $table->dropUnique(self::UNIQUE_INDEX);
                $table->dropColumn('sequence');
            });
        } finally {
            $this->restoreLedgerProtection();
        }
    }

    private function backfillSequences(): void
    {
        DB::table('wallet_accounts')
            ->select('id')
            ->orderBy('id')
            ->chunkById(100, function ($accounts): void {
                foreach ($accounts as $account) {
                    $sequence = 0;

                    DB::table('wallet_entries')
                        ->select('id')
                        ->where('wallet_account_id', $account->id)
                        ->orderBy('created_at')
                        ->orderBy('id')
                        ->chunk(500, function ($entries) use (&$sequence): void {
                            foreach ($entries as $entry) {
                                DB::table('wallet_entries')
                                    ->where('id', $entry->id)
                                    ->update(['sequence' => ++$sequence]);
                            }
                        });
                }
            });
    }

    private function dropLedgerProtection(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS wallet_entries_immutable_update');
        DB::statement('DROP TRIGGER IF EXISTS wallet_entries_immutable_delete');
    }

    private function restoreLedgerProtection(): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'sqlite') {
            DB::statement("CREATE TRIGGER wallet_entries_immutable_update BEFORE UPDATE ON wallet_entries BEGIN SELECT RAISE(ABORT, 'wallet entries are immutable'); END");
            DB::statement("CREATE TRIGGER wallet_entries_immutable_delete BEFORE DELETE ON wallet_entries BEGIN SELECT RAISE(ABORT, 'wallet entries are immutable'); END");
        } elseif (in_array($driver, ['mysql', 'mariadb'], true)) {
            DB::unprepared("CREATE TRIGGER wallet_entries_immutable_update BEFORE UPDATE ON wallet_entries FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'wallet entries are immutable'");
            DB::unprepared("CREATE TRIGGER wallet_entries_immutable_delete BEFORE DELETE ON wallet_entries FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'wallet entries are immutable'");
        }
    }
};
