<?php

namespace App\Console\Commands;

use App\Customers\Actions\MergeCustomers as MergeCustomersAction;
use App\Customers\CustomerNumber;
use App\Models\Order;
use App\Models\User;
use App\Models\WalletAccount;
use Illuminate\Console\Command;
use InvalidArgumentException;

final class MergeCustomers extends Command
{
    protected $signature = 'customers:merge
        {from : The duplicate account: customer number, public id, or email}
        {into : The account that survives: customer number, public id, or email}
        {--wallet=drop : What to do with the duplicate balance: drop or transfer}
        {--email=into : Which email and password the survivor keeps: into or from}
        {--dry-run : Show what would move without changing anything}';

    protected $description = 'Fold a duplicate customer account into another (server operators only)';

    public function handle(MergeCustomersAction $merge): int
    {
        $fromHandle = $this->argument('from');
        $intoHandle = $this->argument('into');
        $from = is_string($fromHandle) ? $this->resolve($fromHandle) : null;
        $into = is_string($intoHandle) ? $this->resolve($intoHandle) : null;

        if (! $from instanceof User || ! $into instanceof User) {
            $this->components->error('Both accounts must exist. Use the customer number (CUS-…), the public id, or the email.');

            return self::FAILURE;
        }

        $walletOption = $this->option('wallet');
        $emailOption = $this->option('email');
        $wallet = is_string($walletOption) ? $walletOption : 'drop';
        $email = is_string($emailOption) ? $emailOption : 'into';

        $this->components->twoColumnDetail('Duplicate', $this->describe($from));
        $this->components->twoColumnDetail('Survivor', $this->describe($into));
        $orders = Order::query()->where('user_id', $from->id)->count();
        $balance = (int) (WalletAccount::query()->where('user_id', $from->id)->value('balance_halalah') ?? 0);
        $this->components->twoColumnDetail('Orders moving', (string) $orders);
        $this->components->twoColumnDetail('Duplicate wallet', number_format($balance / 100, 2).' SAR → '.$wallet);
        $this->components->twoColumnDetail('Survivor email', $email === 'from' ? (string) $from->email : (string) $into->email);

        if ((bool) $this->option('dry-run')) {
            $this->components->info('Dry run: nothing changed.');

            return self::SUCCESS;
        }

        try {
            $summary = $merge->execute($from, $into, $wallet, $email);
        } catch (InvalidArgumentException $exception) {
            $this->components->error($exception->getMessage());

            return self::INVALID;
        }

        $this->components->info(sprintf(
            'Merged into %s: %d rows moved, %s SAR settled from the duplicate wallet.',
            $summary['into_customer_number'] ?? $summary['into_public_id'],
            $summary['moved_rows'],
            number_format(((int) $summary['wallet_halalah']) / 100, 2),
        ));

        return self::SUCCESS;
    }

    private function resolve(string $handle): ?User
    {
        $handle = trim($handle);

        if ($handle === '') {
            return null;
        }

        $query = User::query();

        if (preg_match(CustomerNumber::PATTERN, strtoupper($handle)) === 1) {
            $query->where('customer_number', strtoupper($handle));
        } elseif (str_contains($handle, '@')) {
            $query->whereRaw('lower(email) = ?', [mb_strtolower($handle)]);
        } else {
            $query->where('public_id', $handle);
        }

        return $query->first();
    }

    private function describe(User $user): string
    {
        return sprintf('%s · %s · %s', $user->customer_number ?? $user->public_id, $user->name, $user->email ?? $user->phone ?? '—');
    }
}
