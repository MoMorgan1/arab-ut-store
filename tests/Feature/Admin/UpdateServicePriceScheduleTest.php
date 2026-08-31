<?php

use App\Actions\Pricing\ReadManualServicePricing;
use App\Enums\ServiceType;
use App\Enums\UserRole;
use App\Models\ServicePriceSchedule;
use App\Models\StaffAuditLog;
use App\Models\User;
use App\Services\Catalog\CoinsCatalogReader;
use App\ValueObjects\Pricing\FutChampionsPricing;
use App\ValueObjects\Pricing\RivalsPricing;
use Illuminate\Support\Str;
use Laravel\Fortify\Contracts\TwoFactorAuthenticationProvider;
use Laravel\Fortify\Fortify;

beforeEach(function (): void {
    // Ensure both schedules exist as seeded by migration
    ServicePriceSchedule::query()->firstOrCreate(
        ['service_type' => ServiceType::FutChampions],
        [
            'public_id' => (string) Str::ulid(),
            'version' => 1,
            'configuration' => [
                'ranks' => [
                    '1' => 22000,
                    '2' => 19000,
                    '3' => 17000,
                    '4' => 15000,
                    '5' => 13000,
                    '6' => 10000,
                ],
                'urgent_surcharge_halalah' => 4000,
            ],
            'is_active' => true,
        ]
    );

    ServicePriceSchedule::query()->firstOrCreate(
        ['service_type' => ServiceType::Rivals],
        [
            'public_id' => (string) Str::ulid(),
            'version' => 1,
            'configuration' => [
                'steps' => [
                    '7:6' => 11000,
                    '6:5' => 12000,
                    '5:4' => 13000,
                    '4:3' => 14000,
                    '3:2' => 15000,
                    '2:1' => 16000,
                    '1:elite' => 17000,
                ],
            ],
            'is_active' => true,
        ]
    );
});

test('Admin updates FUT Champions ranks; version increments by 1; config round-trips; audit is written', function (): void {
    $admin = createPricingTestAdmin(UserRole::Admin);
    $schedule = ServicePriceSchedule::query()->where('service_type', ServiceType::FutChampions)->firstOrFail();
    $initialVersion = (int) $schedule->version;

    $newConfig = [
        'ranks' => [
            1 => 25000,
            2 => 21000,
            3 => 18000,
            4 => 16000,
            5 => 14000,
            6 => 11000,
        ],
        'urgent_surcharge_halalah' => 5000,
    ];

    $response = $this->actingAs($admin)
        ->withSession(['auth.password_confirmed_at' => now()->timestamp])
        ->postJson('/admin/api/settings/service-pricing/fut_champions', [
            'expected_version' => $initialVersion,
            'configuration' => $newConfig,
        ]);

    $response->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertJson([
            'data' => [
                'serviceType' => 'fut_champions',
                'version' => $initialVersion + 1,
                'isActive' => true,
            ],
        ]);

    $refreshed = $schedule->fresh();
    expect($refreshed->version)->toBe($initialVersion + 1);

    // Verify value object round-trip
    $pricing = FutChampionsPricing::fromConfiguration($refreshed->configuration);
    expect($pricing->priceForRank(1, urgent: false))->toBe(25000)
        ->and($pricing->priceForRank(1, urgent: true))->toBe(30000)
        ->and($pricing->urgentSurcharge())->toBe(5000);

    $audit = StaffAuditLog::query()->latest('id')->first();
    expect($audit)->not->toBeNull()
        ->and($audit?->action)->toBe('settings.service_pricing_updated')
        ->and($audit?->actor_user_id)->toBe($admin->id)
        ->and($audit?->metadata['service_type'])->toBe('fut_champions')
        ->and($audit?->metadata['previous_version'])->toBe($initialVersion)
        ->and($audit?->metadata['new_version'])->toBe($initialVersion + 1)
        ->and($audit?->metadata['prices_changed'])->toContain('ranks.1', 'urgent_surcharge_halalah');
});

