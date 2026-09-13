<?php

use App\Enums\DeliveryPhase;
use App\Enums\OrderHoldReason;
use App\Enums\OrderStatus;
use App\Enums\Supplier;
use App\Enums\SupplierAction;
use App\Suppliers\RawSupplierObservation;
use App\Suppliers\Translation\SupplierStateTranslator;
use Carbon\CarbonImmutable;

/**
 * @param  array<string, mixed>  $payload
 */
function fftTranslatorObservation(array $payload, Supplier $supplier = Supplier::Fft): RawSupplierObservation
{
    return new RawSupplierObservation(
        $supplier,
        'FFT-ORDER-1',
        $payload,
        CarbonImmutable::parse('2026-09-12 12:00:00'),
    );
}

/**
 * @return array{status: string, accountCheck: string, economyState: string}
 */
function fftTranslatorPayload(string $field, string $code): array
{
    return match ($field) {
        'accountCheck' => ['status' => 'entered', 'accountCheck' => $code, 'economyState' => ''],
        'economyState' => ['status' => 'entered', 'accountCheck' => '', 'economyState' => $code],
        default => ['status' => $code, 'accountCheck' => '', 'economyState' => ''],
    };
}

/**
 * @return array<string, array{0: string, 1: string, 2: array{status: OrderStatus, hold: OrderHoldReason|null, actions: list<SupplierAction>}}>
 */
function fftTranslatorFixture(): array
{
    $datasets = [];

    /** @var array<string, array<string, array{status: OrderStatus, hold: OrderHoldReason|null, actions: list<SupplierAction>}>> $axes */
    $axes = require dirname(__DIR__, 2).'/Fixtures/Suppliers/fft-states.php';

    foreach (['accountCheck', 'economyState', 'status'] as $field) {
        foreach ($axes[$field] as $code => $expected) {
            $datasets[$field.':'.$code] = [$field, $code, $expected];
        }
    }

    return $datasets;
}

/**
 * @return array<string, array{0: array<string, string>, 1: OrderStatus, 2: array{status: OrderStatus, hold: OrderHoldReason|null, actions: list<SupplierAction>, observed: string}}>
 */
function fftTranslatorCombinations(): array
{
    /** @var array{combinations: array<string, array{payload: array<string, string>, current: OrderStatus, expected: array{status: OrderStatus, hold: OrderHoldReason|null, actions: list<SupplierAction>, observed: string}}>} $fixture */
    $fixture = require dirname(__DIR__, 2).'/Fixtures/Suppliers/fft-states.php';

    $datasets = [];

    foreach ($fixture['combinations'] as $name => $case) {
        $datasets[$name] = [$case['payload'], $case['current'], $case['expected']];
    }

    return $datasets;
}

test('every known supplier code becomes its canonical state', function (string $field, string $code, array $expected): void {
    $translated = (new SupplierStateTranslator)->translate(
        fftTranslatorObservation(fftTranslatorPayload($field, $code)),
        OrderStatus::InProgress,
        null,
    );

    expect($translated->supported)->toBeTrue()
        ->and($translated->observedState)->toBe($code)
        ->and($translated->status)->toBe($expected['status'])
        ->and($translated->holdReason)->toBe($expected['hold'])
        ->and($translated->allowedActions)->toBe($expected['actions']);
})->with(fftTranslatorFixture());

test('an unknown code is rejected before a finished-looking status can complete', function (array $payload, OrderStatus $current, array $expected): void {
    $translated = (new SupplierStateTranslator)->translate(
        fftTranslatorObservation($payload),
        $current,
        null,
    );

    expect($translated->supported)->toBeFalse()
        ->and($translated->status)->toBe($expected['status'])
        ->and($translated->holdReason)->toBe($expected['hold'])
        ->and($translated->allowedActions)->toBe($expected['actions'])
        ->and($translated->observedState)->toBe($expected['observed']);
})->with(fftTranslatorCombinations());

test('a status that merely resembles a stopped status is unsupported, not a hold', function (): void {
    $translated = (new SupplierStateTranslator)->translate(
        fftTranslatorObservation(['status' => 'UNSTOPPABLE', 'accountCheck' => '', 'economyState' => '']),
        OrderStatus::InProgress,
        null,
    );

    expect($translated->supported)->toBeFalse()
        ->and($translated->status)->toBe(OrderStatus::InProgress)
        ->and($translated->holdReason)->toBeNull()
        ->and($translated->allowedActions)->toBe([])
        ->and($translated->observedState)->toBe('unstoppable');
});

test('a real account error outranks an active status and a deactivated economy state', function (): void {
    $translated = (new SupplierStateTranslator)->translate(
        fftTranslatorObservation([
            'status' => 'transfersInProgress',
            'accountCheck' => 'wrongUserPass',
            'economyState' => 'deactivated',
        ]),
        OrderStatus::InProgress,
        null,
    );

    expect($translated->supported)->toBeTrue()
        ->and($translated->status)->toBe(OrderStatus::WaitingForCustomer)
        ->and($translated->holdReason)->toBe(OrderHoldReason::Credentials)
        ->and($translated->allowedActions)->toBe([SupplierAction::EditCredentials]);
});

