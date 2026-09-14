<?php

use App\Enums\ChallengeState;
use App\Enums\DeliveryPhase;
use App\Enums\HoldTone;
use App\Enums\OrderStatus;
use App\Enums\Supplier;
use App\Enums\SupplierAction;
use App\Enums\TrackingPresentation;
use App\Suppliers\RawSupplierObservation;
use App\Suppliers\Translation\SupplierStateTranslator;
use Carbon\CarbonImmutable;

test('each of the nine system economy states produces HoldTone::Info', function (string $code): void {
    $translator = new SupplierStateTranslator;

    $tone = $translator->resolveHoldTone('entered', '', $code);

    expect($tone)->toBe(HoldTone::Info);
})->with([
    'tempbanCooldown' => ['tempbanCooldown'],
    'listingTempban' => ['listingTempban'],
    'dailyReceiverLimit' => ['dailyReceiverLimit'],
    'calcErrorMaintenance' => ['calcErrorMaintenance'],
    'FailedProxyConnectionError' => ['FailedProxyConnectionError'],
    'FailProxyUnavailable' => ['FailProxyUnavailable'],
    'noPlayer' => ['noPlayer'],
    'noSuitableSender' => ['noSuitableSender'],
    'belowMinTransfer' => ['belowMinTransfer'],
]);

test('customer action account checks produce HoldTone::Action', function (string $code): void {
    $translator = new SupplierStateTranslator;

    $tone = $translator->resolveHoldTone('entered', $code, '');

    expect($tone)->toBe(HoldTone::Action);
})->with([
    'wrongBA' => ['wrongBA'],
    'wrongUserPass' => ['wrongUserPass'],
    'captcha' => ['captcha'],
    'tlFull' => ['tlFull'],
    'console' => ['console'],
    'active session' => ['active session'],
]);

test('customer action economy states produce HoldTone::Action', function (string $code): void {
    $translator = new SupplierStateTranslator;

    $tone = $translator->resolveHoldTone('entered', '', $code);

    expect($tone)->toBe(HoldTone::Action);
})->with([
    'FailedWrongCredentialsTo' => ['FailedWrongCredentialsTo'],
    'FailedWrongBACodeTo' => ['FailedWrongBACodeTo'],
    'FailWebAppCustomerLocked' => ['FailWebAppCustomerLocked'],
    'FailedSessionExpiredCustomerLoggedIn?' => ['FailedSessionExpiredCustomerLoggedIn?'],
]);

test('stopped status alone produces HoldTone::Action', function (): void {
    $translator = new SupplierStateTranslator;

    $tone = $translator->resolveHoldTone('stopped', '', '');

    expect($tone)->toBe(HoldTone::Action);
});

test('cooldown and system states take precedence over stopped status for HoldTone', function (string $systemCode): void {
    $translator = new SupplierStateTranslator;

    // Stopped status alone would be Action, but system cooldown outranks it to produce Info
    $tone = $translator->resolveHoldTone('stopped', '', $systemCode);

    expect($tone)->toBe(HoldTone::Info);
})->with([
    'dailyReceiverLimit' => ['dailyReceiverLimit'],
    'tempbanCooldown' => ['tempbanCooldown'],
    'listingTempban' => ['listingTempban'],
]);

test('deactivated alone yields a null tone (no action box)', function (): void {
    $translator = new SupplierStateTranslator;

    expect($translator->resolveHoldTone('entered', '', 'deactivated'))->toBeNull();
});

test('deactivated with customer action account check yields HoldTone::Action', function (): void {
    $translator = new SupplierStateTranslator;

    expect($translator->resolveHoldTone('entered', 'wrongBA', 'deactivated'))->toBe(HoldTone::Action);
});

test('deactivated with stopped status yields HoldTone::Action', function (string $status): void {
    $translator = new SupplierStateTranslator;

    expect($translator->resolveHoldTone($status, '', 'deactivated'))->toBe(HoldTone::Action);
})->with([
    'stopped' => ['stopped'],
    'interrupted' => ['interrupted'],
]);