test('Admin updates Rivals steps; version increments by 1; config round-trips; audit is written', function (): void {
    $admin = createPricingTestAdmin(UserRole::Admin);
    $schedule = ServicePriceSchedule::query()->where('service_type', ServiceType::Rivals)->firstOrFail();
    $initialVersion = (int) $schedule->version;

    $newConfig = [
        'steps' => [
            '7:6' => 12000,
            '6:5' => 13000,
            '5:4' => 14000,
            '4:3' => 15000,
            '3:2' => 16000,
            '2:1' => 17000,
            '1:elite' => 18000,
        ],
    ];

    $response = $this->actingAs($admin)
        ->withSession(['auth.password_confirmed_at' => now()->timestamp])
        ->postJson('/admin/api/settings/service-pricing/rivals', [
            'expected_version' => $initialVersion,
            'configuration' => $newConfig,
        ]);

    $response->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertJson([
            'data' => [
                'serviceType' => 'rivals',
                'version' => $initialVersion + 1,
                'isActive' => true,
            ],
        ]);

    $refreshed = $schedule->fresh();
    expect($refreshed->version)->toBe($initialVersion + 1);

    // Verify value object round-trip
    $pricing = RivalsPricing::fromConfiguration($refreshed->configuration);
    expect($pricing->priceForRoute('7', '6'))->toBe(12000)
        ->and($pricing->priceForRoute('7', 'elite'))->toBe(105000);

    $audit = StaffAuditLog::query()->latest('id')->first();
    expect($audit)->not->toBeNull()
        ->and($audit?->action)->toBe('settings.service_pricing_updated')
        ->and($audit?->actor_user_id)->toBe($admin->id)
        ->and($audit?->metadata['service_type'])->toBe('rivals')
        ->and($audit?->metadata['previous_version'])->toBe($initialVersion)
        ->and($audit?->metadata['new_version'])->toBe($initialVersion + 1)
        ->and($audit?->metadata['prices_changed'])->toContain('steps.7:6', 'steps.1:elite');
});

test('stale expected_version returns 409 and leaves row untouched in database', function (): void {
    $admin = createPricingTestAdmin(UserRole::Admin);
    $schedule = ServicePriceSchedule::query()->where('service_type', ServiceType::FutChampions)->firstOrFail();
    $originalConfig = $schedule->configuration;
    $currentVersion = (int) $schedule->version;

    $staleVersion = $currentVersion + 5; // Stale!

    $response = $this->actingAs($admin)
        ->withSession(['auth.password_confirmed_at' => now()->timestamp])
        ->postJson('/admin/api/settings/service-pricing/fut_champions', [
            'expected_version' => $staleVersion,
            'configuration' => [
                'ranks' => [
                    1 => 99000,
                    2 => 99000,
                    3 => 99000,
                    4 => 99000,
                    5 => 99000,
                    6 => 99000,
                ],
                'urgent_surcharge_halalah' => 99000,
            ],
        ]);

    $response->assertStatus(409)
        ->assertJson([
            'serviceType' => 'fut_champions',
            'version' => $currentVersion,
            'isActive' => true,
            'configuration' => $originalConfig,
        ]);

    // Assert the database row is untouched
    $freshSchedule = $schedule->fresh();
    expect($freshSchedule->version)->toBe($currentVersion)
        ->and($freshSchedule->configuration)->toBe($originalConfig);
});

test('configuration missing a rank, carrying an extra key, or containing zero/negative price returns 422 and writes nothing', function (array $invalidConfig): void {
    $admin = createPricingTestAdmin(UserRole::Admin);
    $schedule = ServicePriceSchedule::query()->where('service_type', ServiceType::FutChampions)->firstOrFail();
    $originalConfig = $schedule->configuration;
    $currentVersion = (int) $schedule->version;

    $response = $this->actingAs($admin)
        ->withSession(['auth.password_confirmed_at' => now()->timestamp])
        ->postJson('/admin/api/settings/service-pricing/fut_champions', [
            'expected_version' => $currentVersion,
            'configuration' => $invalidConfig,
        ]);

    $response->assertStatus(422);

    $freshSchedule = $schedule->fresh();
    expect($freshSchedule->version)->toBe($currentVersion)
        ->and($freshSchedule->configuration)->toBe($originalConfig);
})->with([
    'missing rank 6' => [
        [
            'ranks' => [
                1 => 22000,
                2 => 19000,
                3 => 17000,
                4 => 15000,
                5 => 13000,
            ],
            'urgent_surcharge_halalah' => 4000,
        ],
    ],
    'carrying extra rank 7' => [
        [
            'ranks' => [
                1 => 22000,
                2 => 19000,
                3 => 17000,
                4 => 15000,
                5 => 13000,
                6 => 10000,
                7 => 5000,
            ],
            'urgent_surcharge_halalah' => 4000,
        ],
    ],
    'carrying extra config field' => [
        [
            'ranks' => [
                1 => 22000,
                2 => 19000,
                3 => 17000,
                4 => 15000,
                5 => 13000,
                6 => 10000,
            ],
            'urgent_surcharge_halalah' => 4000,
            'unexpected_key' => 1234,
        ],
    ],
    'price is zero' => [
        [
            'ranks' => [
                1 => 22000,
                2 => 19000,
                3 => 0,
                4 => 15000,
                5 => 13000,
                6 => 10000,
            ],
            'urgent_surcharge_halalah' => 4000,
        ],
    ],
    'urgent surcharge is negative' => [
        [
            'ranks' => [
                1 => 22000,
                2 => 19000,
                3 => 17000,
                4 => 15000,
                5 => 13000,
                6 => 10000,
            ],
            'urgent_surcharge_halalah' => -100,
        ],
    ],
]);

