<?php

namespace App\Console\Commands;

use App\Customers\CustomerNumber;
use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Console\Command;

/**
 * Give a short customer number to every customer that still shows a ULID.
 *
 * The creating hook only assigned numbers when the role was set explicitly, so
 * customers created through registration (where the role comes from the column
 * default) went without one and the admin fell back to the 26-character public
 * id. The hook is fixed; this catches up the rows it missed.
 */
final class BackfillCustomerNumbers extends Command
{
    protected $signature = 'customers:backfill-numbers';

    protected $description = 'Assign CUS- numbers to customers that were created without one';

    public function handle(): int
    {
        $assigned = 0;

        User::query()
            ->whereNull('customer_number')
            ->where('role', UserRole::Customer)
            ->orderBy('id')
            ->chunkById(200, function ($users) use (&$assigned): void {
                foreach ($users as $user) {
                    $user->forceFill(['customer_number' => CustomerNumber::generate()])->save();
                    $assigned++;
                }
            });

        $this->components->info("Assigned {$assigned} customer number(s).");

        return self::SUCCESS;
    }
}
