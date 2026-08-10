<?php

namespace App\Console\Commands;

use App\Models\CartItemSecret;
use Illuminate\Console\Command;

final class PurgeCartItemSecrets extends Command
{
    protected $signature = 'cart-secrets:purge';

    protected $description = 'Purge expired Coins cart credentials while retaining safe cart lines';

    public function handle(): int
    {
        $purgeCount = CartItemSecret::query()
            ->where('retained_until', '<=', now())
            ->where(function ($cartSecrets): void {
                $cartSecrets->whereNotNull('encrypted_payload')
                    ->orWhereNotNull('masked_summary')
                    ->orWhereNull('deleted_at');
            })
            ->update([
                'encrypted_payload' => null,
                'masked_summary' => null,
                'deleted_at' => now(),
                'updated_at' => now(),
            ]);

        $this->components->info("Purged {$purgeCount} expired cart secret(s).");

        return self::SUCCESS;
    }
}