test('an unknown code keeps the last known customer state', function (string $field): void {
    $translated = (new SupplierStateTranslator)->translate(
        fftTranslatorObservation(fftTranslatorPayload($field, 'brandnewsignal')),
        OrderStatus::WaitingForCustomer,
        DeliveryPhase::Challenge,
    );

    expect($translated->supported)->toBeFalse()
        ->and($translated->status)->toBe(OrderStatus::WaitingForCustomer)
        ->and($translated->holdReason)->toBeNull()
        ->and($translated->allowedActions)->toBe([])
        ->and($translated->observedState)->toBe('brandnewsignal');
})->with(['accountCheck', 'economyState', 'status']);

test('a malformed payload keeps the last known customer state without throwing', function (array $payload): void {
    $translated = (new SupplierStateTranslator)->translate(
        fftTranslatorObservation($payload),
        OrderStatus::InProgress,
        null,
    );

    expect($translated->supported)->toBeFalse()
        ->and($translated->status)->toBe(OrderStatus::InProgress)
        ->and($translated->holdReason)->toBeNull()
        ->and($translated->allowedActions)->toBe([])
        ->and($translated->observedState)->toBeNull();
})->with([
    'empty' => [[]],
    'all null' => [['status' => null, 'accountCheck' => null, 'economyState' => null]],
    'array status' => [['status' => ['finished'], 'accountCheck' => '', 'economyState' => '']],
    'numeric status' => [['status' => 12, 'accountCheck' => '', 'economyState' => '']],
    'boolean status' => [['status' => false, 'accountCheck' => '', 'economyState' => '']],
    'array account check' => [['status' => 'entered', 'accountCheck' => ['wrongBA'], 'economyState' => '']],
    'numeric economy state' => [['status' => 'entered', 'accountCheck' => '', 'economyState' => 5]],
]);

test('a terminal order never walks backwards', function (): void {
    $translator = new SupplierStateTranslator;
    $waiting = ['status' => 'interrupted', 'accountCheck' => 'wrongBA', 'economyState' => ''];
    $healthy = ['status' => 'entered', 'accountCheck' => '', 'economyState' => ''];
    $finished = ['status' => 'finished', 'accountCheck' => '', 'economyState' => ''];

    $stuckCompleted = $translator->translate(fftTranslatorObservation($waiting), OrderStatus::Completed, DeliveryPhase::Challenge);
    $healthyCompleted = $translator->translate(fftTranslatorObservation($healthy), OrderStatus::Completed, null);
    $cancelled = $translator->translate(fftTranslatorObservation($healthy), OrderStatus::Cancelled, null);
    $refunded = $translator->translate(fftTranslatorObservation($finished), OrderStatus::Refunded, null);

    expect($stuckCompleted->status)->toBe(OrderStatus::Completed)
        ->and($stuckCompleted->supported)->toBeTrue()
        ->and($stuckCompleted->holdReason)->toBeNull()
        ->and($stuckCompleted->allowedActions)->toBe([])
        ->and($healthyCompleted->status)->toBe(OrderStatus::Completed)
        ->and($healthyCompleted->holdReason)->toBeNull()
        ->and($healthyCompleted->allowedActions)->toBe([])
        ->and($cancelled->status)->toBe(OrderStatus::Cancelled)
        ->and($cancelled->holdReason)->toBeNull()
        ->and($cancelled->allowedActions)->toBe([])
        ->and($refunded->status)->toBe(OrderStatus::Refunded)
        ->and($refunded->holdReason)->toBeNull()
        ->and($refunded->allowedActions)->toBe([]);
});

test('progress is carried only when the payload carries it', function (): void {
    $translator = new SupplierStateTranslator;
    $base = ['status' => 'entered', 'accountCheck' => '', 'economyState' => 'transfersInProgress'];

    $translated = $translator->translate(
        fftTranslatorObservation($base + [
            'amount' => 1.5,
            'amountOrdered' => 2,
            'challengesDone' => 3,
            'totalChallenges' => 4,
        ]),
        OrderStatus::InProgress,
        DeliveryPhase::Challenge,
    );

    $guessedNames = $translator->translate(
        fftTranslatorObservation($base + [
            'challengesSolved' => 5,
            'challengesRequested' => 6,
        ]),
        OrderStatus::InProgress,
        DeliveryPhase::Challenge,
    );

    $missing = $translator->translate(fftTranslatorObservation($base), OrderStatus::InProgress, null);

    expect($translated->coinsDelivered)->toBe(1500)
        ->and($translated->coinsOrdered)->toBe(2000)
        ->and($translated->squadsDone)->toBe(3)
        ->and($translated->squadsTotal)->toBe(4)
        ->and($guessedNames->squadsDone)->toBeNull()
        ->and($guessedNames->squadsTotal)->toBeNull()
        ->and($missing->coinsDelivered)->toBeNull()
        ->and($missing->coinsOrdered)->toBeNull()
        ->and($missing->squadsDone)->toBeNull()
        ->and($missing->squadsTotal)->toBeNull();
});

