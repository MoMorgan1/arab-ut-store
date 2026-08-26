<?php

use App\Enums\OrderItemStatus;
use App\Enums\OrderStatus;

/**
 * The order status is the only signal a customer gets about a stalled order.
 *
 * It used to render from two translation groups on two different pages, and the
 * two disagreed - «بانتظار ردك» on the order page against «بانتظارك» in the
 * account, «تم الاسترجاع» against «مسترد» - with nothing to catch it. The store
 * order page is now a redirect to the account, so there is one copy of these
 * labels and no second copy to drift from. What is still worth guarding is that
 * the one copy covers every status a customer can actually reach.
 */
$read = function (string $locale): array {
    $translations = require dirname(__DIR__, 3)."/lang/{$locale}/account.php";

    expect($translations)->toHaveKey('statuses');

    return $translations['statuses'];
};

test('every order status a customer can reach is labelled in both locales', function () use ($read) {
    $expected = array_map(static fn (OrderStatus $status): string => $status->value, OrderStatus::cases());
    sort($expected);

    foreach (['ar', 'en'] as $locale) {
        $labels = $read($locale);
        $covered = array_keys($labels);
        sort($covered);

        expect(array_diff($expected, $covered))->toBe([], "lang/{$locale}/account.php is missing an order status label");

        foreach ($labels as $key => $label) {
            expect($label)->toBeString()->not->toBe('', "lang/{$locale}/account.php has an empty label for {$key}");
        }
    }
});

test('no status is labelled that no order can reach', function () use ($read) {
    // A label with no status behind it ships a state to customers that nothing
    // can produce - which is exactly what the deleted 'failed' case did.
    $reachable = array_map(static fn (OrderStatus $status): string => $status->value, OrderStatus::cases());

    foreach (['ar', 'en'] as $locale) {
        expect(array_diff(array_keys($read($locale)), $reachable))
            ->toBe([], "lang/{$locale}/account.php labels a status no order can reach");
    }
});

test('the blocked status tells the customer an action is needed', function () use ($read) {
    // waiting_for_customer is the one status that demands the customer act. None
    // of the 17 tracker states it covers wants a reply — they want credentials
    // fixed, a game session signed out, a transfer list cleared.
    expect($read('ar')['waiting_for_customer'])->toContain('إجراء')
        ->and($read('en')['waiting_for_customer'])->toContain('action');
});

test('order item statuses stay a superset of order statuses', function () {
    $orderStatuses = array_map(static fn (OrderStatus $status): string => $status->value, OrderStatus::cases());
    $itemStatuses = array_map(static fn (OrderItemStatus $status): string => $status->value, OrderItemStatus::cases());

    expect(array_diff($orderStatuses, $itemStatuses))->toBe([]);
});
