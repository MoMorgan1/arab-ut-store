<?php

namespace App\Console\Commands;

use App\Models\LoyaltyTier;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

final class SeedLoyaltyTiers extends Command
{
    /**
     * @var array<int, array{key: string, name_ar: string, name_en: string, rank: int, minimum_lifetime_spend_halalah: int, cashback_basis_points: int}>
     */
    private const TIERS = [
        ['key' => 'bronze', 'name_ar' => 'برونزي', 'name_en' => 'Bronze', 'rank' => 1, 'minimum_lifetime_spend_halalah' => 0, 'cashback_basis_points' => 200],
        ['key' => 'silver', 'name_ar' => 'فضي', 'name_en' => 'Silver', 'rank' => 2, 'minimum_lifetime_spend_halalah' => 50_000, 'cashback_basis_points' => 300],
        ['key' => 'gold', 'name_ar' => 'ذهبي', 'name_en' => 'Gold', 'rank' => 3, 'minimum_lifetime_spend_halalah' => 200_000, 'cashback_basis_points' => 500],
        ['key' => 'platinum', 'name_ar' => 'بلاتيني', 'name_en' => 'Platinum', 'rank' => 4, 'minimum_lifetime_spend_halalah' => 1_000_000, 'cashback_basis_points' => 700],
    ];

    protected $signature = 'loyalty:seed-tiers';

    protected $description = 'Idempotently seed the four loyalty tiers with their cashback rates';

    public function handle(): int
    {
        DB::transaction(function (): void {
            foreach (self::TIERS as $tier) {
                LoyaltyTier::query()->updateOrCreate(
                    ['key' => $tier['key']],
                    [
                        'name_ar' => $tier['name_ar'],
                        'name_en' => $tier['name_en'],
                        'rank' => $tier['rank'],
                        'minimum_lifetime_spend_halalah' => $tier['minimum_lifetime_spend_halalah'],
                        'cashback_basis_points' => $tier['cashback_basis_points'],
                        'is_active' => true,
                    ],
                );
            }
        });

        $this->info('Loyalty tiers seeded.');

        return self::SUCCESS;
    }
}
