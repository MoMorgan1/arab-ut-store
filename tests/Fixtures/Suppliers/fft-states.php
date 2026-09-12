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
 * @return array{
 *     accountCheck: array<string, array{status: OrderStatus, hold: OrderHoldReason|null, actions: list<SupplierAction>}>,
 *     economyState: array<string, array{status: OrderStatus, hold: OrderHoldReason|null, actions: list<SupplierAction>}>,
 *     status: array<string, array{status: OrderStatus, hold: OrderHoldReason|null, actions: list<SupplierAction>}>
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
        'abort' => ['status' => OrderStatus::WaitingForCustomer, 'hold' => OrderHoldReason::Paused, 'actions' => [SupplierAction::Resume]],
        'interrupted' => ['status' => OrderStatus::WaitingForCustomer, 'hold' => OrderHoldReason::Paused, 'actions' => [SupplierAction::Resume]],
        'entered' => ['status' => OrderStatus::InProgress, 'hold' => null, 'actions' => []],
        'started' => ['status' => OrderStatus::InProgress, 'hold' => null, 'actions' => []],
        'ready' => ['status' => OrderStatus::InProgress, 'hold' => null, 'actions' => []],
        'waiting' => ['status' => OrderStatus::InProgress, 'hold' => null, 'actions' => []],
        'waitingforassignment' => ['status' => OrderStatus::InProgress, 'hold' => null, 'actions' => []],
        'transfersinprogress' => ['status' => OrderStatus::InProgress, 'hold' => null, 'actions' => []],
    ],
];
