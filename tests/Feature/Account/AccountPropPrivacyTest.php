<?php

use App\Models\User;

test('account destinations never serialize credential or internal data props', function (string $path): void {
    $user = User::factory()->create([
        'phone' => '+201001234567',
        'phone_verified_at' => now(),
    ]);

    $props = $this->actingAs($user)
        ->get($path)
        ->assertOk()
        ->inertiaPage()['props'];

    expect(forbiddenAccountPropPaths($props))->toBe([]);
})->with([
    'overview' => '/my-account',
    'orders' => '/my-account/orders',
    'wallet' => '/my-account/wallet',
    'profile' => '/my-account/profile',
    'security' => '/my-account/security',
    'support' => '/my-account/support',
    'English support' => '/en/my-account/support',
]);

/**
 * @param  array<string, mixed>  $props
 * @return list<string>
 */
function forbiddenAccountPropPaths(array $props): array
{
    $forbidden = ['raw_payload', 'password', 'otp', 'credentials', 'secret', 'internal_notes'];
    $safeMetadata = [
        'passwordMode',
        'passwordRules',
        'passwordConfirmationRequired',
        'changePasswordUrl',
        'setupPasswordUrl',
    ];
    $found = [];

    $walk = function (mixed $value, string $path = 'props') use (&$walk, &$found, $forbidden, $safeMetadata): void {
        if (! is_array($value)) {
            return;
        }

        foreach ($value as $key => $child) {
            $key = (string) $key;
            $childPath = $path.'.'.$key;

            if ($key === 'accountUi' || $key === 'ui') {
                continue;
            }

            if (! in_array($key, $safeMetadata, true)) {
                foreach ($forbidden as $needle) {
                    if (str_contains(mb_strtolower($key), $needle)) {
                        $found[] = $childPath;
                    }
                }
            }

            $walk($child, $childPath);
        }
    };

    $walk($props);

    return $found;
}
