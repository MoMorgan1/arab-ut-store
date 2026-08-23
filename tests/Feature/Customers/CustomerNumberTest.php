<?php

use App\Customers\CustomerNumber;
use App\Enums\UserRole;
use App\Models\User;

test('customer numbers are short, prefixed, and use the unambiguous alphabet', function (): void {
    foreach (range(1, 200) as $ignored) {
        expect(CustomerNumber::candidate())->toMatch(CustomerNumber::PATTERN);
    }
});

test('generate skips numbers that are already taken', function (): void {
    $existing = User::factory()->create(['role' => UserRole::Customer]);

    $numbers = collect(range(1, 50))->map(fn (): string => CustomerNumber::generate());

    expect($numbers->unique()->count())->toBe(50)
        ->and($numbers)->not->toContain($existing->customer_number)
        ->and($numbers->every(fn (string $number): bool => preg_match(CustomerNumber::PATTERN, $number) === 1))->toBeTrue();
});

test('a new customer is given a number automatically', function (): void {
    $customer = User::factory()->create(['role' => UserRole::Customer]);

    expect($customer->customer_number)->toMatch(CustomerNumber::PATTERN);
});

test('staff, admin and service accounts are not given customer numbers', function (): void {
    foreach ([UserRole::Admin, UserRole::Staff, UserRole::ServiceAccount] as $role) {
        expect(User::factory()->create(['role' => $role])->customer_number)->toBeNull();
    }
});

test('customer numbers are unique across many creations', function (): void {
    $numbers = collect(range(1, 40))
        ->map(fn (): ?string => User::factory()->create(['role' => UserRole::Customer])->customer_number);

    expect($numbers->unique()->count())->toBe(40);
});
