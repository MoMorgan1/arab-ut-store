<?php

use App\Models\User;

test('legacy profile settings redirect to the canonical account profile', function (string $locale, string $url) {
    $user = User::factory()->create(['preferred_locale' => $locale]);

    $this->actingAs($user)
        ->get(route('profile.edit'))
        ->assertRedirect($url);
})->with([
    'Arabic' => ['ar', '/my-account/profile'],
    'English' => ['en', '/en/my-account/profile'],
]);