test('presentation cascade resolves in exact tracker order', function (
    string $status,
    string $accCheck,
    string $econ,
    ?int $delivered,
    ?int $ordered,
    TrackingPresentation $expected,
): void {
    $translator = new SupplierStateTranslator;

    $presentation = $translator->resolvePresentation($status, $accCheck, $econ, $delivered, $ordered);

    expect($presentation)->toBe($expected);
})->with([
    'tempbanCooldown -> CooldownTempban' => ['entered', '', 'tempbanCooldown', null, null, TrackingPresentation::CooldownTempban],
    'listingTempban -> CooldownListing' => ['entered', '', 'listingTempban', null, null, TrackingPresentation::CooldownListing],
    'dailyReceiverLimit -> CooldownDailyLimit' => ['entered', '', 'dailyReceiverLimit', null, null, TrackingPresentation::CooldownDailyLimit],
    'transfersInProgress -> Transferring' => ['entered', '', 'transfersInProgress', 10000, 50000, TrackingPresentation::Transferring],
    'transferCycleComplete -> TransferringPartDone' => ['entered', '', 'transferCycleComplete', 10000, 50000, TrackingPresentation::TransferringPartDone],
    'customerHasPlayer -> Preparing' => ['entered', '', 'customerHasPlayer', 0, 50000, TrackingPresentation::Preparing],
    'customerListedPlayer -> Preparing' => ['entered', '', 'customerListedPlayer', 0, 50000, TrackingPresentation::Preparing],
    'entered -> LoggingIn' => ['entered', 'entered', '', null, null, TrackingPresentation::LoggingIn],
    'userPassVerified -> LoggingIn' => ['entered', 'userPassVerified', '', null, null, TrackingPresentation::LoggingIn],
    'finished -> Completed' => ['finished', '', '', 50000, 50000, TrackingPresentation::Completed],
    'completed -> Completed' => ['completed', '', '', 50000, 50000, TrackingPresentation::Completed],
    'stopped -> Stopped' => ['stopped', '', '', 10000, 50000, TrackingPresentation::Stopped],
    'interrupted -> Stopped' => ['interrupted', '', '', 10000, 50000, TrackingPresentation::Stopped],
    'customerAction -> NeedsReview' => ['entered', 'wrongBA', '', 0, 50000, TrackingPresentation::NeedsReview],
    'default -> Processing' => ['waiting', '', '', 0, 50000, TrackingPresentation::Processing],
]);

test('trap 1: cooldown states with stopped status resolve to their Cooldown presentation and not Stopped', function (
    string $status,
    string $econ,
    TrackingPresentation $expected,
): void {
    $translator = new SupplierStateTranslator;

    $presentation = $translator->resolvePresentation($status, '', $econ, 10000, 50000);

    expect($presentation)->toBe($expected);
})->with([
    'stopped + tempbanCooldown' => ['stopped', 'tempbanCooldown', TrackingPresentation::CooldownTempban],
    'interrupted + tempbanCooldown' => ['interrupted', 'tempbanCooldown', TrackingPresentation::CooldownTempban],
    'stopped + dailyReceiverLimit' => ['stopped', 'dailyReceiverLimit', TrackingPresentation::CooldownDailyLimit],
    'interrupted + listingTempban' => ['interrupted', 'listingTempban', TrackingPresentation::CooldownListing],
]);

test('trap 2: zero-remaining orders respect isFinished and isStopped without being overridden by Finishing', function (
    string $status,
    TrackingPresentation $expected,
): void {
    $translator = new SupplierStateTranslator;

    // Remaining is 0 (100k delivered of 100k ordered)
    $presentation = $translator->resolvePresentation($status, '', '', 100_000, 100_000);

    expect($presentation)->toBe($expected);
})->with([
    'finished status with 0 remaining is Completed' => ['finished', TrackingPresentation::Completed],
    'completed status with 0 remaining is Completed' => ['completed', TrackingPresentation::Completed],
    'stopped status with 0 remaining is Stopped' => ['stopped', TrackingPresentation::Stopped],
    'interrupted status with 0 remaining is Stopped' => ['interrupted', TrackingPresentation::Stopped],
]);

