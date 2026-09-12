<?php

use App\Enums\ChallengeState;
use App\Enums\HoldTone;
use App\Enums\OrderStatus;
use App\Enums\Supplier;
use App\Enums\SupplierAction;
use App\Enums\TrackingPresentation;
use App\Suppliers\Translation\SupplierStateTranslator;

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

test('deactivated completely hides the action box producing null tone even when stopped', function (string $status): void {
    $translator = new SupplierStateTranslator;

    $tone = $translator->resolveHoldTone($status, '', 'deactivated');

    expect($tone)->toBeNull();
})->with([
    'entered' => ['entered'],
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
    'tempbanCooldown -> Processing' => ['entered', '', 'tempbanCooldown', null, null, TrackingPresentation::Processing],
    'listingTempban -> Processing' => ['entered', '', 'listingTempban', null, null, TrackingPresentation::Processing],
    'dailyReceiverLimit -> Processing' => ['entered', '', 'dailyReceiverLimit', null, null, TrackingPresentation::Processing],
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

test('trap 1: tempbanCooldown or dailyReceiverLimit with stopped status resolves to Processing and not Stopped', function (
    string $status,
    string $econ,
): void {
    $translator = new SupplierStateTranslator;

    $presentation = $translator->resolvePresentation($status, '', $econ, 10000, 50000);

    expect($presentation)->toBe(TrackingPresentation::Processing);
})->with([
    'stopped + tempbanCooldown' => ['stopped', 'tempbanCooldown'],
    'interrupted + tempbanCooldown' => ['interrupted', 'tempbanCooldown'],
    'stopped + dailyReceiverLimit' => ['stopped', 'dailyReceiverLimit'],
    'interrupted + listingTempban' => ['interrupted', 'listingTempban'],
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
    'tempban' => ['tempban', ChallengeState::Failed],
    'dailyReceiverLimit' => ['dailyReceiverLimit', ChallengeState::Failed],
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
