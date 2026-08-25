<?php

use App\Enums\OrderItemStatus;
use App\Enums\OrderStatus;

/**
 * The order status is the only signal a customer gets about a stalled order, and
 * it renders from two different translation groups on two different pages. Those
 * two used to disagree — «بانتظار ردك» on the order page against «بانتظارك» in
 * the account, «تم الاسترجاع» against «مسترد» — with nothing to catch it.
 */
$groups = [
    'store' => 'order_page.statuses',
    'account' => 'statuses',
];

$read = function (string $locale, string $file, string $path): array {
    $translations = require dirname(__DIR__, 3)."/lang/{$locale}/{$file}.php";

    foreach (explode('.', $path) as $segment) {
        expect($translations)->toHaveKey($segment);
        $translations = $translations[$segment];
    }

    return $translations;
};

test('every order status a customer can reach is labelled in both locales', function () use ($groups, $read) {
    $expected = array_map(static fn (OrderStatus $status): string => $status->value, OrderStatus::cases());
    sort($expected);

    foreach ($groups as $file => $path) {
        foreach (['ar', 'en'] as $locale) {
            $labels = $read($locale, $file, $path);
            $covered = array_keys($labels);
            sort($covered);

            expect(array_diff($expected, $covered))->toBe([], "lang/{$locale}/{$file}.php is missing an order status label");

            foreach ($labels as $key => $label) {
                expect($label)->toBeString()->not->toBe('', "lang/{$locale}/{$file}.php has an empty label for {$key}");
            }
        }
    }
});

test('the order page and the account agree on what each status is called', function () use ($groups, $read) {
    foreach (['ar', 'en'] as $locale) {
        $store = $read($locale, 'store', $groups['store']);
        $account = $read($locale, 'account', $groups['account']);

        expect(array_keys($account))->toEqualCanonicalizing(array_keys($store));

        foreach ($store as $key => $label) {
            expect($account[$key])->toBe($label, "lang/{$locale}: the order page calls {$key} \"{$label}\" but the account calls it \"{$account[$key]}\"");
        }
    }
});

test('the blocked status tells the customer an action is needed', function () use ($groups, $read) {
    // waiting_for_customer is the one status that demands the customer act. None
    // of the 17 tracker states it covers wants a reply — they want credentials
    // fixed, a game session signed out, a transfer list cleared.
    expect($read('ar', 'store', $groups['store'])['waiting_for_customer'])->toContain('إجراء')
        ->and($read('en', 'store', $groups['store'])['waiting_for_customer'])->toContain('action');
});

test('order item statuses stay a superset of order statuses', function () {
    $orderStatuses = array_map(static fn (OrderStatus $status): string => $status->value, OrderStatus::cases());
    $itemStatuses = array_map(static fn (OrderItemStatus $status): string => $status->value, OrderItemStatus::cases());

    expect(array_diff($orderStatuses, $itemStatuses))->toBe([]);
});
