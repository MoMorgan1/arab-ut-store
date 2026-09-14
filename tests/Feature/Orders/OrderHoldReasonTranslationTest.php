<?php

use App\Enums\OrderHoldReason;

// A hold reason with no translation renders its own key to the customer -
// "orders.hold_reasons.captcha" on the order page - because trans() falls back
// to the key. That is invisible in review and obvious to a customer, so the
// coverage is asserted mechanically rather than remembered.
test('every hold reason has customer-facing text in both locales', function (string $locale): void {
    $missing = [];

    foreach (OrderHoldReason::cases() as $reason) {
        $message = $reason->message($locale);

        if ($message === 'orders.hold_reasons.'.$reason->value || trim($message) === '') {
            $missing[] = $reason->value;
        }
    }

    expect($missing)->toBe([]);
})->with(['ar', 'en']);
