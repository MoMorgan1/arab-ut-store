<?php

use App\Enums\UserRole;
use App\Http\Middleware\EnsureAdminMfa;
use App\Models\Coupon;
use App\Models\StaffAuditLog;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Laravel\Fortify\Fortify;

afterEach(function (): void {
    Carbon::setTestNow();
});

beforeEach(function (): void {
    Carbon::setTestNow(Carbon::parse('2026-08-23 12:00:00', 'UTC'));
});

test('guests and nonprivileged accounts cannot enter the marketing coupons page', function (): void {
    $this->get('/admin/marketing/coupons')->assertRedirect('/en/login');

    foreach ([UserRole::Customer, UserRole::ServiceAccount] as $role) {
        $account = User::factory()->create(['role' => $role]);
        $this->actingAs($account)->get('/admin/marketing/coupons')->assertForbidden();
    }
});

test('staff users are forbidden from the marketing coupons page', function (): void {
    $staff = adminCouponsActor(UserRole::Staff);

    $this->actingAs($staff)->get('/admin/marketing/coupons')->assertForbidden();
});

test('confirmed admin can open localized coupon pages with counts', function (string $path, string $direction): void {
    $admin = adminCouponsActor(UserRole::Admin);
    Coupon::query()->create(couponAttributes(['code' => 'WELCOME10', 'is_active' => true]));
    Coupon::query()->create(couponAttributes(['code' => 'EXPIRED5', 'is_active' => false]));

    $this->actingAs($admin)
        ->get($path)
        ->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('admin/marketing/coupons', false)
            ->where('auth', null)
            ->where('direction', $direction)
            ->has('coupons', 2)
            ->has('pagination')
            ->where('counts.total', 2)
            ->where('counts.active', 1)
            ->where('coupons.0.code', 'EXPIRED5'));
})->with([
    'Canonical' => ['/admin/marketing/coupons', 'ltr'],
    'Localized' => ['/en/admin/marketing/coupons', 'ltr'],
]);

test('the coupons route requires EnsureAdminMfa and can:marketing.view middleware', function (): void {
    $route = Route::getRoutes()->getByName('admin.marketing.coupons');

    expect($route)->not->toBeNull()
        ->and($route?->gatherMiddleware())->toContain(EnsureAdminMfa::class)
        ->and($route?->gatherMiddleware())->toContain('can:marketing.view');
});

test('confirmed admin can create a percent coupon with an audit log entry', function (): void {
    $admin = adminCouponsActor(UserRole::Admin);

    $response = $this->actingAs($admin)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->postJson('/admin/api/marketing/coupons', [
            'code' => 'SUMMER25',
            'discount_type' => 'percent',
            'value' => 25,
            'minimum_order_halalah' => 5000,
            'maximum_discount_halalah' => 10000,
            'usage_limit' => 100,
            'per_user_limit' => 2,
            'starts_at' => '2026-08-01T00:00:00+00:00',
            'ends_at' => '2026-09-01T00:00:00+00:00',
            'description_ar' => 'خصم الصيف',
            'description_en' => 'Summer discount',
            'is_active' => true,
        ]);

    $response->assertCreated()
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertJson(['data' => ['code' => 'SUMMER25', 'isActive' => true]]);

    $coupon = Coupon::query()->where('code', 'SUMMER25')->first();

    expect($coupon)->not->toBeNull()
        ->and($coupon?->value)->toBe(25)
        ->and($coupon?->minimum_order_halalah)->toBe(5000)
        ->and($coupon?->maximum_discount_halalah)->toBe(10000)
        ->and($coupon?->usage_limit)->toBe(100)
        ->and($coupon?->per_user_limit)->toBe(2)
        ->and($coupon?->starts_at?->format('Y-m-d'))->toBe('2026-08-01');

    $log = StaffAuditLog::query()->latest('id')->first();

    expect($log?->action)->toBe('coupons.created')
        ->and($log?->actor_user_id)->toBe($admin->id)
        ->and($log?->metadata['code'])->toBe('SUMMER25');
});

test('confirmed admin can update a coupon while keeping its identity', function (): void {
    $admin = adminCouponsActor(UserRole::Admin);
    $coupon = Coupon::query()->create(couponAttributes());
    Coupon::query()->create(couponAttributes(['code' => 'OTHERCODE']));

    $this->actingAs($admin)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->putJson("/admin/api/marketing/coupons/{$coupon->public_id}", [
            'code' => $coupon->code,
            'discount_type' => 'fixed',
            'value' => 1500,
            'minimum_order_halalah' => 0,
            'is_active' => false,
        ])
        ->assertOk()
        ->assertJson(['data' => ['id' => (string) $coupon->public_id, 'isActive' => false]]);

    $coupon->refresh();

    expect($coupon->discount_type)->toBe('fixed')
        ->and($coupon->value)->toBe(1500)
        ->and($coupon->maximum_discount_halalah)->toBeNull()
        ->and($coupon->usage_limit)->toBeNull()
        ->and($coupon->per_user_limit)->toBeNull()
        ->and($coupon->is_active)->toBeFalse();

    $log = StaffAuditLog::query()->latest('id')->first();

    expect($log?->action)->toBe('coupons.updated');
});

test('unconfirmed password returns 423 for coupon mutations', function (): void {
    $admin = adminCouponsActor(UserRole::Admin);
    $coupon = Coupon::query()->create(couponAttributes());

    $this->actingAs($admin)
        ->postJson('/admin/api/marketing/coupons', [
            'code' => 'NEWCODE1',
            'discount_type' => 'percent',
            'value' => 10,
            'minimum_order_halalah' => 0,
        ])
        ->assertStatus(423);

    $this->actingAs($admin)
        ->postJson("/admin/api/marketing/coupons/{$coupon->public_id}/status", ['is_active' => false])
        ->assertStatus(423);
});