test('unusable progress values become null', function (): void {
    $translated = (new SupplierStateTranslator)->translate(
        fftTranslatorObservation([
            'status' => 'entered',
            'accountCheck' => '',
            'economyState' => '',
            'amount' => -1,
            'amountOrdered' => 'not a number',
            'challengesDone' => [],
        ]),
        OrderStatus::InProgress,
        null,
    );

    expect($translated->coinsDelivered)->toBeNull()
        ->and($translated->coinsOrdered)->toBeNull()
        ->and($translated->squadsDone)->toBeNull();
});

test('translated strings stay ASCII', function (): void {
    $translator = new SupplierStateTranslator;

    foreach (fftTranslatorFixture() as $dataset) {
        [$field, $code] = $dataset;

        $translated = $translator->translate(
            fftTranslatorObservation(fftTranslatorPayload($field, $code)),
            OrderStatus::InProgress,
            null,
        );

        $strings = [
            $translated->observedState,
            $translated->status->value,
            $translated->holdReason?->value,
        ];

        foreach ($translated->allowedActions as $action) {
            $strings[] = $action->value;
        }

        foreach ($strings as $string) {
            if ($string !== null) {
                expect($string)->toMatch('/^[\x20-\x7E]+$/');
            }
        }
    }
});

test('a challenge retry is offered only to FFT jobs inside the challenge phase', function (): void {
    $translator = new SupplierStateTranslator;
    $waiting = ['status' => 'interrupted', 'accountCheck' => '', 'economyState' => ''];

    $fftChallenge = $translator->translate(fftTranslatorObservation($waiting), OrderStatus::InProgress, DeliveryPhase::Challenge);
    $fftNoPhase = $translator->translate(fftTranslatorObservation($waiting), OrderStatus::InProgress, null);
    $fftCoins = $translator->translate(fftTranslatorObservation($waiting), OrderStatus::InProgress, DeliveryPhase::Coins);
    $uttChallenge = $translator->translate(fftTranslatorObservation($waiting, Supplier::Utt), OrderStatus::InProgress, DeliveryPhase::Challenge);

    expect($fftChallenge->allowedActions)->toContain(SupplierAction::RetryChallenge)
        ->and($fftNoPhase->allowedActions)->not->toContain(SupplierAction::RetryChallenge)
        ->and($fftCoins->allowedActions)->not->toContain(SupplierAction::RetryChallenge)
        ->and($uttChallenge->allowedActions)->not->toContain(SupplierAction::RetryChallenge);
});

test('the two reason sets classify every hold reason exactly once', function (): void {
    $class = new ReflectionClass(SupplierStateTranslator::class);
    $automatic = $class->getReflectionConstant('AUTOMATIC_RECOVERY_REASONS');
    $customer = $class->getReflectionConstant('CUSTOMER_ACTION_REASONS');

    expect($automatic)->toBeInstanceOf(ReflectionClassConstant::class)
        ->and($customer)->toBeInstanceOf(ReflectionClassConstant::class);

    $classified = array_merge($automatic->getValue(), $customer->getValue());

    expect($classified)->toHaveCount(count(OrderHoldReason::cases()))
        ->and(array_unique($classified, SORT_REGULAR))->toHaveCount(count($classified));

    foreach (OrderHoldReason::cases() as $reason) {
        expect($classified)->toContain($reason);
    }
});

test('an automatic-recovery hold keeps the order moving even when the status says stopped', function (): void {
    $translated = (new SupplierStateTranslator)->translate(
        fftTranslatorObservation([
            'status' => 'stopped',
            'accountCheck' => '',
            'economyState' => 'insufficientFunds',
        ]),
        OrderStatus::InProgress,
        DeliveryPhase::Challenge,
    );

    // The status still says the customer is not blocked, but the Resume button
    // stays: the owner's rule is that the tracking link keeps every control the
    // tracker offers, and here it means "try now" rather than "you must act".
    expect($translated->status)->toBe(OrderStatus::InProgress)
        ->and($translated->holdReason)->toBe(OrderHoldReason::StoreStock)
        ->and($translated->allowedActions)->toBe([SupplierAction::Resume]);
});

test('a customer-action hold keeps the tracker actions when the status says stopped', function (): void {
    $translated = (new SupplierStateTranslator)->translate(
        fftTranslatorObservation([
            'status' => 'interrupted',
            'accountCheck' => 'wrongBA',
            'economyState' => '',
        ]),
        OrderStatus::InProgress,
        null,
    );

    expect($translated->status)->toBe(OrderStatus::WaitingForCustomer)
        ->and($translated->holdReason)->toBe(OrderHoldReason::BackupCodes)
        ->and($translated->allowedActions)->toBe([SupplierAction::EditCredentials, SupplierAction::Resume]);
});

