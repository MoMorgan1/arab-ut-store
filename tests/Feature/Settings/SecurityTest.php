<?php

use App\Models\User;
use Illuminate\Support\Facades\Hash;

test('legacy security settings redirect to the canonical account security page', function (string $locale, string $url) {
    $user = User::factory()->create(['preferred_locale' => $locale]);

    $this->actingAs($user)
        ->get(route('security.edit'))
        ->assertRedirect($url);
})->with([
    'Arabic' => ['ar', '/my-account/security'],
    'English' => ['en', '/en/my-account/security'],
]);

test('password can be updated', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->from(route('security.edit'))
        ->put(route('user-password.update'), [
            'current_password' => 'password',
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ]);

    $response
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('security.edit'));

    expect(Hash::check('new-password', $user->refresh()->password))->toBeTrue();
});

test('correct password must be provided to update password', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->from(route('security.edit'))
        ->put(route('user-password.update'), [
            'current_password' => 'wrong-password',
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ]);

    $response
        ->assertSessionHasErrors('current_password')
        ->assertRedirect(route('security.edit'));
});