test('trailing override: open unstopped order with zero or negative remaining resolves to Finishing', function (
    int $delivered,
    int $ordered,
): void {
    $translator = new SupplierStateTranslator;

    $presentation = $translator->resolvePresentation('entered', '', 'transfersInProgress', $delivered, $ordered);

    expect($presentation)->toBe(TrackingPresentation::Finishing);
})->with([
    'exact remaining 0' => [100_000, 100_000],
    'over-delivered remaining negative' => [120_000, 100_000],
]);

test('tooExpensive resolves to NeedsReview with holdReason null, holdTone Action, and RetryChallenge action', function (): void {
    $translator = new SupplierStateTranslator;

    $translated = $translator->translateChallenge(
        Supplier::Fft,
        ['chal-1'],
        ['chal-1' => ['sbcStatus' => 'tooExpensive']],
        OrderStatus::InProgress,
    );

    // Sharpest gap verified: tooExpensive must not look like healthy work!
    expect($translated->status)->toBe(OrderStatus::InProgress)
        ->and($translated->holdReason)->toBeNull()
        ->and($translated->allowedActions)->toBe([SupplierAction::RetryChallenge])
        ->and($translated->presentation)->toBe(TrackingPresentation::NeedsReview)
        ->and($translated->holdTone)->toBe(HoldTone::Action);
});

test('challengeState maps raw sbcStatus strings to curated ChallengeState cases', function (
    string $sbcStatus,
    ChallengeState $expectedState,
): void {
    $state = SupplierStateTranslator::challengeState($sbcStatus);

    expect($state)->toBe($expectedState);
})->with([
    'entered' => ['entered', ChallengeState::Queued],
    'waitingForOtherSolve' => ['waitingForOtherSolve', ChallengeState::WaitingPreviousSolve],
    'started' => ['started', ChallengeState::Started],
    'fetchSBCInfo' => ['fetchSBCInfo', ChallengeState::FetchingChallenge],
    'fetchChallengeInfo' => ['fetchChallengeInfo', ChallengeState::FetchingSquads],
    'solvingChallenge' => ['solvingChallenge', ChallengeState::Solving],
    'finished' => ['finished', ChallengeState::Done],
    'alreadyCompleted' => ['alreadyCompleted', ChallengeState::Done],
    'sessionExpired' => ['sessionExpired', ChallengeState::SessionExpired],
    'needEmailConfirm' => ['needEmailConfirm', ChallengeState::SignInFailed],
    'LoginFailed495' => ['LoginFailed495', ChallengeState::SignInFailed],
    'LoginFailed401' => ['LoginFailed401', ChallengeState::SignInFailed],
    'LoginFailedDeviceBan' => ['LoginFailedDeviceBan', ChallengeState::SignInFailed],
    'WrongUserPass' => ['WrongUserPass', ChallengeState::SignInFailed],
    'WrongBA' => ['WrongBA', ChallengeState::SignInFailed],
    'loginFailed' => ['loginFailed', ChallengeState::SignInFailed],
    'tooExpensive' => ['tooExpensive', ChallengeState::Failed],
    'noSolutionFound' => ['noSolutionFound', ChallengeState::Failed],
    'clickFailed' => ['clickFailed', ChallengeState::Failed],
    'submitFailed' => ['submitFailed', ChallengeState::Failed],
    'playerBuyFailed' => ['playerBuyFailed', ChallengeState::Failed],
    'OutOfCoins' => ['OutOfCoins', ChallengeState::Failed],
    'tempban' => ['tempban', ChallengeState::Cooldown],
    'dailyReceiverLimit' => ['dailyReceiverLimit', ChallengeState::Cooldown],
    'unknown string' => ['unheardOfStatus', ChallengeState::Unknown],
]);

test('transfersInProgress with an error simplified status does not read as transferring', function (): void {
    // The tracker's transferring branch has three conditions and the third is easy to drop:
    // econ === 'transfersInProgress' && !actualActionMsg && simplified !== 'error' (ui.js:443).
    // Without it the customer reads "جاري النقل" for an order the tracker treats as processing.
    $translator = new SupplierStateTranslator;

    // Skipping the branch falls through to the next one the cascade offers, which is the
    // point: the order is preserved rather than short-circuited. With correctBA that is
    // LoggingIn; with an account check outside that set it lands on the default.
    expect($translator->resolvePresentation('started', 'correctBA', 'transfersInProgress', 10, 100, 'error'))
        ->toBe(TrackingPresentation::LoggingIn)
        ->and($translator->resolvePresentation('started', 'noUnassignedItemsPresent', 'transfersInProgress', 10, 100, 'error'))
        ->toBe(TrackingPresentation::Processing)
        ->and($translator->resolvePresentation('started', 'correctBA', 'transfersInProgress', 10, 100, ''))
        ->toBe(TrackingPresentation::Transferring);
});

