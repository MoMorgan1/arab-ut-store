<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $this->rejectDuplicateActiveCarts();
        $driver = DB::connection()->getDriverName();

        if ($driver === 'sqlite') {
            $this->backfillSqlite();
            $this->enforceSqlite();

            return;
        }

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            $this->backfillMariaDb();
            $this->enforceMariaDb();
        }
    }

    public function down(): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'sqlite') {
            DB::statement('DROP TRIGGER IF EXISTS carts_derive_active_owner_insert');
            DB::statement('DROP TRIGGER IF EXISTS carts_derive_active_owner_update');
            DB::statement('DROP INDEX IF EXISTS carts_one_active_authenticated_sar');
        } elseif (in_array($driver, ['mysql', 'mariadb'], true)) {
            DB::statement('ALTER TABLE carts MODIFY active_owner_key VARCHAR(255) NULL');
        }
    }

    private function rejectDuplicateActiveCarts(): void
    {
        $duplicateExists = DB::table('carts')
            ->whereNotNull('user_id')
            ->where('status', 'active')
            ->where('currency', 'SAR')
            ->groupBy('user_id')
            ->havingRaw('COUNT(*) > 1')
            ->exists();

        if ($duplicateExists) {
            throw new RuntimeException(
                'Cannot enforce active cart invariant: duplicate active authenticated SAR carts exist.',
            );
        }
    }

    private function backfillSqlite(): void
    {
        DB::statement(<<<'SQL'
            UPDATE carts
            SET active_owner_key = CASE
                WHEN user_id IS NOT NULL AND status = 'active' AND currency = 'SAR'
                    THEN 'user:' || user_id
                ELSE NULL
            END
            SQL);
    }

    private function enforceSqlite(): void
    {
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX carts_one_active_authenticated_sar
            ON carts (CASE
                WHEN user_id IS NOT NULL AND status = 'active' AND currency = 'SAR'
                    THEN user_id
                ELSE NULL
            END)
            SQL);
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER carts_derive_active_owner_insert
            AFTER INSERT ON carts
            BEGIN
                UPDATE carts
                SET active_owner_key = CASE
                    WHEN NEW.user_id IS NOT NULL AND NEW.status = 'active' AND NEW.currency = 'SAR'
                        THEN 'user:' || NEW.user_id
                    ELSE NULL
                END
                WHERE id = NEW.id;
            END
            SQL);
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER carts_derive_active_owner_update
            AFTER UPDATE OF user_id, status, currency, active_owner_key ON carts
            WHEN COALESCE(NEW.active_owner_key, '') <> COALESCE(
                CASE
                    WHEN NEW.user_id IS NOT NULL AND NEW.status = 'active' AND NEW.currency = 'SAR'
                        THEN 'user:' || NEW.user_id
                    ELSE NULL
                END,
                ''
            )
            BEGIN
                UPDATE carts
                SET active_owner_key = CASE
                    WHEN NEW.user_id IS NOT NULL AND NEW.status = 'active' AND NEW.currency = 'SAR'
                        THEN 'user:' || NEW.user_id
                    ELSE NULL
                END
                WHERE id = NEW.id;
            END
            SQL);
    }

    private function backfillMariaDb(): void
    {
        DB::statement(<<<'SQL'
            UPDATE carts
            SET active_owner_key = CASE
                WHEN user_id IS NOT NULL AND status = 'active' AND currency = 'SAR'
                    THEN CONCAT('user:', user_id)
                ELSE NULL
            END
            SQL);
    }

    private function enforceMariaDb(): void
    {
        DB::statement(<<<'SQL'
            ALTER TABLE carts
            MODIFY active_owner_key VARCHAR(255)
            GENERATED ALWAYS AS (
                CASE
                    WHEN user_id IS NOT NULL AND status = 'active' AND currency = 'SAR'
                        THEN CONCAT('user:', user_id)
                    ELSE NULL
                END
            ) STORED
            SQL);
    }
};
