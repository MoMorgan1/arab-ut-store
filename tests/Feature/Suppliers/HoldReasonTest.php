<?php

use App\Enums\DeliveryPhase;
use App\Enums\OrderHoldReason;
use App\Enums\OrderStatus;
use App\Enums\Supplier;
use App\Suppliers\RawSupplierObservation;
use App\Suppliers\Translation\SupplierStateTranslator;
use Carbon\CarbonImmutable;

/**
 * A hold reason's words are read from the language files, so these live in
 * Feature rather than Unit: a Unit test has no container and `trans()` fails
 * there with "Target class [translator] does not exist".
 */
test('a problem that is not a wrong password does not get the wrong-password message', function (
    string $accountCheck,
    OrderHoldReason $expected,
): void {
    // One reason was answering for five different problems and its sentence was
    // true for three of them: a customer whose two-factor was switched off was
    // told to check a password that had never been wrong, and a customer whose
    // Web App had never been opened got the same. Each now carries its own, and
    // each still waits on the customer rather than describing itself as moving.
    $translator = new SupplierStateTranslator;
    $observation = new RawSupplierObservation(
        supplier: Supplier::Fft,
        supplierOrderId: 'fft-hold-split',
        payload: [
            'status' => 'stopped',
            'accountCheck' => $accountCheck,
        ],
        fetchedAt: CarbonImmutable::now(),
    );

    $translated = $translator->translate($observation, OrderStatus::InProgress, DeliveryPhase::Coins);

    expect($translated->holdReason)->toBe($expected)
        ->and($translated->status)->toBe(OrderStatus::WaitingForCustomer)
        ->and($expected->message('ar'))->not->toContain('كلمة المرور')
        ->and(mb_strtolower($expected->message('en')))->not->toContain('password');
})->with([
    ['2FADisabled', OrderHoldReason::TwoFactorOff],
    ['no2fa', OrderHoldReason::TwoFactorOff],
    ['No2FA', OrderHoldReason::TwoFactorOff],
    ['wrongPersona', OrderHoldReason::Platform],
]);

test('each reason split out of the credentials bucket has words of its own', function (
    OrderHoldReason $reason,
): void {
    foreach (['ar', 'en'] as $locale) {
        expect($reason->message($locale))->toBeString()->not->toBe('')
            ->not->toBe('orders.hold_reasons.'.$reason->value);
    }
})->with([
    [OrderHoldReason::TwoFactorOff],
    [OrderHoldReason::EmailConfirm],
    [OrderHoldReason::WebAppLocked],
]);
