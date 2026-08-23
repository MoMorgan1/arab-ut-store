<?php

use App\Admin\Queries\CountCustomersPerTier;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\UserRole;
use App\Http\Middleware\EnsureAdminMfa;
use App\Models\LoyaltyTier;
use App\Models\Order;
use App\Models\StaffAuditLog;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia;
use Laravel\Fortify\Fortify;

afterEach(function (): void {
    Carbon::setTestNow();
});

test('guests and nonprivileged accounts cannot access loyalty page', function (): void {
    $this->get('/admin/marketing/loyalty')->assertRedirect('/en/login');

    foreach ([UserRole::Customer, UserRole::ServiceAccount] as $role) {
        $account = User::factory()->create(['role' => $role]);
        $this->actingAs($account)->get('/admin/marketing/loyalty')->assertForbidden();
    }

    $inactiveAdmin = createLoyaltyAdminActor(UserRole::Admin);
    $inactiveAdmin->forceFill(['is_active' => false])->save();
    $this->actingAs($inactiveAdmin)->get('/admin/marketing/loyalty')->assertForbidden();
});

test('staff users without loyalty permissions are forbidden from loyalty page', function (): void {
    $staff = createLoyaltyAdminActor(UserRole::Staff);

    $this->actingAs($staff)->get('/admin/marketing/loyalty')->assertForbidden();
});

test('unconfirmed MFA admin users are redirected to settings', function (): void {
    $admin = createLoyaltyAdminActor(UserRole::Admin);
    $admin->forceFill(['two_factor_confirmed_at' => null])->save();

    $this->actingAs($admin)
        ->get('/admin/marketing/loyalty')
        ->assertRedirect('/admin/settings');
});

test('confirmed Admin can open loyalty page and view tiers and KPIs', function (string $prefix): void {
    $admin = createLoyaltyAdminActor(UserRole::Admin);
    seedLoyaltyTiersFixture();

    $this->actingAs($admin)
        ->get("{$prefix}/marketing/loyalty")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('admin/marketing/loyalty', false)
            ->where('auth', null)
            ->where('locale', 'en')
            ->has('tiers', 4)
            ->has('kpis.customersPerTier')
            ->has('kpis.cashbackCreditedLast30Days')
            ->has('updateTierUrlTemplate')
            ->has('confirmPasswordUrl')
        );
})->with([
    'default prefix' => ['/admin'],
    'localized prefix' => ['/en/admin'],
]);

test('loyalty routes have required middlewares', function (): void {
    $pageRoute = Route::getRoutes()->getByName('admin.marketing.loyalty');
    expect($pageRoute)->not->toBeNull()
        ->and($pageRoute?->gatherMiddleware())->toContain(EnsureAdminMfa::class)
        ->and($pageRoute?->gatherMiddleware())->toContain('can:loyalty.view');

    $updateRoute = Route::getRoutes()->getByName('admin.marketing.loyalty.tiers.update');
    expect($updateRoute)->not->toBeNull()
        ->and($updateRoute?->gatherMiddleware())->toContain(EnsureAdminMfa::class)
        ->and($updateRoute?->gatherMiddleware())->toContain('password.confirm')
        ->and($updateRoute?->gatherMiddleware())->toContain('can:loyalty.manage');
});

test('updating loyalty tier without password confirmation returns 423', function (): void {
    $admin = createLoyaltyAdminActor(UserRole::Admin);
    $tiers = seedLoyaltyTiersFixture();
    $tier = $tiers['silver'];

    $this->actingAs($admin)
        ->putJson("/admin/api/marketing/loyalty/tiers/{$tier->public_id}", [
            'name_ar' => 'فضي معدل',
            'name_en' => 'Silver Updated',
            'minimum_lifetime_spend_halalah' => 60000,
            'cashback_basis_points' => 350,
            'is_active' => true,
        ])
        ->assertStatus(423);
});

