<?php

function adminTranslationLeafKeys(array $translations, string $prefix = ''): array
{
    $keys = [];

    foreach ($translations as $key => $value) {
        $path = $prefix === '' ? (string) $key : "{$prefix}.{$key}";

        if (is_array($value)) {
            $keys = [...$keys, ...adminTranslationLeafKeys($value, $path)];

            continue;
        }

        $keys[] = $path;
    }

    sort($keys);

    return $keys;
}

test('Arabic and English admin copy have exact leaf-key parity', function (): void {
    $arabic = require lang_path('ar/admin.php');
    $english = require lang_path('en/admin.php');

    expect(adminTranslationLeafKeys($arabic))
        ->toBe(adminTranslationLeafKeys($english));
});