test('no supplier signal can ever produce Cancelled or Refunded', function (): void {
    $translator = new SupplierStateTranslator;
    $payloads = [];

    foreach (fftTranslatorFixture() as $key => [$field, $code]) {
        $payloads[$key] = fftTranslatorPayload($field, $code);
    }

    $payloads['status:aborted'] = fftTranslatorPayload('status', 'aborted');
    $payloads['status:stopping'] = fftTranslatorPayload('status', 'stopping');
    $payloads['unknown:accountCheck'] = fftTranslatorPayload('accountCheck', 'brandnewsignal');
    $payloads['unknown:economyState'] = fftTranslatorPayload('economyState', 'brandnewsignal');
    $payloads['unknown:status'] = fftTranslatorPayload('status', 'brandnewsignal');
    $payloads['malformed:array status'] = ['status' => ['finished'], 'accountCheck' => '', 'economyState' => ''];
    $payloads['malformed:empty'] = [];

    $currents = array_filter(
        OrderStatus::cases(),
        static fn (OrderStatus $status): bool => ! in_array($status, [OrderStatus::Cancelled, OrderStatus::Refunded], true),
    );
    $forbidden = [];

    foreach (Supplier::cases() as $supplier) {
        foreach ([null, DeliveryPhase::Coins, DeliveryPhase::Challenge] as $phase) {
            foreach ($currents as $current) {
                foreach ($payloads as $key => $payload) {
                    $status = $translator->translate(fftTranslatorObservation($payload, $supplier), $current, $phase)->status;

                    if (in_array($status, [OrderStatus::Cancelled, OrderStatus::Refunded], true)) {
                        $forbidden[] = $key.' / '.$supplier->value.' / '.($phase?->value ?? 'no phase').' / '.$current->value;
                    }
                }
            }
        }
    }

    expect($forbidden)->toBe([]);
});

test('no supplier code is denied its tracker actions because its hold is automatic', function (): void {
    // The owner's rule, 2026-09-12: the customer keeps every control the tracker
    // offers. A previous round carved out two codes that showed no button at all,
    // and this test exists so that carve-out cannot come back quietly - it fails
    // the moment any code the tracker lists for Resume returns an empty set.
    $translator = new SupplierStateTranslator;
    $states = require __DIR__.'/../../Fixtures/Suppliers/fft-states.php';

    $silent = [];

    foreach (['accountCheck', 'economyState'] as $field) {
        foreach ($states[$field] as $code => $expected) {
            if ($expected['actions'] === []) {
                continue;
            }

            $translated = $translator->translate(
                fftTranslatorObservation([
                    'status' => '',
                    'accountCheck' => $field === 'accountCheck' ? $code : '',
                    'economyState' => $field === 'economyState' ? $code : '',
                ]),
                OrderStatus::InProgress,
                null,
            );

            if ($translated->allowedActions === []) {
                $silent[] = $field.':'.$code;
            }
        }
    }

    expect($silent)->toBe([]);
});

test('finished and alreadyCompleted each resolve to a completed state; a status containing but not equal to a finished word does not', function (): void {
    $translator = new SupplierStateTranslator;

    $finished = $translator->translateChallenge(Supplier::Fft, ['chal-1'], ['chal-1' => ['sbcStatus' => 'finished']], OrderStatus::InProgress);
    $alreadyCompleted = $translator->translateChallenge(Supplier::Fft, ['chal-1'], ['chal-1' => ['sbcStatus' => 'alreadyCompleted']], OrderStatus::InProgress);
    $unfinished = $translator->translateChallenge(Supplier::Fft, ['chal-1'], ['chal-1' => ['sbcStatus' => 'unfinished']], OrderStatus::InProgress);

    expect($finished->status)->toBe(OrderStatus::Completed)
        ->and($finished->supported)->toBeTrue()
        ->and($finished->holdReason)->toBeNull()
        ->and($finished->allowedActions)->toBe([])
        ->and($alreadyCompleted->status)->toBe(OrderStatus::Completed)
        ->and($alreadyCompleted->supported)->toBeTrue()
        ->and($alreadyCompleted->holdReason)->toBeNull()
        ->and($alreadyCompleted->allowedActions)->toBe([])
        ->and($unfinished->status)->toBe(OrderStatus::InProgress)
        ->and($unfinished->supported)->toBeFalse()
        ->and($unfinished->holdReason)->toBeNull()
        ->and($unfinished->allowedActions)->toBe([]);
});

test('an active progress status is InProgress with no hold reason', function (string $activeStatus): void {
    $translator = new SupplierStateTranslator;

    $translated = $translator->translateChallenge(
        Supplier::Fft,
        ['chal-1'],
        ['chal-1' => ['sbcStatus' => $activeStatus]],
        OrderStatus::InProgress,
    );

    expect($translated->status)->toBe(OrderStatus::InProgress)
        ->and($translated->supported)->toBeTrue()
        ->and($translated->holdReason)->toBeNull()
        ->and($translated->allowedActions)->toBe([]);
})->with([
    'entered' => ['entered'],
    'waitingForOtherSolve' => ['waitingForOtherSolve'],
    'started' => ['started'],
    'fetchSBCInfo' => ['fetchSBCInfo'],
    'fetchChallengeInfo' => ['fetchChallengeInfo'],
    'solvingChallenge' => ['solvingChallenge'],
]);

