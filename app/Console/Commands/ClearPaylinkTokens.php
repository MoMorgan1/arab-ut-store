<?php

namespace App\Console\Commands;

use App\Services\Payments\PaylinkPaymentGateway;
use Illuminate\Console\Command;

final class ClearPaylinkTokens extends Command
{
    protected $signature = 'payments:clear-paylink-tokens';

    protected $description = 'Clear cached Paylink authentication tokens';

    public function handle(PaylinkPaymentGateway $gateway): int
    {
        $gateway->clearTokenCache();

        $this->info('Paylink authentication token cache cleared.');

        return self::SUCCESS;
    }
}
