<?php

use App\Models\LoyaltyTier;

test('the seed command is idempotent and leaves exactly the four owner tiers', function (): void {
    $this->artisan('loyalty:seed-tiers')->assertSuccessful();
    $this->artisan('loyalty:seed-tiers')->assertSuccessful();

    $tiers = LoyaltyTier::query()->orderBy('rank')->get();

    expect($tiers)->toHaveCount(4)
        ->and($tiers->pluck('key')->all())->toBe(['bronze', 'silver', 'gold', 'platinum'])
        ->and($tiers->pluck('name_ar')->all())->toBe(['برونزي', 'فضي', 'ذهبي', 'بلاتيني'])
        ->and($tiers->pluck('name_en')->all())->toBe(['Bronze', 'Silver', 'Gold', 'Platinum'])
        ->and($tiers->pluck('minimum_lifetime_spend_halalah')->all())->toBe([0, 50_000, 200_000, 1_000_000])
        ->and($tiers->pluck('cashback_basis_points')->all())->toBe([200, 300, 500, 700])
        ->and($tiers->pluck('rank')->all())->toBe([1, 2, 3, 4])
        ->and($tiers->every(fn (LoyaltyTier $tier): bool => $tier->is_active === true))->toBeTrue();
});

test('the seed command repairs drifted tier values instead of duplicating rows', function (): void {
    LoyaltyTier::query()->create([
        'key' => 'gold',
        'name_ar' => 'قديم',
        'name_en' => 'Old Gold',
        'rank' => 9,
        'minimum_lifetime_spend_halalah' => 1,
        'cashback_basis_points' => 1,
        'is_active' => false,
    ]);

    $this->artisan('loyalty:seed-tiers')->assertSuccessful();

    expect(LoyaltyTier::query()->count())->toBe(4);

    $gold = LoyaltyTier::query()->where('key', 'gold')->sole();

    expect($gold->name_ar)->toBe('ذهبي')
        ->and($gold->rank)->toBe(3)
        ->and($gold->minimum_lifetime_spend_halalah)->toBe(200_000)
        ->and($gold->cashback_basis_points)->toBe(500)
        ->and($gold->is_active)->toBeTrue();
});
