<?php

use App\Models\User;

test('guests are redirected to the login page', function () {
    $response = $this->get(route('dashboard'));
    $response->assertRedirect(route('login'));
});

test('the legacy dashboard redirects authenticated users to My Account', function () {
    $user = User::factory()->create(['preferred_locale' => 'ar']);

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertRedirect('/my-account');
});
