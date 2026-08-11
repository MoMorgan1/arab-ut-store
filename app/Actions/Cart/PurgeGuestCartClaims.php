<?php

namespace App\Actions\Cart;

use Carbon\CarbonInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class PurgeGuestCartClaims
{
    private const CHUNK_SIZE = 100;

    public function execute(): int
    {
        $retentionHours = config('coins.cart.guest_claim_retention_hours');

        if (! is_int($retentionHours) || $retentionHours < 1) {
            throw new RuntimeException('Guest cart claim retention is unavailable.');
        }

        $cutoff = now()->subHours($retentionHours);
        $purged = 0;

        do {
            $purgedChunk = DB::transaction(
                fn (): int => $this->purgeChunk($cutoff),
                attempts: 3,
            );
            $purged += $purgedChunk;
        } while ($purgedChunk === self::CHUNK_SIZE);

        return $purged;
    }

    private function purgeChunk(CarbonInterface $cutoff): int
    {
        $query = DB::table('guest_cart_claims')
            ->where('updated_at', '<=', $cutoff)
            ->orderBy('guest_session_hmac')
            ->limit(self::CHUNK_SIZE);
        $this->lockEligibleRows($query);
        $guestSessionHmacs = $query
            ->pluck('guest_session_hmac')
            ->map(fn (mixed $guestSessionHmac): string => (string) $guestSessionHmac)
            ->all();

        if ($guestSessionHmacs === []) {
            return 0;
        }

        return DB::table('guest_cart_claims')
            ->whereIn('guest_session_hmac', $guestSessionHmacs)
            ->where('updated_at', '<=', $cutoff)
            ->delete();
    }

    private function lockEligibleRows(Builder $query): void
    {
        $driver = DB::connection()->getDriverName();

        if (in_array($driver, ['mariadb', 'mysql', 'pgsql'], true)) {
            $query->lock('for update skip locked');

            return;
        }

        $query->lockForUpdate();
    }
}
