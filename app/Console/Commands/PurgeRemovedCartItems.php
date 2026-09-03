<?php

namespace App\Console\Commands;

use App\Actions\Cart\PurgeRemovedCartItems as PurgeRemovedCartItemsAction;
use Illuminate\Console\Command;

final class PurgeRemovedCartItems extends Command
{
    protected $signature = 'cart-items:purge-removed';

    protected $description = 'Hard-delete soft-removed cart items past the undo window';

    public function handle(PurgeRemovedCartItemsAction $purgeRemovedCartItems): int
    {
        $purgeCount = $purgeRemovedCartItems->execute();

        $this->components->info("Purged {$purgeCount} removed cart item(s).");

        return self::SUCCESS;
    }
}
