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

/**
 * Owner instruction, 2026-09-13: "حط على الاقل زر اعادة تشغيل".
 *
 * Seven hold reasons recover without the customer doing anything, so no message
 * goes out about them - but the screen still offers a button, because "wait for
 * us" and "try it now" are different answers and only the customer knows which
 * one they want.
 *
 * The invariant is asserted over the translator's own maps rather than a list
 * transcribed here: a code added to a hold later inherits the requirement
 * instead of quietly escaping it. The button differs by phase - Resume while
 * coins are moving, Retry on a challenge - so the assertion is that there is
 * one, not which one.
 *
 * The coins probes report `entered`, not `stopped`, and that matters: a stopped
 * status grants Resume on its own through isStopped(), so probing with it made
 * the whole coins half of this test vacuous. It passed with the list entry it
 * exists to protect deleted. `entered` is the case that actually depends on the
 * list, and is what the fixture-driven translator test uses.
 */
test('every hold that recovers on its own still offers the customer a button', function (): void {
    $translator = new SupplierStateTranslator;

    $automatic = [
        OrderHoldReason::EaServers,
        OrderHoldReason::Connection,
        OrderHoldReason::Maintenance,
        OrderHoldReason::NoPlayer,
        OrderHoldReason::StoreStock,
        OrderHoldReason::Paused,
        OrderHoldReason::BelowMinimum,
    ];

    // Two of the three maps are private, and they stay private: a constant is
    // not widened for a test's convenience. Reflection says plainly that this
    // reads internals on purpose.
    $maps = new ReflectionClass(SupplierStateTranslator::class);
    /** @var array<string, OrderHoldReason> $accountChecks */
    $accountChecks = $maps->getConstant('ACCOUNT_CHECK_HOLDS');
    /** @var array<string, OrderHoldReason> $economyStates */
    $economyStates = $maps->getConstant('ECONOMY_STATE_HOLDS');
    /** @var array<string, OrderHoldReason> $sbcStatuses */
    $sbcStatuses = $maps->getConstant('SBC_STATUS_HOLDS');

    $buttonless = [];

    foreach ($accountChecks as $code => $reason) {
        if (! in_array($reason, $automatic, true)) {
            continue;
        }

        $state = $translator->translate(
            new RawSupplierObservation(
                supplier: Supplier::Fft,
                supplierOrderId: 'fft-automatic-hold',
                payload: ['status' => 'entered', 'accountCheck' => $code, 'economyState' => ''],
                fetchedAt: CarbonImmutable::now(),
            ),
            OrderStatus::InProgress,
            DeliveryPhase::Coins,
        );

        if ($state->allowedActions === []) {
            $buttonless[] = "accountCheck:{$code}";
        }
    }

    foreach ($economyStates as $code => $reason) {
        if (! in_array($reason, $automatic, true)) {
            continue;
        }

        $state = $translator->translate(
            new RawSupplierObservation(
                supplier: Supplier::Fft,
                supplierOrderId: 'fft-automatic-hold',
                payload: ['status' => 'entered', 'accountCheck' => '', 'economyState' => $code],
                fetchedAt: CarbonImmutable::now(),
            ),
            OrderStatus::InProgress,
            DeliveryPhase::Coins,
        );

        if ($state->allowedActions === []) {
            $buttonless[] = "economyState:{$code}";
        }
    }

    foreach ($sbcStatuses as $code => $reason) {
        if (! in_array($reason, $automatic, true)) {
            continue;
        }

        $state = $translator->translateChallenge(
            Supplier::Fft,
            ['chal-1'],
            ['chal-1' => ['sbcStatus' => $code]],
            OrderStatus::InProgress,
        );

        if ($state->allowedActions === []) {
            $buttonless[] = "sbcStatus:{$code}";
        }
    }

    expect($buttonless)->toBe([]);
});
