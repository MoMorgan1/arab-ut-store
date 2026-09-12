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
        ->and($translated->challengesSolved)->toBe(3)
        ->and($translated->challengesRequested)->toBe(4)
        ->and($guessedNames->challengesSolved)->toBeNull()
        ->and($guessedNames->challengesRequested)->toBeNull()
        ->and($missing->coinsDelivered)->toBeNull()
        ->and($missing->coinsOrdered)->toBeNull()
        ->and($missing->challengesSolved)->toBeNull()
        ->and($missing->challengesRequested)->toBeNull();
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
        ->and($translated->challengesSolved)->toBeNull();
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