test('WrongUserPass offers edit and retry; WrongBA the same', function (string $credentialError, OrderHoldReason $expectedHold): void {
    $translator = new SupplierStateTranslator;

    $translated = $translator->translateChallenge(
        Supplier::Fft,
        ['chal-1'],
        ['chal-1' => ['sbcStatus' => $credentialError]],
        OrderStatus::InProgress,
    );

    expect($translated->status)->toBe(OrderStatus::WaitingForCustomer)
        ->and($translated->holdReason)->toBe($expectedHold)
        ->and($translated->allowedActions)->toContain(SupplierAction::EditCredentials)
        ->and($translated->allowedActions)->toContain(SupplierAction::RetryChallenge);
})->with([
    'WrongUserPass' => ['WrongUserPass', OrderHoldReason::Credentials],
    'WrongBA' => ['WrongBA', OrderHoldReason::BackupCodes],
]);

test('a login failure offers both a retry and a credential correction, and keeps the order moving', function (string $status): void {
    // Owner decision, 2026-09-13: a failed login "could be a problem with the account or
    // it could be the servers", so the customer gets a retry and a way to fix an email
    // they may have typed wrong. The tracker offers editing on two statuses only and its
    // own API enforces that, but that gate is the tracker's product choice: FFT accepts a
    // credential correction whatever the status, which the gate's own comment says.
    // The order stays InProgress because it may well be EA's side.
    $translator = new SupplierStateTranslator;

    $translated = $translator->translateChallenge(
        Supplier::Fft,
        ['chal-1'],
        ['chal-1' => ['sbcStatus' => $status]],
        OrderStatus::InProgress,
    );

    expect($translated->allowedActions)->toContain(SupplierAction::RetryChallenge)
        ->and($translated->allowedActions)->toContain(SupplierAction::EditCredentials)
        ->and($translated->status)->toBe(OrderStatus::InProgress);
})->with([
    'LoginFailed', 'loginFailed', 'LoginFailed401', 'LoginFailed495', 'LoginError', 'loginLoop',
]);

test('a status outside the editable set offers no credential correction', function (): void {
    // The strictly-absent guard the widened boundary must not quietly lose: a solver
    // failure is not a credentials problem, and offering the sheet there would send the
    // customer to fix data that is already correct.
    $translator = new SupplierStateTranslator;

    $translated = $translator->translateChallenge(
        Supplier::Fft,
        ['chal-1'],
        ['chal-1' => ['sbcStatus' => 'tooExpensive']],
        OrderStatus::InProgress,
    );

    expect($translated->allowedActions)->toContain(SupplierAction::RetryChallenge)
        ->and($translated->allowedActions)->not->toContain(SupplierAction::EditCredentials);
});

test('a status in neither the retryable set nor the edit pair offers no actions', function (string $status, OrderHoldReason $expectedHold, OrderStatus $expectedStatus): void {
    $translator = new SupplierStateTranslator;

    $translated = $translator->translateChallenge(
        Supplier::Fft,
        ['chal-1'],
        ['chal-1' => ['sbcStatus' => $status]],
        OrderStatus::InProgress,
    );

    expect($translated->allowedActions)->toBe([])
        ->and($translated->holdReason)->toBe($expectedHold)
        ->and($translated->status)->toBe($expectedStatus)
        ->and($translated->supported)->toBeTrue();
})->with([
    '2FADisabled' => ['2FADisabled', OrderHoldReason::TwoFactorOff, OrderStatus::WaitingForCustomer],
    'failedNoClub' => ['failedNoClub', OrderHoldReason::NoClub, OrderStatus::WaitingForCustomer],
    'TMLocked' => ['TMLocked', OrderHoldReason::MarketLocked, OrderStatus::WaitingForCustomer],
    'dailyReceiverLimit' => ['dailyReceiverLimit', OrderHoldReason::Paused, OrderStatus::InProgress],
]);

test('an unknown sbcStatus comes back supported false, no actions, no hold reason, and canonical status unchanged', function (): void {
    $translator = new SupplierStateTranslator;

    $translated = $translator->translateChallenge(
        Supplier::Fft,
        ['chal-1'],
        ['chal-1' => [
            'sbcStatus' => 'CompletelyUnknownSbcStatusCode',
            'challengesDone' => 1,
            'totalChallenges' => 7,
        ]],
        OrderStatus::WaitingForCustomer,
    );

    expect($translated->supported)->toBeFalse()
        ->and($translated->status)->toBe(OrderStatus::WaitingForCustomer)
        ->and($translated->holdReason)->toBeNull()
        ->and($translated->allowedActions)->toBe([])
        ->and($translated->observedState)->toBe('CompletelyUnknownSbcStatusCode')
        ->and($translated->squadsDone)->toBe(1)
        ->and($translated->squadsTotal)->toBe(7);
});

test('all four counters land in the right fields from raw payload keys', function (): void {
    $translator = new SupplierStateTranslator;

    $translated = $translator->translateChallenge(
        Supplier::Fft,
        ['chal-1'],
        ['chal-1' => [
            'sbcStatus' => 'started',
            'challengesDone' => 3,
            'totalChallenges' => 7,
            'timesSolved' => 1,
            'timesToSolve' => 2,
        ]],
        OrderStatus::InProgress,
    );

    expect($translated->squadsDone)->toBe(3)
        ->and($translated->squadsTotal)->toBe(7)
        ->and($translated->solvesDone)->toBe(1)
        ->and($translated->solvesTotal)->toBe(2);
});