test('Staff user, inactive admin, and ServiceAccount are refused', function (): void {
    $customer = createPricingTestAdmin(UserRole::Customer);
    $serviceAccount = createPricingTestAdmin(UserRole::ServiceAccount);
    $staff = createPricingTestAdmin(UserRole::Staff);
    $inactiveAdmin = createPricingTestAdmin(UserRole::Admin);
    $inactiveAdmin->forceFill(['is_active' => false])->save();

    $validPayload = [
        'expected_version' => 1,
        'configuration' => [
            'ranks' => [
                1 => 22000,
                2 => 19000,
                3 => 17000,
                4 => 15000,
                5 => 13000,
                6 => 10000,
            ],
            'urgent_surcharge_halalah' => 4000,
        ],
    ];

    $this->actingAs($customer)
        ->postJson('/admin/api/settings/service-pricing/fut_champions', $validPayload)
        ->assertForbidden();

    $this->actingAs($serviceAccount)
        ->postJson('/admin/api/settings/service-pricing/fut_champions', $validPayload)
        ->assertForbidden();

    $this->actingAs($staff)
        ->withSession(['auth.password_confirmed_at' => now()->timestamp])
        ->postJson('/admin/api/settings/service-pricing/fut_champions', $validPayload)
        ->assertForbidden();

    $this->actingAs($inactiveAdmin)
        ->withSession(['auth.password_confirmed_at' => now()->timestamp])
        ->postJson('/admin/api/settings/service-pricing/fut_champions', $validPayload)
        ->assertForbidden();
});

test('toggling is_active off makes ReadManualServicePricing throw and toggling back restores it', function (): void {
    $admin = createPricingTestAdmin(UserRole::Admin);
    $reader = app(ReadManualServicePricing::class);

    // Initial state is active: reader works without exception
    $initialRead = $reader->futChampions();
    expect($initialRead['schedule']->is_active)->toBeTrue();

    // Toggle is_active off (deactivate)
    $response = $this->actingAs($admin)
        ->withSession(['auth.password_confirmed_at' => now()->timestamp])
        ->postJson('/admin/api/settings/service-pricing/fut_champions/status', [
            'action' => 'deactivate',
            'expected_active' => true,
        ]);

    $response->assertOk()
        ->assertJson([
            'data' => [
                'serviceType' => 'fut_champions',
                'isActive' => false,
            ],
        ]);

    // Reader must now throw DomainException
    expect(fn () => $reader->futChampions())->toThrow(DomainException::class);

    // Staff audit is recorded
    $deactivateAudit = StaffAuditLog::query()->latest('id')->first();
    expect($deactivateAudit?->action)->toBe('settings.service_pricing_deactivated')
        ->and($deactivateAudit?->metadata['previous_active'])->toBeTrue()
        ->and($deactivateAudit?->metadata['new_active'])->toBeFalse();

    // Toggle is_active back on (activate)
    $restoreResponse = $this->actingAs($admin)
        ->withSession(['auth.password_confirmed_at' => now()->timestamp])
        ->postJson('/admin/api/settings/service-pricing/fut_champions/status', [
            'action' => 'activate',
            'expected_active' => false,
        ]);

    $restoreResponse->assertOk()
        ->assertJson([
            'data' => [
                'serviceType' => 'fut_champions',
                'isActive' => true,
            ],
        ]);

    // Reader is now restored and works again
    $restoredRead = $reader->futChampions();
    expect($restoredRead['schedule']->is_active)->toBeTrue();

    $activateAudit = StaffAuditLog::query()->latest('id')->first();
    expect($activateAudit?->action)->toBe('settings.service_pricing_activated')
        ->and($activateAudit?->metadata['previous_active'])->toBeFalse()
        ->and($activateAudit?->metadata['new_active'])->toBeTrue();
});

