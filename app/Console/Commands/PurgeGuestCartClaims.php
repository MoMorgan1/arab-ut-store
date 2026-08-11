<?php

namespace App\Console\Commands;

use App\Actions\Cart\PurgeGuestCartClaims as PurgeGuestCartClaimsAction;
use Illuminate\Console\Command;

final class PurgeGuestCartClaims extends Command
{
    protected $signature = 'guest-cart-claims:purge';

    protected $description = 'Purge expired guest cart ownership markers';

    public function handle(PurgeGuestCartClaimsAction $purgeGuestCartClaims): int
    {
        $purgeCount = $purgeGuestCartClaims->execute();

        $this->components->info("Purged {$purgeCount} expired guest cart claim marker(s).");

        return self::SUCCESS;
    }
}