test('over-delivery is stored unclamped', function (): void {
    $translator = new SupplierStateTranslator;

    $translated = $translator->translateChallenge(
        Supplier::Fft,
        ['chal-1'],
        ['chal-1' => [
            'sbcStatus' => 'started',
            'challengesDone' => 8,
            'totalChallenges' => 7,
            'timesSolved' => 3,
            'timesToSolve' => 2,
        ]],
        OrderStatus::InProgress,
    );

    expect($translated->squadsDone)->toBe(8)
        ->and($translated->squadsTotal)->toBe(7)
        ->and($translated->solvesDone)->toBe(3)
        ->and($translated->solvesTotal)->toBe(2);
});

test('every one of the fifty-one sbcStatus values maps to its canonical status, hold reason, and action set', function (
    string $sbcStatus,
    OrderStatus $expectedStatus,
    ?OrderHoldReason $expectedHold,
    array $expectedActions,
): void {
    $translator = new SupplierStateTranslator;

    $translated = $translator->translateChallenge(
        Supplier::Fft,
        ['chal-1'],
        ['chal-1' => ['sbcStatus' => $sbcStatus]],
        OrderStatus::InProgress,
    );

    expect($translated->status)->toBe($expectedStatus)
        ->and($translated->holdReason)->toBe($expectedHold)
        ->and($translated->allowedActions)->toBe($expectedActions)
        ->and($translated->supported)->toBeTrue()
        ->and($translated->observedState)->toBe($sbcStatus);
})->with([
    // Finished statuses (2)
    'finished' => ['finished', OrderStatus::Completed, null, []],
    'alreadyCompleted' => ['alreadyCompleted', OrderStatus::Completed, null, []],

    // Safe / in-progress statuses (6)
    'entered' => ['entered', OrderStatus::InProgress, null, []],
    'waitingForOtherSolve' => ['waitingForOtherSolve', OrderStatus::InProgress, null, []],
    'started' => ['started', OrderStatus::InProgress, null, []],
    'fetchSBCInfo' => ['fetchSBCInfo', OrderStatus::InProgress, null, []],
    'fetchChallengeInfo' => ['fetchChallengeInfo', OrderStatus::InProgress, null, []],
    'solvingChallenge' => ['solvingChallenge', OrderStatus::InProgress, null, []],

    // Auth / session errors (14)
    'WrongUserPass' => ['WrongUserPass', OrderStatus::WaitingForCustomer, OrderHoldReason::Credentials, [SupplierAction::EditCredentials, SupplierAction::RetryChallenge]],
    'WrongBA' => ['WrongBA', OrderStatus::WaitingForCustomer, OrderHoldReason::BackupCodes, [SupplierAction::EditCredentials, SupplierAction::RetryChallenge]],
    'sessionExpired' => ['sessionExpired', OrderStatus::InProgress, OrderHoldReason::EaServers, [SupplierAction::RetryChallenge]],
    'needEmailConfirm' => ['needEmailConfirm', OrderStatus::WaitingForCustomer, OrderHoldReason::EmailConfirm, [SupplierAction::EditCredentials, SupplierAction::RetryChallenge]],
    'LoginFailed495' => ['LoginFailed495', OrderStatus::InProgress, OrderHoldReason::EaServers, [SupplierAction::EditCredentials, SupplierAction::RetryChallenge]],
    'LoginFailed401' => ['LoginFailed401', OrderStatus::InProgress, OrderHoldReason::EaServers, [SupplierAction::EditCredentials, SupplierAction::RetryChallenge]],
    'LoginFailedDeviceBan' => ['LoginFailedDeviceBan', OrderStatus::WaitingForCustomer, OrderHoldReason::AccountBanned, [SupplierAction::EditCredentials, SupplierAction::RetryChallenge]],
    'LoginError' => ['LoginError', OrderStatus::InProgress, OrderHoldReason::EaServers, [SupplierAction::EditCredentials, SupplierAction::RetryChallenge]],
    'LoginFailed' => ['LoginFailed', OrderStatus::InProgress, OrderHoldReason::EaServers, [SupplierAction::EditCredentials, SupplierAction::RetryChallenge]],
    'loginFailed' => ['loginFailed', OrderStatus::InProgress, OrderHoldReason::EaServers, [SupplierAction::EditCredentials, SupplierAction::RetryChallenge]],
    '2FADisabled' => ['2FADisabled', OrderStatus::WaitingForCustomer, OrderHoldReason::TwoFactorOff, []],
    'no2fa' => ['no2fa', OrderStatus::WaitingForCustomer, OrderHoldReason::TwoFactorOff, []],
    'No2FA' => ['No2FA', OrderStatus::WaitingForCustomer, OrderHoldReason::TwoFactorOff, []],
    'loginLoop' => ['loginLoop', OrderStatus::InProgress, OrderHoldReason::EaServers, [SupplierAction::EditCredentials, SupplierAction::RetryChallenge]],

    // Proxy / connection errors (3)
    'FailProxyConn' => ['FailProxyConn', OrderStatus::InProgress, OrderHoldReason::Connection, [SupplierAction::RetryChallenge]],
    'FailedProxyConnectionError' => ['FailedProxyConnectionError', OrderStatus::InProgress, OrderHoldReason::Connection, []],
    'FailProxy' => ['FailProxy', OrderStatus::InProgress, OrderHoldReason::Connection, []],

    // Account / setup errors (4)
    'failedNoClub' => ['failedNoClub', OrderStatus::WaitingForCustomer, OrderHoldReason::NoClub, []],
    'consoleLoggedIn' => ['consoleLoggedIn', OrderStatus::WaitingForCustomer, OrderHoldReason::ActiveSession, [SupplierAction::RetryChallenge]],
    'FailedPersonaSwitch' => ['FailedPersonaSwitch', OrderStatus::WaitingForCustomer, OrderHoldReason::Credentials, []],
    'TMLocked' => ['TMLocked', OrderStatus::WaitingForCustomer, OrderHoldReason::MarketLocked, []],

    // SBC-specific errors (8)
    'setNotFound' => ['setNotFound', OrderStatus::InProgress, null, [SupplierAction::RetryChallenge]],
    'foundationNotSolved' => ['foundationNotSolved', OrderStatus::InProgress, null, [SupplierAction::RetryChallenge]],
    'challengeDataMissing' => ['challengeDataMissing', OrderStatus::InProgress, null, [SupplierAction::RetryChallenge]],
    'noSolutionFound' => ['noSolutionFound', OrderStatus::InProgress, OrderHoldReason::NoPlayer, [SupplierAction::RetryChallenge]],
    'tooExpensive' => ['tooExpensive', OrderStatus::InProgress, null, [SupplierAction::RetryChallenge]],
    'clickFailed' => ['clickFailed', OrderStatus::InProgress, null, [SupplierAction::RetryChallenge]],
    'submitFailed' => ['submitFailed', OrderStatus::InProgress, OrderHoldReason::EaServers, [SupplierAction::RetryChallenge]],
    'squadCreateFailed' => ['squadCreateFailed', OrderStatus::InProgress, null, [SupplierAction::RetryChallenge]],

    // Player / market errors (6)
    'playerBuyFailed' => ['playerBuyFailed', OrderStatus::InProgress, null, [SupplierAction::RetryChallenge]],
    'playerNotFound' => ['playerNotFound', OrderStatus::InProgress, OrderHoldReason::NoPlayer, [SupplierAction::RetryChallenge]],
    'playerNotMoved' => ['playerNotMoved', OrderStatus::InProgress, null, [SupplierAction::RetryChallenge]],
    'clubQueryFailed' => ['clubQueryFailed', OrderStatus::InProgress, OrderHoldReason::EaServers, [SupplierAction::RetryChallenge]],
    'tooManyExchanges' => ['tooManyExchanges', OrderStatus::InProgress, OrderHoldReason::Paused, [SupplierAction::RetryChallenge]],
    'noPriceFound' => ['noPriceFound', OrderStatus::InProgress, OrderHoldReason::NoPlayer, [SupplierAction::RetryChallenge]],

    // Financial / Cooldown errors (5)
    'noFunds' => ['noFunds', OrderStatus::InProgress, OrderHoldReason::StoreStock, [SupplierAction::RetryChallenge]],
    'OutOfCoins' => ['OutOfCoins', OrderStatus::WaitingForCustomer, OrderHoldReason::InsufficientCoins, [SupplierAction::RetryChallenge]],
    'tempban' => ['tempban', OrderStatus::InProgress, OrderHoldReason::Paused, [SupplierAction::RetryChallenge]],
    'TempbanCooldown' => ['TempbanCooldown', OrderStatus::InProgress, OrderHoldReason::Paused, [SupplierAction::RetryChallenge]],
    'dailyReceiverLimit' => ['dailyReceiverLimit', OrderStatus::InProgress, OrderHoldReason::Paused, []],

    // System errors (3)
    'aborted' => ['aborted', OrderStatus::InProgress, OrderHoldReason::Paused, [SupplierAction::RetryChallenge]],
    'failed' => ['failed', OrderStatus::InProgress, null, [SupplierAction::RetryChallenge]],
    'FailUnassignedFound' => ['FailUnassignedFound', OrderStatus::WaitingForCustomer, OrderHoldReason::Unassigned, [SupplierAction::RetryChallenge]],
]);