test('updating loyalty tier enforces validation rules and invariant constraints', function (
    string $tierKey,
    array $payload,
    string $expectedErrorField,
): void {
    $admin = createLoyaltyAdminActor(UserRole::Admin);
    $tiers = seedLoyaltyTiersFixture();
    $tier = $tiers[$tierKey];

    $this->actingAs($admin)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->putJson("/admin/api/marketing/loyalty/tiers/{$tier->public_id}", $payload)
        ->assertStatus(422)
        ->assertJsonValidationErrors($expectedErrorField);
})->with([
    'rank 1 minimum spend must stay 0' => [
        'bronze',
        [
            'name_ar' => 'برونزي معدل',
            'name_en' => 'Bronze Modified',
            'minimum_lifetime_spend_halalah' => 5000,
            'cashback_basis_points' => 200,
            'is_active' => true,
        ],
        'minimum_lifetime_spend_halalah',
    ],
    'cashback basis points cannot exceed 2000' => [
        'silver',
        [
            'name_ar' => 'فضي',
            'name_en' => 'Silver',
            'minimum_lifetime_spend_halalah' => 50000,
            'cashback_basis_points' => 2500,
            'is_active' => true,
        ],
        'cashback_basis_points',
    ],
    'cashback basis points cannot be negative' => [
        'silver',
        [
            'name_ar' => 'فضي',
            'name_en' => 'Silver',
            'minimum_lifetime_spend_halalah' => 50000,
            'cashback_basis_points' => -10,
            'is_active' => true,
        ],
        'cashback_basis_points',
    ],
    'name_ar too short' => [
        'silver',
        [
            'name_ar' => 'أ',
            'name_en' => 'Silver',
            'minimum_lifetime_spend_halalah' => 50000,
            'cashback_basis_points' => 350,
            'is_active' => true,
        ],
        'name_ar',
    ],
    'name_en too long' => [
        'silver',
        [
            'name_ar' => 'فضي',
            'name_en' => str_repeat('A', 45),
            'minimum_lifetime_spend_halalah' => 50000,
            'cashback_basis_points' => 350,
            'is_active' => true,
        ],
        'name_en',
    ],
    'threshold inverted with higher tier' => [
        'silver',
        [
            'name_ar' => 'فضي',
            'name_en' => 'Silver',
            'minimum_lifetime_spend_halalah' => 200000, // Gold is 150,000, so silver cannot be 200,000
            'cashback_basis_points' => 350,
            'is_active' => true,
        ],
        'minimum_lifetime_spend_halalah',
    ],
    'threshold inverted with lower tier' => [
        'silver',
        [
            'name_ar' => 'فضي',
            'name_en' => 'Silver',
            'minimum_lifetime_spend_halalah' => 0, // Bronze is 0, so Silver cannot be <= 0
            'cashback_basis_points' => 350,
            'is_active' => true,
        ],
        'minimum_lifetime_spend_halalah',
    ],
    'unknown fields rejected' => [
        'silver',
        [
            'name_ar' => 'فضي',
            'name_en' => 'Silver',
            'minimum_lifetime_spend_halalah' => 50000,
            'cashback_basis_points' => 350,
            'is_active' => true,
            'extra_evil_field' => 'bad',
        ],
        'unexpected_fields',
    ],
]);

test('confirmed Admin can update loyalty tier and record staff audit log', function (): void {
    $admin = createLoyaltyAdminActor(UserRole::Admin);
    $tiers = seedLoyaltyTiersFixture();
    $tier = $tiers['silver'];

    $response = $this->actingAs($admin)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->putJson("/admin/api/marketing/loyalty/tiers/{$tier->public_id}", [
            'name_ar' => 'المستوى الفضي المطور',
            'name_en' => 'Silver Pro Tier',
            'minimum_lifetime_spend_halalah' => 75000,
            'cashback_basis_points' => 400,
            'is_active' => true,
        ]);

    $response->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertJson([
            'data' => [
                'id' => (string) $tier->public_id,
                'nameAr' => 'المستوى الفضي المطور',
                'nameEn' => 'Silver Pro Tier',
                'minimumLifetimeSpend' => [
                    'amountMinor' => '75000',
                    'currency' => 'SAR',
                ],
                'cashbackBasisPoints' => 400,
                'cashbackPercent' => '4.0%',
                'isActive' => true,
            ],
        ]);

    $fresh = $tier->fresh();
    expect($fresh->name_ar)->toBe('المستوى الفضي المطور')
        ->and($fresh->name_en)->toBe('Silver Pro Tier')
        ->and($fresh->minimum_lifetime_spend_halalah)->toBe(75000)
        ->and($fresh->cashback_basis_points)->toBe(400);

    $log = StaffAuditLog::query()
        ->where('auditable_type', $tier->getMorphClass())
        ->where('auditable_id', $tier->getKey())
        ->first();

    expect($log)->not->toBeNull()
        ->and($log->action)->toBe('loyalty.tier_updated')
        ->and($log->actor_user_id)->toBe($admin->id)
        ->and($log->metadata['new']['minimum_lifetime_spend_halalah'])->toBe(75000);
});

