<?php

namespace Database\Factories;

use App\Models\User;
use App\Models\WalletAccount;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<WalletAccount> */
class WalletAccountFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'balance_halalah' => 0,
        ];
    }
}
