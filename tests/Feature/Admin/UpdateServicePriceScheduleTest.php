<?php

use App\Actions\Pricing\ReadManualServicePricing;
use App\Enums\ServiceType;
use App\Enums\UserRole;
use App\Models\ServicePriceSchedule;
use App\Models\StaffAuditLog;
use App\Models\User;
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

test('service pricing mutations require password confirmation', function (): void {
    $admin = createPricingTestAdmin(UserRole::Admin);

    $this->actingAs($admin)
        ->postJson('/admin/api/settings/service-pricing/fut_champions', [
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
        ])
        ->assertStatus(423);

    $this->actingAs($admin)
        ->postJson('/admin/api/settings/service-pricing/fut_champions/status', [
            'action' => 'deactivate',
            'expected_active' => true,
        ])
        ->assertStatus(423);
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
