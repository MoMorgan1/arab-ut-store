<?php

use App\Enums\OrderHoldReason;
use App\Enums\OrderStatus;
use App\Enums\SupplierAction;

/**
 * Every supplier code the tracker classifies, transcribed from
 * track/assets/js/ui.js (errorMap, economyStateMap, customerAction sets) and
 * track/STATUS_MAPPING_CURRENT.md.
 *
 * This is the test's independent copy; the translator must never read it. Each
 * entry is the canonical state the code should produce when it is the only
 * signal in an otherwise healthy observation (status `entered`, phase null).
 *
 * The axes are independent: the hold reason decides the status (automatic
 * recovery stays InProgress, customer action waits in WaitingForCustomer),
 * while actions come from the tracker's showEditStates/showResumeStates lists,
 * except the two silent automatic codes (insufficientFunds, calcErrorMaintenance).
 *
 * The `combinations` table covers interactions between the axes: an unknown
 * code always wins over a finished-looking status, unknown codes are reported
 * in accountCheck > economyState > status order, `unfinished` is not finished,
 * and `unstoppable` is not stopped. Every combination is unsupported and keeps
 * the current status.
 *
 * @return array{
 *     accountCheck: array<string, array{status: OrderStatus, hold: OrderHoldReason|null, actions: list<SupplierAction>}>,
 *     economyState: array<string, array{status: OrderStatus, hold: OrderHoldReason|null, actions: list<SupplierAction>}>,
 *     status: array<string, array{status: OrderStatus, hold: OrderHoldReason|null, actions: list<SupplierAction>}>,
 *     combinations: array<string, array{payload: array<string, string>, current: OrderStatus, expected: array{status: OrderStatus, hold: OrderHoldReason|null, actions: list<SupplierAction>, observed: string}}>
 * }
 */