test('unknown service type is a validation error, not a 500', function (): void {
    $admin = createPricingTestAdmin(UserRole::Admin);

    $response = $this->actingAs($admin)
        ->withSession(['auth.password_confirmed_at' => now()->timestamp])
        ->postJson('/admin/api/settings/service-pricing/unknown_service', [
            'expected_version' => 1,
            'configuration' => ['foo' => 'bar'],
        ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['service_type']);

    $statusResponse = $this->actingAs($admin)
        ->withSession(['auth.password_confirmed_at' => now()->timestamp])
        ->postJson('/admin/api/settings/service-pricing/unknown_service/status', [
            'action' => 'deactivate',
            'expected_active' => true,
        ]);

    $statusResponse->assertStatus(422)
        ->assertJsonValidationErrors(['service_type']);
});

function createPricingTestAdmin(UserRole $role): User
{
    $secret = app(TwoFactorAuthenticationProvider::class)->generateSecretKey();
    $user = User::factory()->create([
        'role' => $role,
        'password' => 'SecurePassword!12',
        'is_active' => true,
    ]);
    $user->forceFill([
        'two_factor_secret' => Fortify::currentEncrypter()->encrypt($secret),
        'two_factor_confirmed_at' => now(),
    ])->save();

    return $user;
}

test('Admin edits the Coins quantity bands and the storefront follows without a deploy', function (): void {
    $admin = createPricingTestAdmin(UserRole::Admin);
    $schedule = ServicePriceSchedule::query()->where('service_type', ServiceType::Coins)->firstOrFail();
    $initialVersion = (int) $schedule->version;

    // Coarsen the top band: one nudge at three million should move further.
    $newConfig = [
        'minimum' => 100_000,
        'roundingUnit' => 5_000,
        'tiers' => [
            ['upTo' => 1_000_000, 'step' => 100_000],
            ['upTo' => 20_000_000, 'step' => 500_000],
        ],
        'presets' => [100_000, 500_000, 1_000_000, 5_000_000],
    ];

    $response = $this->actingAs($admin)
        ->withSession(['auth.password_confirmed_at' => now()->timestamp])
        ->postJson('/admin/api/settings/service-pricing/coins', [
            'expected_version' => $initialVersion,
            'configuration' => $newConfig,
        ]);

    $response->assertOk()
        ->assertJson(['data' => ['serviceType' => 'coins', 'version' => $initialVersion + 1]]);

    $rules = app(CoinsCatalogReader::class)->quantityRules();

    expect($rules->minimum())->toBe(100_000)
        ->and($rules->stepAt(500_000))->toBe(100_000)
        ->and($rules->stepAt(3_000_000))->toBe(500_000)
        ->and($rules->accepts(200_000))->toBeTrue()
        // Between two band steps, so the slider will not stop here - but the
        // customer can still type it, because it is a whole rounding unit.
        ->and($rules->accepts(150_000))->toBeTrue()
        ->and($rules->accepts(152_000))->toBeFalse()
        ->and($rules->accepts(50_000))->toBeFalse();
});

test('Admin turns the Coins balance requirement on and off and the storefront follows', function (): void {
    $admin = createPricingTestAdmin(UserRole::Admin);
    $schedule = ServicePriceSchedule::query()->where('service_type', ServiceType::Coins)->firstOrFail();

    expect(app(CoinsCatalogReader::class)->requiresCurrentBalance())->toBeFalse();

    $configuration = [
        ...(array) $schedule->configuration,
        'requiresCurrentBalance' => true,
    ];

    $this->actingAs($admin)
        ->withSession(['auth.password_confirmed_at' => now()->timestamp])
        ->postJson('/admin/api/settings/service-pricing/coins', [
            'expected_version' => (int) $schedule->version,
            'configuration' => $configuration,
        ])
        ->assertOk();

    expect(app(CoinsCatalogReader::class)->requiresCurrentBalance())->toBeTrue();

    $configuration['requiresCurrentBalance'] = false;

    $this->actingAs($admin)
        ->withSession(['auth.password_confirmed_at' => now()->timestamp])
        ->postJson('/admin/api/settings/service-pricing/coins', [
            'expected_version' => (int) $schedule->version + 1,
            'configuration' => $configuration,
        ])
        ->assertOk();

    expect(app(CoinsCatalogReader::class)->requiresCurrentBalance())->toBeFalse();
});

test('Admin cannot save Coins bands the storefront could not price', function (array $configuration, string $why): void {
    $admin = createPricingTestAdmin(UserRole::Admin);
    $schedule = ServicePriceSchedule::query()->where('service_type', ServiceType::Coins)->firstOrFail();
    $before = (array) $schedule->configuration;

    $response = $this->actingAs($admin)
        ->withSession(['auth.password_confirmed_at' => now()->timestamp])
        ->postJson('/admin/api/settings/service-pricing/coins', [
            'expected_version' => (int) $schedule->version,
            'configuration' => $configuration,
        ]);

    $response->assertUnprocessable();

    // A rejected save must leave the live bands untouched, or the storefront
    // would start refusing quantities it still advertises.
    expect((array) $schedule->fresh()->configuration)->toBe($before, $why);
})->with([
    'a band that does not divide by its own step' => [[
        'minimum' => 50_000,
        'roundingUnit' => 5_000,
        'tiers' => [['upTo' => 100_001, 'step' => 10_000]],
        'presets' => [],
    ], 'an indivisible band leaves a quantity the schedule cannot price'],
    'bands that descend' => [[
        'minimum' => 50_000,
        'roundingUnit' => 5_000,
        'tiers' => [['upTo' => 500_000, 'step' => 10_000], ['upTo' => 100_000, 'step' => 10_000]],
        'presets' => [],
    ], 'descending bands make the ceiling ambiguous'],
    'a preset nobody can select' => [[
        'minimum' => 50_000,
        'roundingUnit' => 5_000,
        'tiers' => [['upTo' => 500_000, 'step' => 10_000]],
        'presets' => [52_000],
    ], 'a quick-pick button must be a quantity a customer can actually buy'],
]);

test('a stray field in the Coins configuration is refused, not quietly stored', function (array $configuration): void {
    // The other two services already refuse unknown keys. Coins accepted them,
    // which meant a typo could sit in the row forever, unread and unnoticed.
    $admin = createPricingTestAdmin(UserRole::Admin);
    $schedule = ServicePriceSchedule::query()->where('service_type', ServiceType::Coins)->firstOrFail();
    $before = (array) $schedule->configuration;

    $this->actingAs($admin)
        ->withSession(['auth.password_confirmed_at' => now()->timestamp])
        ->postJson('/admin/api/settings/service-pricing/coins', [
            'expected_version' => (int) $schedule->version,
            'configuration' => $configuration,
        ])
        ->assertUnprocessable();

    expect((array) $schedule->fresh()->configuration)->toBe($before);
})->with([
    'an unknown top-level key' => [[
        'minimum' => 50_000,
        'roundingUnit' => 5_000,
        'tiers' => [['upTo' => 500_000, 'step' => 10_000]],
        'presets' => [],
        'increment' => 10_000,
    ]],
    'an unknown key inside a band' => [[
        'minimum' => 50_000,
        'roundingUnit' => 5_000,
        'tiers' => [['upTo' => 500_000, 'step' => 10_000, 'label' => 'small']],
        'presets' => [],
    ]],
]);

test('an edit that touches only a newly editable field is still saved', function (array $configuration, string $expectedAuditKey): void {
    // The save was gated on a hand-written per-field diff, so any field added
    // later was invisible to it: the request validated, returned 200, and wrote
    // nothing. Weekly matches could never be put on sale, and the coins
    // rounding unit and quick amounts could never be changed on their own.
    $admin = createPricingTestAdmin(UserRole::Admin);
    $serviceType = isset($configuration['steps']) ? ServiceType::Rivals : ServiceType::Coins;
    $schedule = ServicePriceSchedule::query()->where('service_type', $serviceType)->firstOrFail();
    $initialVersion = (int) $schedule->version;

    $this->actingAs($admin)
        ->withSession(['auth.password_confirmed_at' => now()->timestamp])
        ->postJson('/admin/api/settings/service-pricing/'.$serviceType->value, [
            'expected_version' => $initialVersion,
            'configuration' => $configuration,
        ])
        ->assertOk();

    $schedule->refresh();

    expect((int) $schedule->version)->toBe($initialVersion + 1)
        ->and((array) $schedule->configuration)->toBe($configuration);

    $audit = StaffAuditLog::query()->where('action', 'settings.service_pricing_updated')->latest('id')->first();

    expect($audit?->metadata['prices_changed'])->toContain($expectedAuditKey);
})->with([
    'weekly matches put on sale, steps untouched' => [[
        'steps' => [
            '7:6' => 11_000, '6:5' => 12_000, '5:4' => 13_000, '4:3' => 14_000,
            '3:2' => 15_000, '2:1' => 16_000, '1:elite' => 17_000,
        ],
        'weeklyMatches' => ['priceHalalah' => 9_000, 'includedWins' => 8],
    ], 'weeklyMatches.priceHalalah'],
    'coins rounding unit alone' => [[
        'minimum' => 50_000,
        'roundingUnit' => 1_000,
        'tiers' => [
            ['upTo' => 500_000, 'step' => 10_000],
            ['upTo' => 2_000_000, 'step' => 50_000],
            ['upTo' => 20_000_000, 'step' => 250_000],
        ],
        'presets' => [50_000, 100_000, 500_000, 1_000_000, 5_000_000],
    ], 'roundingUnit'],
    'coins quick amounts alone' => [[
        'minimum' => 50_000,
        'roundingUnit' => 5_000,
        'tiers' => [
            ['upTo' => 500_000, 'step' => 10_000],
            ['upTo' => 2_000_000, 'step' => 50_000],
            ['upTo' => 20_000_000, 'step' => 250_000],
        ],
        'presets' => [100_000, 200_000],
    ], 'presets.0'],
]);

test('Coins bands that skip a platform ceiling are refused', function (): void {
    // A platform or delivery speed caps below the catalogue ceiling - console
    // normal stops at two million - and the storefront requires the quote
    // schedule to end exactly on that cap. Bands that step over it are valid on
    // their own terms yet leave the last stop below the cap, and the whole
    // delivery lane then renders as unavailable. Refuse at the save, where
    // there is somebody to tell.
    $admin = createPricingTestAdmin(UserRole::Admin);
    $schedule = ServicePriceSchedule::query()->where('service_type', ServiceType::Coins)->firstOrFail();
    $before = (array) $schedule->configuration;

    $this->actingAs($admin)
        ->withSession(['auth.password_confirmed_at' => now()->timestamp])
        ->postJson('/admin/api/settings/service-pricing/coins', [
            'expected_version' => (int) $schedule->version,
            'configuration' => [
                'minimum' => 50_000,
                'roundingUnit' => 5_000,
                'tiers' => [
                    ['upTo' => 550_000, 'step' => 125_000],
                    ['upTo' => 1_800_000, 'step' => 250_000],
                    // Steps over 2,000,000 without ever landing on it.
                    ['upTo' => 20_000_000, 'step' => 35_000],
                ],
                'presets' => [],
            ],
        ])
        ->assertUnprocessable();

    expect((array) $schedule->fresh()->configuration)->toBe($before);
});

test('Coins bands too fine to price ahead of time are refused', function (): void {
    // Every slider stop is priced on every homepage render, for three variants.
    // A step typed in coins instead of thousands turns that into thousands of
    // calculations per request.
    $admin = createPricingTestAdmin(UserRole::Admin);
    $schedule = ServicePriceSchedule::query()->where('service_type', ServiceType::Coins)->firstOrFail();
    $before = (array) $schedule->configuration;

    $this->actingAs($admin)
        ->withSession(['auth.password_confirmed_at' => now()->timestamp])
        ->postJson('/admin/api/settings/service-pricing/coins', [
            'expected_version' => (int) $schedule->version,
            'configuration' => [
                'minimum' => 50_000,
                'roundingUnit' => 5_000,
                'tiers' => [['upTo' => 20_000_000, 'step' => 5_000]],
                'presets' => [],
            ],
        ])
        ->assertUnprocessable();

    expect((array) $schedule->fresh()->configuration)->toBe($before);
});