test('a requested challenge id missing from response leaves order InProgress even if all returned are finished', function (): void {
    $translator = new SupplierStateTranslator;

    $translated = $translator->translateChallenge(
        Supplier::Fft,
        ['chal-1', 'chal-2'],
        ['chal-1' => ['sbcStatus' => 'finished', 'challengesDone' => 7, 'totalChallenges' => 7]],
        OrderStatus::InProgress,
    );

    expect($translated->status)->toBe(OrderStatus::InProgress)
        ->and($translated->supported)->toBeTrue()
        ->and($translated->holdReason)->toBeNull()
        ->and($translated->allowedActions)->toBe([])
        ->and($translated->observedState)->toBe('finished')
        ->and($translated->squadsDone)->toBe(7)
        ->and($translated->squadsTotal)->toBe(7);
});

test('active challenge selection picks first returned not finished over last returned', function (): void {
    $translator = new SupplierStateTranslator;

    $translated = $translator->translateChallenge(
        Supplier::Fft,
        ['chal-1', 'chal-2'],
        [
            'chal-1' => ['sbcStatus' => 'solvingChallenge', 'challengesDone' => 3, 'totalChallenges' => 7],
            'chal-2' => ['sbcStatus' => 'finished', 'challengesDone' => 7, 'totalChallenges' => 7],
        ],
        OrderStatus::InProgress,
    );

    expect($translated->observedState)->toBe('solvingChallenge')
        ->and($translated->squadsDone)->toBe(3)
        ->and($translated->squadsTotal)->toBe(7);
});

