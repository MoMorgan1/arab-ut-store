<?php

use App\Models\User;
use Laravel\Fortify\Features;

beforeEach(function () {
    $this->skipUnlessFortifyHas(Features::registration());
});

test('registration screen can be rendered', function () {
    $response = $this->get(route('register'));

    $response->assertOk();
});

test('new users can register', function () {
    $response = $this->post(route('register.store'), [
        'first_name' => 'Test',
        'last_name' => 'User',
        'email' => 'test@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    $this->assertAuthenticated();
    $response->assertRedirect(route('dashboard', absolute: false));
    $this->assertDatabaseHas('users', [
        'first_name' => 'Test',
        'last_name' => 'User',
        'email' => 'test@example.com',
    ]);
});

test('users without local passwords can be persisted for imported identities', function () {
    $user = User::create([
        'first_name' => 'Imported',
        'last_name' => 'Customer',
        'email' => 'imported@example.com',
        'password' => null,
    ]);

    expect($user->password)->toBeNull()
        ->and($user->name)->toBe('Imported Customer')
        ->and($user->toArray())->toMatchArray([
            'first_name' => 'Imported',
            'last_name' => 'Customer',
            'name' => 'Imported Customer',
        ]);
});
