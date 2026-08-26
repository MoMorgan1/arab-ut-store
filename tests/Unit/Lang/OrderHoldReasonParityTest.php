<?php

use App\Enums\OrderHoldReason;

/**
 * A hold reason is written once by staff and read twice: as a short label in the
 * admin picker, and as the full sentence the customer reads on the order page.
 * A key that exists in one place and not the other silently pauses an order with
 * an empty explanation, which is the exact failure this feature exists to fix.
 */
$read = function (string $locale, string $file, string $key): array {
    $translations = require dirname(__DIR__, 3)."/lang/{$locale}/{$file}.php";

    expect($translations)->toHaveKey($key);

    return $translations[$key];
};

test('every hold reason has a customer message and an admin label in both locales', function () use ($read) {
    $expected = OrderHoldReason::values();
    sort($expected);

    foreach ([['orders', 'hold_reasons'], ['admin', 'holdReasons']] as [$file, $key]) {
        foreach (['ar', 'en'] as $locale) {
            $labels = $read($locale, $file, $key);
            $covered = array_keys($labels);
            sort($covered);

            expect($covered)->toBe($expected, "lang/{$locale}/{$file}.php does not cover every hold reason");

            foreach ($labels as $reason => $label) {
                expect($label)->toBeString()->not->toBe('', "lang/{$locale}/{$file}.php has an empty entry for {$reason}");
            }
        }
    }
});

test('the customer message says more than the admin picker label', function () use ($read) {
    // The picker is a name; the message has to carry the cause and the fix,
    // because the customer never sees the picker.
    foreach (['ar', 'en'] as $locale) {
        $messages = $read($locale, 'orders', 'hold_reasons');
        $labels = $read($locale, 'admin', 'holdReasons');

        foreach ($messages as $reason => $message) {
            expect(mb_strlen($message))->toBeGreaterThan(
                mb_strlen($labels[$reason]),
                "lang/{$locale}: the message for {$reason} is no longer than its picker label",
            );
        }
    }
});

test('a banned account message clears the store of blame', function () use ($read) {
    // Customers reach for the store first when EA suspends them. Saying whose
    // decision it was is the whole point of curating this one.
    expect($read('ar', 'orders', 'hold_reasons')['account_banned'])->toContain('EA')
        ->and($read('en', 'orders', 'hold_reasons')['account_banned'])->toContain('EA');
});
