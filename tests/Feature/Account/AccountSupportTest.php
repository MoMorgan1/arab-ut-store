<?php

use App\Models\User;

test('the support URL redirects to the account overview', function (
    string $path,
    string $target,
): void {
    $owner = User::factory()->create();

    $this->actingAs($owner)
        ->get($path)
        ->assertRedirect($target);
})->with([
    'Arabic support' => ['/my-account/support', '/my-account'],
    'English support' => ['/en/my-account/support', '/en/my-account'],
]);
