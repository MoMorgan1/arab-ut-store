<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $this->rejectDuplicateActiveOwners();
        $driver = DB::connection()->getDriverName();

        if ($driver === 'sqlite') {
            $this->replaceSqliteInvariant(includeGuests: true);

            return;
        }

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            $this->replaceMariaDbInvariant(includeGuests: true);
        }
    }

    public function down(): void
    {
        $this->rejectDuplicateActiveUsers();
        $driver = DB::connection()->getDriverName();

        if ($driver === 'sqlite') {
            $this->replaceSqliteInvariant(includeGuests: false);

            return;
        }

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            $this->replaceMariaDbInvariant(includeGuests: false);
        }
    }

    private function rejectDuplicateActiveOwners(): void
    {
        if ($this->duplicateActiveUsersExist() || $this->duplicateActiveGuestsExist()) {
            throw new RuntimeException(
                'Cannot enforce active cart invariant: duplicate active cart owners exist.',
            );
        }
    }

    private function rejectDuplicateActiveUsers(): void
    {
        if ($this->duplicateActiveUsersExist()) {
            throw new RuntimeException(
                'Cannot restore active cart invariant: duplicate active authenticated carts exist.',
            );
        }
    }

    private function duplicateActiveUsersExist(): bool
    {
        return DB::table('carts')
            ->whereNotNull('user_id')
            ->where('status', 'active')
            ->where('currency', 'SAR')
            ->groupBy('user_id')
            ->havingRaw('COUNT(*) > 1')
            ->exists();
    }

    private function duplicateActiveGuestsExist(): bool
    {
        return DB::table('carts')
            ->whereNull('user_id')
            ->whereNotNull('session_key')
            ->where('status', 'active')
            ->where('currency', 'SAR')
            ->groupBy('session_key')
            ->havingRaw('COUNT(*) > 1')
            ->exists();
    }

    private function replaceSqliteInvariant(bool $includeGuests): void
    {
        DB::statement('DROP TRIGGER IF EXISTS carts_derive_active_owner_insert');
        DB::statement('DROP TRIGGER IF EXISTS carts_derive_active_owner_update');
        DB::statement('DROP INDEX IF EXISTS carts_one_active_authenticated_sar');
        DB::statement('DROP INDEX IF EXISTS carts_one_active_owner');

        if ($includeGuests) {
            DB::statement('DROP INDEX IF EXISTS carts_active_owner_key_unique');
        }

        $expression = $this->sqliteOwnerExpression($includeGuests);

        DB::statement("UPDATE carts SET active_owner_key = {$expression}");
        $indexName = $this->sqliteIndexName($includeGuests);
        DB::statement(<<<SQL
            CREATE UNIQUE INDEX {$indexName}
            ON carts ({$expression})
            SQL);

        if (! $includeGuests) {
            DB::statement(
                'CREATE UNIQUE INDEX carts_active_owner_key_unique ON carts (active_owner_key)',
            );
        }

        $this->installSqliteTriggers($includeGuests);
    }

    private function installSqliteTriggers(bool $includeGuests): void
    {
        if ($includeGuests) {
            DB::unprepared(<<<'SQL'
                CREATE TRIGGER carts_derive_active_owner_insert
                AFTER INSERT ON carts
                BEGIN
                    UPDATE carts
                    SET active_owner_key = CASE
                        WHEN NEW.user_id IS NOT NULL AND NEW.status = 'active' AND NEW.currency = 'SAR'
                            THEN 'user:' || NEW.user_id
                        WHEN NEW.user_id IS NULL AND NEW.session_key IS NOT NULL
                            AND NEW.status = 'active' AND NEW.currency = 'SAR'
                            THEN 'guest:' || NEW.session_key
                        ELSE NULL
                    END
                    WHERE id = NEW.id;
                END
                SQL);
            DB::unprepared(<<<'SQL'
                CREATE TRIGGER carts_derive_active_owner_update
                AFTER UPDATE OF user_id, session_key, status, currency, active_owner_key ON carts
                WHEN COALESCE(NEW.active_owner_key, '') <> COALESCE(
                    CASE
                        WHEN NEW.user_id IS NOT NULL AND NEW.status = 'active' AND NEW.currency = 'SAR'
                            THEN 'user:' || NEW.user_id
                        WHEN NEW.user_id IS NULL AND NEW.session_key IS NOT NULL
                            AND NEW.status = 'active' AND NEW.currency = 'SAR'
                            THEN 'guest:' || NEW.session_key
                        ELSE NULL
                    END,
                    ''
                )
                BEGIN
                    UPDATE carts
                    SET active_owner_key = CASE
                        WHEN NEW.user_id IS NOT NULL AND NEW.status = 'active' AND NEW.currency = 'SAR'
                            THEN 'user:' || NEW.user_id
                        WHEN NEW.user_id IS NULL AND NEW.session_key IS NOT NULL
                            AND NEW.status = 'active' AND NEW.currency = 'SAR'
                            THEN 'guest:' || NEW.session_key
                        ELSE NULL
                    END
                    WHERE id = NEW.id;
                END
                SQL);

            return;
        }

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER carts_derive_active_owner_insert
            AFTER INSERT ON carts
            BEGIN
                UPDATE carts SET active_owner_key = CASE
                    WHEN NEW.user_id IS NOT NULL AND NEW.status = 'active' AND NEW.currency = 'SAR'
                        THEN 'user:' || NEW.user_id
                    ELSE NULL
                END
                WHERE id = NEW.id;
            END
            SQL);
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER carts_derive_active_owner_update
            AFTER UPDATE OF user_id, session_key, status, currency, active_owner_key ON carts
            WHEN COALESCE(NEW.active_owner_key, '') <> COALESCE(
                CASE
                    WHEN NEW.user_id IS NOT NULL AND NEW.status = 'active' AND NEW.currency = 'SAR'
                        THEN 'user:' || NEW.user_id
                    ELSE NULL
                END,
                ''
            )
            BEGIN
                UPDATE carts SET active_owner_key = CASE
                    WHEN NEW.user_id IS NOT NULL AND NEW.status = 'active' AND NEW.currency = 'SAR'
                        THEN 'user:' || NEW.user_id
                    ELSE NULL
                END
                WHERE id = NEW.id;
            END
            SQL);
    }

    private function sqliteOwnerExpression(bool $includeGuests): string
    {
        $guestBranch = $includeGuests
            ? "WHEN user_id IS NULL AND session_key IS NOT NULL AND status = 'active' AND currency = 'SAR' THEN 'guest:' || session_key"
            : '';

        return <<<SQL
            CASE
                WHEN user_id IS NOT NULL AND status = 'active' AND currency = 'SAR'
                    THEN 'user:' || user_id
                {$guestBranch}
                ELSE NULL
            END
            SQL;
    }

    private function sqliteIndexName(bool $includeGuests): string
    {
        return $includeGuests ? 'carts_one_active_owner' : 'carts_one_active_authenticated_sar';
    }

    private function replaceMariaDbInvariant(bool $includeGuests): void
    {
        $guestBranch = $includeGuests
            ? "WHEN user_id IS NULL AND session_key IS NOT NULL AND status = 'active' AND currency = 'SAR' THEN CONCAT('guest:', session_key)"
            : '';

        DB::statement(<<<SQL
            ALTER TABLE carts
            MODIFY active_owner_key VARCHAR(255)
            GENERATED ALWAYS AS (
                CASE
                    WHEN user_id IS NOT NULL AND status = 'active' AND currency = 'SAR'
                        THEN CONCAT('user:', user_id)
                    {$guestBranch}
                    ELSE NULL
                END
            ) STORED
            SQL);
    }
};
