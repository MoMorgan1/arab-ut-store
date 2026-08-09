<?php

namespace Database\Factories;

use App\Enums\WalletEntryType;
use App\Models\WalletAccount;
use App\Models\WalletEntry;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<WalletEntry> */
class WalletEntryFactory extends Factory
{
    public function definition(): array
    {
        return [
            'wallet_account_id' => WalletAccount::factory(),
            'type' => WalletEntryType::Credit,
            'amount_halalah' => 1_000,
            'balance_after_halalah' => 1_000,
            'reference' => (string) str()->ulid(),
            'metadata' => [],
        ];
    }
}