test('active challenge selection falls back to last returned entry when all returned are finished', function (): void {
    $translator = new SupplierStateTranslator;

    $translated = $translator->translateChallenge(
        Supplier::Fft,
        ['chal-1', 'chal-2'],
        [
            'chal-1' => ['sbcStatus' => 'alreadyCompleted', 'challengesDone' => 5, 'totalChallenges' => 5],
            'chal-2' => ['sbcStatus' => 'finished', 'challengesDone' => 7, 'totalChallenges' => 7],
        ],
        OrderStatus::InProgress,
    );

    expect($translated->observedState)->toBe('finished')
        ->and($translated->status)->toBe(OrderStatus::Completed)
        ->and($translated->squadsDone)->toBe(7)
        ->and($translated->squadsTotal)->toBe(7);
});

test('empty bulk response fails closed with supported false and all counters null', function (): void {
    $translator = new SupplierStateTranslator;

    $translated = $translator->translateChallenge(
        Supplier::Fft,
        ['chal-1', 'chal-2'],
        [],
        OrderStatus::WaitingForCustomer,
    );

    expect($translated->supported)->toBeFalse()
        ->and($translated->status)->toBe(OrderStatus::WaitingForCustomer)
        ->and($translated->holdReason)->toBeNull()
        ->and($translated->allowedActions)->toBe([])
        ->and($translated->observedState)->toBeNull()
        ->and($translated->squadsDone)->toBeNull()
        ->and($translated->squadsTotal)->toBeNull()
        ->and($translated->solvesDone)->toBeNull()
        ->and($translated->solvesTotal)->toBeNull();
});

test('normalises challenge ids case-insensitively and strips SBC- prefix on both sides', function (): void {
    $translator = new SupplierStateTranslator;

    $uuid = '1803b7a6-0000-0000-0000-00000064265f';

    $translated = $translator->translateChallenge(
        Supplier::Fft,
        ['SBC-'.strtoupper($uuid)],
        [strtolower($uuid) => ['sbcStatus' => 'finished']],
        OrderStatus::InProgress,
    );

    expect($translated->status)->toBe(OrderStatus::Completed)
        ->and($translated->supported)->toBeTrue();
});

test('two response keys naming one challenge fail closed instead of merging', function (array $bulk): void {
    // A bare uuid and the same uuid with an SBC- prefix normalise to one id. Letting the
    // later entry win meant a finished alias could erase an unfinished observation and
    // complete the order, paying cashback on a challenge still being solved. Both
    // response orders are pinned because the bug only showed in one of them.
    $translator = new SupplierStateTranslator;

    $translated = $translator->translateChallenge(
        Supplier::Fft,
        ['1803b7a6-0000-0000-0000-00000064265f'],
        $bulk,
        OrderStatus::InProgress,
    );

    expect($translated->status)->toBe(OrderStatus::InProgress)
        ->and($translated->supported)->toBeFalse()
        ->and($translated->allowedActions)->toBe([])
        ->and($translated->squadsDone)->toBeNull();
})->with([
    'finished last' => [[
        '1803b7a6-0000-0000-0000-00000064265f' => ['sbcStatus' => 'solvingChallenge'],
        'SBC-1803B7A6-0000-0000-0000-00000064265F' => ['sbcStatus' => 'finished'],
    ]],
    'finished first' => [[
        'SBC-1803B7A6-0000-0000-0000-00000064265F' => ['sbcStatus' => 'finished'],
        '1803b7a6-0000-0000-0000-00000064265f' => ['sbcStatus' => 'solvingChallenge'],
    ]],
]);

test('a response key naming two challenges answers for neither', function (): void {
    // The permissive parse accepts a separated list, so "uuid-a,uuid-b" would resolve to
    // uuid-a and let one entry answer for a challenge it says nothing about.
    $translator = new SupplierStateTranslator;

    $translated = $translator->translateChallenge(
        Supplier::Fft,
        ['1803b7a6-0000-0000-0000-00000064265f'],
        ['1803b7a6-0000-0000-0000-00000064265f,3a0825bf-0000-0000-0000-00000064265f' => ['sbcStatus' => 'finished']],
        OrderStatus::InProgress,
    );

    expect($translated->status)->toBe(OrderStatus::InProgress)
        ->and($translated->supported)->toBeFalse();
});