test('CountCustomersPerTier counts customers correctly based on completed eligible spend', function (): void {
    $tiers = seedLoyaltyTiersFixture();

    // Customer 1: 0 spend -> Bronze
    User::factory()->create(['role' => UserRole::Customer]);

    // Customer 2: 60,000 spend -> Silver
    $cust2 = User::factory()->create(['role' => UserRole::Customer]);
    $order2 = Order::factory()->for($cust2)->create([
        'currency' => 'SAR',
        'total_halalah' => 60000,
        'wallet_halalah' => 0,
        'status' => OrderStatus::Completed,
        'completed_at' => now(),
    ]);
    $order2->payments()->create([
        'provider' => 'paylink',
        'provider_payment_id' => (string) str()->ulid(),
        'status' => PaymentStatus::Paid,
        'currency' => 'SAR',
        'amount_halalah' => 60000,
        'captured_halalah' => 60000,
        'refunded_halalah' => 0,
        'idempotency_key' => 'paylink:'.hash('sha256', $order2->id.'|'.(string) str()->ulid()),
    ]);

    // Customer 3: 200,000 spend -> Gold
    $cust3 = User::factory()->create(['role' => UserRole::Customer]);
    $order3 = Order::factory()->for($cust3)->create([
        'currency' => 'SAR',
        'total_halalah' => 200000,
        'wallet_halalah' => 0,
        'status' => OrderStatus::Completed,
        'completed_at' => now(),
    ]);
    $order3->payments()->create([
        'provider' => 'paylink',
        'provider_payment_id' => (string) str()->ulid(),
        'status' => PaymentStatus::Paid,
        'currency' => 'SAR',
        'amount_halalah' => 200000,
        'captured_halalah' => 200000,
        'refunded_halalah' => 0,
        'idempotency_key' => 'paylink:'.hash('sha256', $order3->id.'|'.(string) str()->ulid()),
    ]);

    $counts = app(CountCustomersPerTier::class)->execute();

    expect($counts['bronze'])->toBe(1)
        ->and($counts['silver'])->toBe(1)
        ->and($counts['gold'])->toBe(1)
        ->and($counts['platinum'])->toBe(0);
});

/** @return array<string, LoyaltyTier> */
function seedLoyaltyTiersFixture(): array
{
    LoyaltyTier::query()->delete();

    $bronze = LoyaltyTier::query()->create([
        'key' => 'bronze',
        'name_ar' => 'برونزي',
        'name_en' => 'Bronze',
        'rank' => 1,
        'minimum_lifetime_spend_halalah' => 0,
        'cashback_basis_points' => 200,
        'is_active' => true,
    ]);

    $silver = LoyaltyTier::query()->create([
        'key' => 'silver',
        'name_ar' => 'فضي',
        'name_en' => 'Silver',
        'rank' => 2,
        'minimum_lifetime_spend_halalah' => 50000,
        'cashback_basis_points' => 350,
        'is_active' => true,
    ]);

    $gold = LoyaltyTier::query()->create([
        'key' => 'gold',
        'name_ar' => 'ذهبي',
        'name_en' => 'Gold',
        'rank' => 3,
        'minimum_lifetime_spend_halalah' => 150000,
        'cashback_basis_points' => 500,
        'is_active' => true,
    ]);

    $platinum = LoyaltyTier::query()->create([
        'key' => 'platinum',
        'name_ar' => 'بلاتيني',
        'name_en' => 'Platinum',
        'rank' => 4,
        'minimum_lifetime_spend_halalah' => 500000,
        'cashback_basis_points' => 750,
        'is_active' => true,
    ]);

    return [
        'bronze' => $bronze,
        'silver' => $silver,
        'gold' => $gold,
        'platinum' => $platinum,
    ];
}

function createLoyaltyAdminActor(UserRole $role, string $locale = 'en'): User
{
    $actor = User::factory()->create([
        'role' => $role,
        'preferred_locale' => $locale,
        'password' => 'SecurePassword!12',
    ]);
    $actor->forceFill([
        'two_factor_secret' => Fortify::currentEncrypter()->encrypt('ADMINLOYALTYTOTPSECRET'),
        'two_factor_confirmed_at' => now(),
    ])->save();

    return $actor;
}