test('staff actors are forbidden from coupon mutations even with confirmed passwords', function (): void {
    $staff = adminCouponsActor(UserRole::Staff);

    $this->actingAs($staff)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->postJson('/admin/api/marketing/coupons', [
            'code' => 'STAFFCODE',
            'discount_type' => 'percent',
            'value' => 10,
            'minimum_order_halalah' => 0,
        ])
        ->assertForbidden();
});

test('confirmed admin can toggle coupon status with an audit log entry', function (): void {
    $admin = adminCouponsActor(UserRole::Admin);
    $coupon = Coupon::query()->create(couponAttributes(['is_active' => true]));

    $this->actingAs($admin)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->postJson("/admin/api/marketing/coupons/{$coupon->public_id}/status", ['is_active' => false])
        ->assertOk()
        ->assertJson(['data' => ['isActive' => false]]);

    expect($coupon->fresh()->is_active)->toBeFalse();

    $log = StaffAuditLog::query()->latest('id')->first();

    expect($log?->action)->toBe('coupons.deactivated')
        ->and($log?->metadata['new_active'])->toBeFalse();

    $this->actingAs($admin)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->postJson("/admin/api/marketing/coupons/{$coupon->public_id}/status", ['is_active' => true])
        ->assertOk();

    expect($coupon->fresh()->is_active)->toBeTrue();
});

test('coupon create requests validate every rule boundary', function (array $payload, string $field): void {
    // Seed case-insensitively: codes are canonicalised to uppercase, so
    // 'duplicate' must collide with a stored 'DUPLICATE' as a validation
    // error rather than reaching the database unique index.
    if (mb_strtoupper($payload['code']) === 'DUPLICATE') {
        Coupon::query()->create(couponAttributes(['code' => 'DUPLICATE']));
    }

    $admin = adminCouponsActor(UserRole::Admin);

    $this->actingAs($admin)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->postJson('/admin/api/marketing/coupons', $payload)
        ->assertStatus(422)
        ->assertJsonValidationErrors($field);
})->with([
    'code too short' => [couponPayload(['code' => 'AB']), 'code'],
    'case-variant duplicate' => [couponPayload(['code' => 'duplicate']), 'code'],
    'code invalid characters' => [couponPayload(['code' => 'BAD CODE!']), 'code'],
    'duplicate code' => [couponPayload(['code' => 'DUPLICATE']), 'code'],
    'percent above 100' => [couponPayload(['discount_type' => 'percent', 'value' => 101]), 'value'],
    'percent zero' => [couponPayload(['discount_type' => 'percent', 'value' => 0]), 'value'],
    'fixed below one riyal' => [couponPayload(['discount_type' => 'fixed', 'value' => 99]), 'value'],
    'negative minimum order' => [couponPayload(['minimum_order_halalah' => -1]), 'minimum_order_halalah'],
    'negative maximum discount' => [couponPayload(['maximum_discount_halalah' => -5]), 'maximum_discount_halalah'],
    'maximum on fixed type' => [couponPayload(['discount_type' => 'fixed', 'value' => 500, 'maximum_discount_halalah' => 100]), 'maximum_discount_halalah'],
    'zero usage limit' => [couponPayload(['usage_limit' => 0]), 'usage_limit'],
    'zero per user limit' => [couponPayload(['per_user_limit' => 0]), 'per_user_limit'],
    'ends before starts' => [couponPayload(['starts_at' => '2026-08-01T00:00:00+00:00', 'ends_at' => '2026-07-01T00:00:00+00:00']), 'ends_at'],
    'unknown discount type' => [couponPayload(['discount_type' => 'bogo']), 'discount_type'],
]);

test('update requests reject a duplicate code owned by another coupon', function (): void {
    $admin = adminCouponsActor(UserRole::Admin);
    $coupon = Coupon::query()->create(couponAttributes());
    Coupon::query()->create(couponAttributes(['code' => 'TAKENCODE']));

    $this->actingAs($admin)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->putJson("/admin/api/marketing/coupons/{$coupon->public_id}", couponPayload(['code' => 'TAKENCODE']))
        ->assertStatus(422)
        ->assertJsonValidationErrors('code');
});

test('updating or toggling an unknown public id fails', function (): void {
    $admin = adminCouponsActor(UserRole::Admin);

    $this->actingAs($admin)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->putJson('/admin/api/marketing/coupons/01J00000000000000000000000', couponPayload())
        ->assertNotFound();

    $this->actingAs($admin)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->postJson('/admin/api/marketing/coupons/01J00000000000000000000000/status', ['is_active' => true])
        ->assertNotFound();
});

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function couponAttributes(array $overrides = []): array
{
    return array_merge([
        'public_id' => (string) Str::ulid(),
        'code' => 'SAVE20NOW',
        'discount_type' => 'percent',
        'value' => 20,
        'minimum_order_halalah' => 0,
        'is_active' => true,
    ], $overrides);
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function couponPayload(array $overrides = []): array
{
    return array_merge([
        'code' => 'FRESHCODE',
        'discount_type' => 'percent',
        'value' => 15,
        'minimum_order_halalah' => 0,
    ], $overrides);
}

function adminCouponsActor(UserRole $role, string $locale = 'en'): User
{
    $actor = User::factory()->create([
        'role' => $role,
        'preferred_locale' => $locale,
        'password' => 'SecurePassword!12',
    ]);
    $actor->forceFill([
        'two_factor_secret' => Fortify::currentEncrypter()->encrypt('ADMINCOUPONTOTPSECRET'),
        'two_factor_confirmed_at' => now(),
    ])->save();

    return $actor;
}