return [
    'accountCheck' => [
        'wrongBA' => ['status' => OrderStatus::WaitingForCustomer, 'hold' => OrderHoldReason::BackupCodes, 'actions' => [SupplierAction::EditCredentials]],
        'wrongUserPass' => ['status' => OrderStatus::WaitingForCustomer, 'hold' => OrderHoldReason::Credentials, 'actions' => [SupplierAction::EditCredentials]],
        'wrongConsole' => ['status' => OrderStatus::WaitingForCustomer, 'hold' => OrderHoldReason::Platform, 'actions' => [SupplierAction::EditCredentials]],
        'noTM' => ['status' => OrderStatus::WaitingForCustomer, 'hold' => OrderHoldReason::MarketLocked, 'actions' => [SupplierAction::EditCredentials, SupplierAction::Resume]],
        'notEnoughCoins' => ['status' => OrderStatus::WaitingForCustomer, 'hold' => OrderHoldReason::InsufficientCoins, 'actions' => [SupplierAction::Resume]],
        'active session' => ['status' => OrderStatus::WaitingForCustomer, 'hold' => OrderHoldReason::ActiveSession, 'actions' => [SupplierAction::Resume]],
        'loginFailed' => ['status' => OrderStatus::InProgress, 'hold' => OrderHoldReason::EaServers, 'actions' => [SupplierAction::Resume]],
        'noClub' => ['status' => OrderStatus::WaitingForCustomer, 'hold' => OrderHoldReason::NoClub, 'actions' => [SupplierAction::EditCredentials, SupplierAction::Resume]],
        'tlFull' => ['status' => OrderStatus::WaitingForCustomer, 'hold' => OrderHoldReason::TransferListFull, 'actions' => [SupplierAction::Resume]],
        'captcha' => ['status' => OrderStatus::WaitingForCustomer, 'hold' => OrderHoldReason::Captcha, 'actions' => [SupplierAction::Resume]],
        'wrongPersona' => ['status' => OrderStatus::WaitingForCustomer, 'hold' => OrderHoldReason::Credentials, 'actions' => [SupplierAction::EditCredentials, SupplierAction::Resume]],
        'console' => ['status' => OrderStatus::WaitingForCustomer, 'hold' => OrderHoldReason::ActiveSession, 'actions' => [SupplierAction::Resume]],
        'consoleLoggedIn' => ['status' => OrderStatus::WaitingForCustomer, 'hold' => OrderHoldReason::ActiveSession, 'actions' => [SupplierAction::Resume]],
        'unassignedItemsPresent' => ['status' => OrderStatus::WaitingForCustomer, 'hold' => OrderHoldReason::Unassigned, 'actions' => [SupplierAction::Resume]],
        'LoginFailedDeviceBan' => ['status' => OrderStatus::WaitingForCustomer, 'hold' => OrderHoldReason::AccountBanned, 'actions' => [SupplierAction::EditCredentials, SupplierAction::Resume]],
        'insufficientFunds' => ['status' => OrderStatus::InProgress, 'hold' => OrderHoldReason::StoreStock, 'actions' => []],
        '2FADisabled' => ['status' => OrderStatus::WaitingForCustomer, 'hold' => OrderHoldReason::Credentials, 'actions' => [SupplierAction::Resume]],
        'no2fa' => ['status' => OrderStatus::WaitingForCustomer, 'hold' => OrderHoldReason::Credentials, 'actions' => [SupplierAction::Resume]],
        'No2FA' => ['status' => OrderStatus::WaitingForCustomer, 'hold' => OrderHoldReason::Credentials, 'actions' => [SupplierAction::Resume]],
        'entered' => ['status' => OrderStatus::InProgress, 'hold' => null, 'actions' => []],
        'started' => ['status' => OrderStatus::InProgress, 'hold' => null, 'actions' => []],
        'userPassVerified' => ['status' => OrderStatus::InProgress, 'hold' => null, 'actions' => []],
        'correctBA' => ['status' => OrderStatus::InProgress, 'hold' => null, 'actions' => []],
        'noUnassignedItemsPresent' => ['status' => OrderStatus::InProgress, 'hold' => null, 'actions' => []],
    ],
    'economyState' => [
        'FailedWrongCredentialsTo' => ['status' => OrderStatus::WaitingForCustomer, 'hold' => OrderHoldReason::Credentials, 'actions' => [SupplierAction::EditCredentials]],
        'FailedWrongBACodeTo' => ['status' => OrderStatus::WaitingForCustomer, 'hold' => OrderHoldReason::BackupCodes, 'actions' => [SupplierAction::EditCredentials]],
        'deactivated' => ['status' => OrderStatus::InProgress, 'hold' => OrderHoldReason::Paused, 'actions' => []],
        'FailLoggedInConsoleTo' => ['status' => OrderStatus::WaitingForCustomer, 'hold' => OrderHoldReason::ActiveSession, 'actions' => [SupplierAction::Resume]],
        'FailNoClubToCanBeEAErrorTryAgain' => ['status' => OrderStatus::WaitingForCustomer, 'hold' => OrderHoldReason::NoClub, 'actions' => [SupplierAction::EditCredentials, SupplierAction::Resume]],
        'FailWebAppCustomerLocked' => ['status' => OrderStatus::WaitingForCustomer, 'hold' => OrderHoldReason::AccountBanned, 'actions' => [SupplierAction::EditCredentials, SupplierAction::Resume]],
        'FailedReceiverDeviceBan' => ['status' => OrderStatus::WaitingForCustomer, 'hold' => OrderHoldReason::AccountBanned, 'actions' => [SupplierAction::EditCredentials, SupplierAction::Resume]],
        'FailedSessionExpiredCustomerLoggedIn?' => ['status' => OrderStatus::WaitingForCustomer, 'hold' => OrderHoldReason::ActiveSession, 'actions' => [SupplierAction::Resume]],
        'FailedTLfullReceiver' => ['status' => OrderStatus::WaitingForCustomer, 'hold' => OrderHoldReason::TransferListFull, 'actions' => [SupplierAction::Resume]],
        'insufficientFunds' => ['status' => OrderStatus::InProgress, 'hold' => OrderHoldReason::StoreStock, 'actions' => []],
        'FailWebAppNotYetUnlocked' => ['status' => OrderStatus::WaitingForCustomer, 'hold' => OrderHoldReason::Credentials, 'actions' => [SupplierAction::EditCredentials, SupplierAction::Resume]],
        'tempbanCooldown' => ['status' => OrderStatus::InProgress, 'hold' => OrderHoldReason::Paused, 'actions' => []],
        'listingTempban' => ['status' => OrderStatus::InProgress, 'hold' => OrderHoldReason::Paused, 'actions' => []],
        'dailyReceiverLimit' => ['status' => OrderStatus::InProgress, 'hold' => OrderHoldReason::Paused, 'actions' => [SupplierAction::Resume]],
        'calcErrorMaintenance' => ['status' => OrderStatus::InProgress, 'hold' => OrderHoldReason::Maintenance, 'actions' => []],
        'FailedProxyConnectionError' => ['status' => OrderStatus::InProgress, 'hold' => OrderHoldReason::Connection, 'actions' => [SupplierAction::Resume]],
        'FailProxyUnavailable' => ['status' => OrderStatus::InProgress, 'hold' => OrderHoldReason::Connection, 'actions' => [SupplierAction::Resume]],
        'noSuitableSender' => ['status' => OrderStatus::InProgress, 'hold' => OrderHoldReason::NoPlayer, 'actions' => [SupplierAction::Resume]],
        'noPlayer' => ['status' => OrderStatus::InProgress, 'hold' => OrderHoldReason::NoPlayer, 'actions' => [SupplierAction::Resume]],
        'belowMinTransfer' => ['status' => OrderStatus::InProgress, 'hold' => null, 'actions' => [SupplierAction::Resume]],
        'transfersInProgress' => ['status' => OrderStatus::InProgress, 'hold' => null, 'actions' => []],
        'transferCycleComplete' => ['status' => OrderStatus::InProgress, 'hold' => null, 'actions' => []],
        'customerHasPlayer' => ['status' => OrderStatus::InProgress, 'hold' => null, 'actions' => []],
        'customerListedPlayer' => ['status' => OrderStatus::InProgress, 'hold' => null, 'actions' => []],
        'FailedProxyPoolExhausted' => ['status' => OrderStatus::InProgress, 'hold' => null, 'actions' => []],
    ],
    'status' => [
        'finished' => ['status' => OrderStatus::Completed, 'hold' => null, 'actions' => []],
        'completed' => ['status' => OrderStatus::Completed, 'hold' => null, 'actions' => []],
        'stopped' => ['status' => OrderStatus::WaitingForCustomer, 'hold' => OrderHoldReason::Paused, 'actions' => [SupplierAction::Resume]],
        'stopping' => ['status' => OrderStatus::WaitingForCustomer, 'hold' => OrderHoldReason::Paused, 'actions' => [SupplierAction::Resume]],
        'abort' => ['status' => OrderStatus::WaitingForCustomer, 'hold' => OrderHoldReason::Paused, 'actions' => [SupplierAction::Resume]],
        'aborted' => ['status' => OrderStatus::WaitingForCustomer, 'hold' => OrderHoldReason::Paused, 'actions' => [SupplierAction::Resume]],
        'interrupted' => ['status' => OrderStatus::WaitingForCustomer, 'hold' => OrderHoldReason::Paused, 'actions' => [SupplierAction::Resume]],
        'entered' => ['status' => OrderStatus::InProgress, 'hold' => null, 'actions' => []],
        'started' => ['status' => OrderStatus::InProgress, 'hold' => null, 'actions' => []],
        'ready' => ['status' => OrderStatus::InProgress, 'hold' => null, 'actions' => []],
        'waiting' => ['status' => OrderStatus::InProgress, 'hold' => null, 'actions' => []],
        'waitingforassignment' => ['status' => OrderStatus::InProgress, 'hold' => null, 'actions' => []],
        'transfersinprogress' => ['status' => OrderStatus::InProgress, 'hold' => null, 'actions' => []],
    ],
    'combinations' => [
        'finished with an unknown account check stays unsupported' => [
            'payload' => ['status' => 'finished', 'accountCheck' => 'brandnewsignal', 'economyState' => ''],
            'current' => OrderStatus::WaitingForCustomer,
            'expected' => ['status' => OrderStatus::WaitingForCustomer, 'hold' => null, 'actions' => [], 'observed' => 'brandnewsignal'],
        ],
        'finished with an unknown economy state stays unsupported' => [
            'payload' => ['status' => 'finished', 'accountCheck' => '', 'economyState' => 'brandnewsignal'],
            'current' => OrderStatus::InProgress,
            'expected' => ['status' => OrderStatus::InProgress, 'hold' => null, 'actions' => [], 'observed' => 'brandnewsignal'],
        ],
        'completed with an unknown account check stays unsupported' => [
            'payload' => ['status' => 'completed', 'accountCheck' => 'brandnewsignal', 'economyState' => ''],
            'current' => OrderStatus::InProgress,
            'expected' => ['status' => OrderStatus::InProgress, 'hold' => null, 'actions' => [], 'observed' => 'brandnewsignal'],
        ],
        'unfinished is not finished' => [
            'payload' => ['status' => 'unfinished', 'accountCheck' => '', 'economyState' => ''],
            'current' => OrderStatus::WaitingForCustomer,
            'expected' => ['status' => OrderStatus::WaitingForCustomer, 'hold' => null, 'actions' => [], 'observed' => 'unfinished'],
        ],
        'unstoppable is not stopped' => [
            'payload' => ['status' => 'unstoppable', 'accountCheck' => '', 'economyState' => ''],
            'current' => OrderStatus::InProgress,
            'expected' => ['status' => OrderStatus::InProgress, 'hold' => null, 'actions' => [], 'observed' => 'unstoppable'],
        ],
        'unfinished beside a known customer code is still unsupported' => [
            'payload' => ['status' => 'unfinished', 'accountCheck' => 'wrongBA', 'economyState' => ''],
            'current' => OrderStatus::InProgress,
            'expected' => ['status' => OrderStatus::InProgress, 'hold' => null, 'actions' => [], 'observed' => 'unfinished'],
        ],
        'unknown status beside a known account check stays unsupported' => [
            'payload' => ['status' => 'manual review', 'accountCheck' => 'wrongBA', 'economyState' => ''],
            'current' => OrderStatus::InProgress,
            'expected' => ['status' => OrderStatus::InProgress, 'hold' => null, 'actions' => [], 'observed' => 'manual review'],
        ],
        'unknown status beside an unknown account check reports the account check first' => [
            'payload' => ['status' => 'manual review', 'accountCheck' => 'brandnewsignal', 'economyState' => ''],
            'current' => OrderStatus::WaitingForCustomer,
            'expected' => ['status' => OrderStatus::WaitingForCustomer, 'hold' => null, 'actions' => [], 'observed' => 'brandnewsignal'],
        ],
    ],
];
