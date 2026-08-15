<?php

use App\Http\Middleware\EnsureActiveUser;
use App\Http\Middleware\EnsureMyAccountEnabled;
use App\Models\User;
use Illuminate\Support\Facades\Route;

test('account and legacy history rollout controls stay independent', function (
    bool $accountEnabled,
    bool $legacyEnabled,
    int $expectedStatus,
) {
    Route::middleware(['web', EnsureMyAccountEnabled::class])
        ->get('/_test/account-rollout', fn () => response()->noContent());

    config()->set('store.features.my_account_enabled', $accountEnabled);
    config()->set('store.features.legacy_history_enabled', $legacyEnabled);

    $this->get('/_test/account-rollout')->assertStatus($expectedStatus);
})->with([
    'account disabled while archive enabled' => [false, true, 404],
    'account enabled while archive disabled' => [true, false, 204],
]);

test('account deletion is unavailable until retention rules are approved', function () {
    expect(Route::has('profile.destroy'))->toBeFalse();
});

test('logout clears encrypted inertia history after invalidating the session', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->withHeader('X-Inertia', 'true')
        ->post(route('logout'))
        ->assertRedirect('/')
        ->assertSessionHas('inertia.clear_history', true);

    $this->assertGuest();
});

test('an inactive authenticated session cannot enter an account route', function () {
    Route::middleware(['web', 'auth', EnsureActiveUser::class])
        ->get('/_test/account-active-user', fn () => response()->noContent());

    $user = User::factory()->create(['is_active' => false]);

    $this->actingAs($user)
        ->get('/_test/account-active-user')
        ->assertForbidden();
});