test('a system-info state suppresses the logging-in headline, so it cannot contradict its own box', function (string $economyState): void {
    // The tracker guards its branches on "is there a message", and a system-info code has
    // one - getActionMessage() returns it with an __info_box__ prefix rather than returning
    // nothing (ui.js:310-313). Guarding only on customer-action codes produced
    // "جاري تسجيل الدخول" above an amber box explaining we cannot find a player.
    $translator = new SupplierStateTranslator;

    expect($translator->resolvePresentation('started', 'entered', $economyState))
        ->toBe(TrackingPresentation::Processing);
})->with([
    'calcErrorMaintenance', 'FailedProxyConnectionError', 'FailProxyUnavailable',
    'noPlayer', 'noSuitableSender', 'belowMinTransfer',
]);

test('an account check with no economy state still reads as logging in', function (): void {
    // The other half of the pair: the guard must not swallow the branch entirely.
    $translator = new SupplierStateTranslator;

    expect($translator->resolvePresentation('started', 'entered', ''))
        ->toBe(TrackingPresentation::LoggingIn);
});

test('finished status with tempbanCooldown on a non-coins phase reports cooldown presentation with Completed status', function (): void {
    $translator = new SupplierStateTranslator;
    $observation = new RawSupplierObservation(
        supplier: Supplier::Fft,
        supplierOrderId: 'fft-tracking-test',
        payload: [
            'status' => 'finished',
            'economyState' => 'tempbanCooldown',
        ],
        fetchedAt: CarbonImmutable::now(),
    );

    $translated = $translator->translate($observation, OrderStatus::InProgress, DeliveryPhase::Challenge);

    expect($translated->status)->toBe(OrderStatus::Completed)
        ->and($translated->presentation)->toBe(TrackingPresentation::CooldownTempban);
});

test('terminal cancelled and refunded orders resolve to their respective TrackingPresentation cases', function (
    OrderStatus $terminalStatus,
    TrackingPresentation $expected,
): void {
    $translator = new SupplierStateTranslator;
    $observation = new RawSupplierObservation(
        supplier: Supplier::Fft,
        supplierOrderId: 'fft-tracking-test',
        payload: [
            'status' => 'transfersinprogress',
        ],
        fetchedAt: CarbonImmutable::now(),
    );

    $translated = $translator->translate($observation, $terminalStatus, DeliveryPhase::Coins);

    expect($translated->presentation)->toBe($expected)
        ->and($translated->allowedActions)->toBe([]);
})->with([
    'Cancelled' => [OrderStatus::Cancelled, TrackingPresentation::Cancelled],
    'Refunded' => [OrderStatus::Refunded, TrackingPresentation::Refunded],
]);

test('challenge informational statuses resolve to Processing presentation and Info hold tone', function (
    string $sbcStatus,
): void {
    $translator = new SupplierStateTranslator;

    $translated = $translator->translateChallenge(
        Supplier::Fft,
        ['chal-info-1'],
        ['chal-info-1' => ['sbcStatus' => $sbcStatus]],
        OrderStatus::InProgress,
    );

    expect($translated->presentation)->toBe(TrackingPresentation::Processing)
        ->and($translated->holdTone)->toBe(HoldTone::Info)
        ->and($translated->status)->toBe(OrderStatus::InProgress);
})->with([
    'tempban' => ['tempban'],
    'TempbanCooldown' => ['TempbanCooldown'],
    'dailyReceiverLimit' => ['dailyReceiverLimit'],
    'FailProxyConn' => ['FailProxyConn'],
    'FailedProxyConnectionError' => ['FailedProxyConnectionError'],
    'FailProxy' => ['FailProxy'],
]);
