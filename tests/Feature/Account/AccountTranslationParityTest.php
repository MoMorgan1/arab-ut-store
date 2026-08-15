<?php

function accountTranslationLeafKeys(array $translations, string $prefix = ''): array
{
    $keys = [];

    foreach ($translations as $key => $value) {
        $path = $prefix === '' ? (string) $key : "{$prefix}.{$key}";

        if (is_array($value)) {
            $keys = [...$keys, ...accountTranslationLeafKeys($value, $path)];

            continue;
        }

        $keys[] = $path;
    }

    sort($keys);

    return $keys;
}

test('Arabic and English account copy have exact leaf-key parity', function (): void {
    $arabic = require lang_path('ar/account.php');
    $english = require lang_path('en/account.php');

    expect(accountTranslationLeafKeys($arabic))
        ->toBe(accountTranslationLeafKeys($english));
});

test('customer account navigation uses the approved native names', function (): void {
    $arabic = require lang_path('ar/account.php');
    $english = require lang_path('en/account.php');

    expect($arabic)
        ->toHaveKey('page_title', 'حسابي')
        ->toHaveKey('navigation.overview', 'نظرة عامة')
        ->toHaveKey('navigation.orders', 'طلباتي')
        ->toHaveKey('navigation.wallet', 'محفظتي')
        ->toHaveKey('navigation.profile', 'بياناتي')
        ->toHaveKey('navigation.security', 'الأمان')
        ->toHaveKey('navigation.support', 'الدعم')
        ->and($english)
        ->toHaveKey('page_title', 'My Account')
        ->toHaveKey('navigation.overview', 'Overview')
        ->toHaveKey('navigation.orders', 'Orders')
        ->toHaveKey('navigation.wallet', 'Wallet')
        ->toHaveKey('navigation.profile', 'Profile')
        ->toHaveKey('navigation.security', 'Security')
        ->toHaveKey('navigation.support', 'Support');
});
