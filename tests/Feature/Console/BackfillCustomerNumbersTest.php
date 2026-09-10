<?php

use App\Customers\CustomerNumber;
use App\Enums\UserRole;
use App\Models\User;

test('a customer created without an explicit role still gets a short number', function (): void {
    // Registration and Google sign-in create the row without a role, leaving
    // the column default to say "customer"; the number must not depend on it.
    $user = User::query()->create([
        'first_name' => 'Muteb',
        'last_name' => 'Alzhrani',
        'email' => 'muteb@example.test',
        'password' => 'secret-hash',
    ]);

    expect($user->customer_number)->toMatch(CustomerNumber::PATTERN);
});

test('staff never receive a customer number', function (): void {
    $staff = User::factory()->create(['role' => UserRole::Staff, 'customer_number' => null]);

    expect($staff->customer_number)->toBeNull();
});

test('the backfill numbers every customer that was left with a ULID only', function (): void {
    $missing = User::factory()->create();
    $missing->forceFill(['customer_number' => null])->save();
    $numbered = User::factory()->create();
    $staff = User::factory()->create(['role' => UserRole::Staff, 'customer_number' => null]);

    $this->artisan('customers:backfill-numbers')
        ->expectsOutputToContain('Assigned 1 customer number(s).')
        ->assertSuccessful();

    expect($missing->fresh()->customer_number)->toMatch(CustomerNumber::PATTERN)
        ->and($numbered->fresh()->customer_number)->toBe($numbered->customer_number)
        ->and($staff->fresh()->customer_number)->toBeNull();
});
