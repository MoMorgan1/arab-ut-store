<?php

namespace App\Suppliers\Translation;

use App\Enums\ChallengeState;
use App\Enums\DeliveryPhase;
use App\Enums\HoldTone;
use App\Enums\OrderHoldReason;
use App\Enums\OrderStatus;
use App\Enums\Supplier;
use App\Enums\SupplierAction;
use App\Enums\TrackingPresentation;
use App\Suppliers\ChallengeIds;
use App\Suppliers\RawSupplierObservation;

/**
 * Turns one supplier observation into the one canonical state the store keeps.
 *
 * This is a port of the tracker's classification tables: errorMap,
 * economyStateMap, customerActionAccountChecks, customerActionEconomyStates,
 * systemInfoEconomyStates, showEditStates and showResumeStates in
 * track/assets/js/ui.js, mirrored in track/includes/functions.php. The tracker
 * renders Arabic; this layer only decides status, hold reason and allowed
 * actions, so it never emits a string a customer will read.
 *
 * Two rules keep it safe. Unknown supplier codes never change the state a
 * customer already sees: the observation is marked unsupported and the current
 * status wins. And an action is only offered when a code that justifies it is
 * one this class actually knows.
 *
 * The two axes are independent. The hold reason decides the STATUS: a reason in
 * AUTOMATIC_RECOVERY_REASONS means the order is not blocked on the customer, so
 * it stays InProgress. The ACTIONS come from the tracker's showEditStates and
 * showResumeStates lists, because those answer a different question: what the
 * customer can usefully press. A Resume next to an automatic wait means "try
 * now instead of waiting for the sweep", which the tracker's own texts ask for.
 */
final class SupplierStateTranslator
{
    /**
     * Hold reasons the system recovers from on its own. They decide the status
     * only: an order holding on one of these is not blocked on the customer, so
     * it stays InProgress. Actions stay independent, coming purely from the
     * tracker's action lists.
     *
     * @var list<OrderHoldReason>
     */
    private const array AUTOMATIC_RECOVERY_REASONS = [
        OrderHoldReason::EaServers,
        OrderHoldReason::StoreStock,
        OrderHoldReason::Connection,
        OrderHoldReason::NoPlayer,
        OrderHoldReason::Maintenance,
        OrderHoldReason::Paused,
        OrderHoldReason::BelowMinimum,
    ];

    /**
     * Hold reasons where the order cannot continue until the customer does
     * something. The matching lang/ar/orders.php texts ask for new backup
     * codes, corrected credentials, a cleared transfer list, and so on.
     *
     * @var list<OrderHoldReason>
     */
    private const array CUSTOMER_ACTION_REASONS = [
        OrderHoldReason::BackupCodes,
        OrderHoldReason::Credentials,
        OrderHoldReason::Platform,
        OrderHoldReason::MarketLocked,
        OrderHoldReason::InsufficientCoins,
        OrderHoldReason::ActiveSession,
        OrderHoldReason::NoClub,
        OrderHoldReason::TransferListFull,
        OrderHoldReason::Captcha,
        OrderHoldReason::Unassigned,
        OrderHoldReason::AccountBanned,
    ];

    /**
     * accountCheck codes that hold the job. The reason decides whether the
     * order waits on the customer; see the two sets above.
     *
     * @var array<string, OrderHoldReason>
     */
    private const array ACCOUNT_CHECK_HOLDS = [
        'wrongBA' => OrderHoldReason::BackupCodes,
        'wrongUserPass' => OrderHoldReason::Credentials,
        'wrongConsole' => OrderHoldReason::Platform,
        'noTM' => OrderHoldReason::MarketLocked,
        'notEnoughCoins' => OrderHoldReason::InsufficientCoins, // the customer's coin balance
        'active session' => OrderHoldReason::ActiveSession,
        'loginFailed' => OrderHoldReason::EaServers, // EA server pressure, not the customer's fault (ui.js:44-45)
        'noClub' => OrderHoldReason::NoClub,
        'tlFull' => OrderHoldReason::TransferListFull,
        'captcha' => OrderHoldReason::Captcha,
        'wrongPersona' => OrderHoldReason::Credentials,
        'console' => OrderHoldReason::ActiveSession,
        'consoleLoggedIn' => OrderHoldReason::ActiveSession,
        'unassignedItemsPresent' => OrderHoldReason::Unassigned,
        'LoginFailedDeviceBan' => OrderHoldReason::AccountBanned,
        'insufficientFunds' => OrderHoldReason::StoreStock, // OUR float at the supplier, not the customer's coins
        '2FADisabled' => OrderHoldReason::Credentials,
        'no2fa' => OrderHoldReason::Credentials,
        'No2FA' => OrderHoldReason::Credentials,
    ];

    /**
     * economyState codes that hold the job, whatever the reason's class. The
     * reason alone decides InProgress versus WaitingForCustomer, so customer
     * and system codes live in one map; a reason in AUTOMATIC_RECOVERY_REASONS
     * keeps the order InProgress and asks the customer for nothing.
     *
     * Every mapped code names a reason. `belowMinTransfer` was the one exception
     * until 2026-09-13, and it cost the customer the explanation: the amber box
     * is decided by the code while its text comes from the reason, so a code with
     * no reason drew a box with nothing in it.
     *
     * @var array<string, OrderHoldReason>
     */
    private const array ECONOMY_STATE_HOLDS = [
        'FailedWrongCredentialsTo' => OrderHoldReason::Credentials,
        'FailedWrongBACodeTo' => OrderHoldReason::BackupCodes,
        'FailLoggedInConsoleTo' => OrderHoldReason::ActiveSession,
        'FailNoClubToCanBeEAErrorTryAgain' => OrderHoldReason::NoClub,
        'FailWebAppCustomerLocked' => OrderHoldReason::AccountBanned,
        'FailedReceiverDeviceBan' => OrderHoldReason::AccountBanned,
        'FailedSessionExpiredCustomerLoggedIn?' => OrderHoldReason::ActiveSession,
        'FailedTLfullReceiver' => OrderHoldReason::TransferListFull,
        'insufficientFunds' => OrderHoldReason::StoreStock, // OUR float at the supplier, not the customer's coins
        'FailWebAppNotYetUnlocked' => OrderHoldReason::Credentials,
        'tempbanCooldown' => OrderHoldReason::Paused,
        'listingTempban' => OrderHoldReason::Paused, // system-side ban, nothing for the customer to do
        'dailyReceiverLimit' => OrderHoldReason::Paused,
        'calcErrorMaintenance' => OrderHoldReason::Maintenance,
        'FailedProxyConnectionError' => OrderHoldReason::Connection,
        'FailProxyUnavailable' => OrderHoldReason::Connection,
        'noSuitableSender' => OrderHoldReason::NoPlayer,
        'noPlayer' => OrderHoldReason::NoPlayer,
        'belowMinTransfer' => OrderHoldReason::BelowMinimum, // the system is finishing the order itself
    ];

    /**
     * economyState value the tracker pauses without naming a hold: on its own
     * it keeps the order moving with no action, but beside a stopped status it
     * waits on the customer. Named so every code comparison in this class is
     * exact against a constant.
     */
    private const string ECONOMY_STATE_DEACTIVATED = 'deactivated';

    /**
     * accountCheck codes that describe healthy progress, never a hold.
     *
     * @var list<string>
     */
    private const array SAFE_ACCOUNT_CHECKS = [
        'entered',
        'started',
        'userPassVerified',
        'correctBA',
        'noUnassignedItemsPresent',
    ];

    /**
     * economyState codes that describe healthy progress, never a hold.
     *
     * @var list<string>
     */
    private const array SAFE_ECONOMY_STATES = [
        'transfersInProgress',
        'transferCycleComplete',
        'customerHasPlayer',
        'customerListedPlayer',
        'FailedProxyPoolExhausted',
    ];

    /**
     * status values that describe healthy progress, never a hold.
     *
     * @var list<string>
     */
    private const array SAFE_STATUSES = [
        'entered',
        'started',
        'ready',
        'waiting',
        'waitingforassignment',
        'transfersinprogress',
    ];

    /**
     * status values that mean the order finished, transcribed exactly from
     * track/STATUS_MAPPING_CURRENT.md: 'finished' and 'completed'. Matching is
     * exact on purpose: the tracker tests for the substrings 'finish' and
     * 'complet', so a status like 'unfinished' would complete a live order
     * past the unknown-code guard. Everything not listed here is unknown and
     * fails closed in firstUnknownCode().
     *
     * @var list<string>
     */
    private const array FINISHED_STATUSES = [
        'finished',
        'completed',
    ];

    /**
     * status values that mean the order is stopped and shows Resume, matched
     * exactly like FINISHED_STATUSES. track/STATUS_MAPPING_CURRENT.md item 8
     * names 'interrupted', 'stopped' and 'abort', but the running tracker tests
     * the substrings 'stop', 'abort' and 'interrupted'
     * (track/assets/js/ui.js:413-414, track/includes/functions.php:97), which
     * also catches the inflected values 'stopping' and 'aborted'. This list
     * follows the JavaScript: those five are the concrete stopped codes.
     * Anything else, like 'unstoppable', is unknown and fails closed in
     * firstUnknownCode().
     *
     * @var list<string>
     */
    private const array STOPPED_STATUSES = [
        'interrupted',
        'stopped',
        'stopping',
        'abort',
        'aborted',
    ];

    /**
     * Union of showEditStates in ui.js.
     *
     * @var list<string>
     */
    private const array EDIT_STATES = [
        'wrongBA',
        'wrongUserPass',
        'wrongConsole',
        'noTM',
        'noClub',
        'wrongPersona',
        'LoginFailedDeviceBan',
        'FailedWrongCredentialsTo',
        'FailedWrongBACodeTo',
        'FailWebAppCustomerLocked',
        'FailNoClubToCanBeEAErrorTryAgain',
        'FailedReceiverDeviceBan',
        'FailWebAppNotYetUnlocked',
    ];

    /**
     * Union of showResumeStates in ui.js. Automatic-recovery codes keep their
     * Resume here: the button means "try now instead of waiting for the sweep".
     *
     * @var list<string>
     */
    private const array RESUME_STATES = [
        'noTM',
        'noClub',
        'tlFull',
        'notEnoughCoins',
        'console',
        'consoleLoggedIn',
        'active session',
        'captcha',
        'wrongPersona',
        'unassignedItemsPresent',
        'LoginFailedDeviceBan',
        'loginFailed',
        'insufficientFunds',
        '2FADisabled',
        'no2fa',
        'No2FA',
        'FailLoggedInConsoleTo',
        'FailWebAppCustomerLocked',
        'FailNoClubToCanBeEAErrorTryAgain',
        'FailedReceiverDeviceBan',
        'FailedSessionExpiredCustomerLoggedIn?',
        'FailedTLfullReceiver',
        'FailWebAppNotYetUnlocked',
        'dailyReceiverLimit',
        'calcErrorMaintenance',
        'FailedProxyConnectionError',
        'FailProxyUnavailable',
        'noPlayer',
        'noSuitableSender',
        'belowMinTransfer',
    ];

    /**
     * AccountCheck values that require customer action (ui.js:269-274).
     *
     * @var list<string>
     */
    public const array CUSTOMER_ACTION_ACCOUNT_CHECKS = [
        'wrongBA', 'wrongUserPass', 'wrongConsole', 'noTM', 'noClub',
        'tlFull', 'notEnoughCoins', 'console', 'consoleLoggedIn', 'active session', 'captcha',
        'wrongPersona', 'unassignedItemsPresent', 'LoginFailedDeviceBan',
        'loginFailed', 'insufficientFunds', '2FADisabled', 'no2fa', 'No2FA',
    ];

    /**
     * EconomyState values that require customer action (ui.js:277-283).
     *
     * @var list<string>
     */
    public const array CUSTOMER_ACTION_ECONOMY_STATES = [
        'FailedWrongCredentialsTo', 'FailedWrongBACodeTo',
        'FailWebAppCustomerLocked', 'FailLoggedInConsoleTo',
        'insufficientFunds', 'FailNoClubToCanBeEAErrorTryAgain',
        'FailedReceiverDeviceBan', 'FailedSessionExpiredCustomerLoggedIn?',
        'FailedTLfullReceiver', 'FailWebAppNotYetUnlocked',
    ];

    /**
     * EconomyState values that are system-side (auto-retry, yellow info box) (ui.js:286-290).
     *
     * @var list<string>
     */
    public const array SYSTEM_INFO_ECONOMY_STATES = [
        'tempbanCooldown', 'listingTempban', 'dailyReceiverLimit', 'calcErrorMaintenance',
        'FailedProxyConnectionError', 'FailProxyUnavailable',
        'noPlayer', 'noSuitableSender', 'belowMinTransfer',
    ];

    /**
     * SBC failure statuses that are system-side informational waits rather than customer action.
     *
     * @var list<string>
     */
    public const array SBC_SYSTEM_INFO_STATUSES = [
        'tempban',
        'TempbanCooldown',
        'dailyReceiverLimit',
        'FailProxyConn',
        'FailedProxyConnectionError',
        'FailProxy',
    ];

    /**
     * SBC statuses that mean the challenge is complete, matching track/assets/js/ui.js:967-970, 999.
     * Matching is exact on purpose; substring matching would incorrectly match 'unfinished'.
     *
     * @var list<string>
     */
    private const array SBC_FINISHED_STATUSES = [
        'finished',
        'alreadyCompleted',
    ];

    /**
     * SBC statuses describing active healthy progress (track/assets/js/ui.js:1030).
     *
     * @var list<string>
     */
    private const array SBC_SAFE_STATUSES = [
        'entered',
        'waitingForOtherSolve',
        'started',
        'fetchSBCInfo',
        'fetchChallengeInfo',
        'solvingChallenge',
    ];

    /**
     * SBC failure statuses mapped to their canonical hold reasons.
     * Failure statuses in CUSTOMER_ACTION_REASONS transition the order to WaitingForCustomer;
     * failure statuses in AUTOMATIC_RECOVERY_REASONS keep the order InProgress.
     * If a status has no honest match in OrderHoldReason, it maps to null and stays InProgress.
     *
     * @var array<string, OrderHoldReason|null>
     */
    public const array SBC_STATUS_HOLDS = [
        // Auth / session errors
        'WrongUserPass' => OrderHoldReason::Credentials,
        'WrongBA' => OrderHoldReason::BackupCodes,
        'sessionExpired' => OrderHoldReason::EaServers,
        'needEmailConfirm' => OrderHoldReason::Credentials,
        'LoginFailed495' => OrderHoldReason::EaServers,
        'LoginFailed401' => OrderHoldReason::EaServers,
        'LoginFailedDeviceBan' => OrderHoldReason::AccountBanned,
        'LoginError' => OrderHoldReason::EaServers,
        'LoginFailed' => OrderHoldReason::EaServers,
        'loginFailed' => OrderHoldReason::EaServers,
        '2FADisabled' => OrderHoldReason::Credentials,
        'no2fa' => OrderHoldReason::Credentials,
        'No2FA' => OrderHoldReason::Credentials,
        'loginLoop' => OrderHoldReason::EaServers,

        // Proxy / connection errors
        'FailProxyConn' => OrderHoldReason::Connection,
        'FailedProxyConnectionError' => OrderHoldReason::Connection,
        'FailProxy' => OrderHoldReason::Connection,

        // Account / setup errors
        'failedNoClub' => OrderHoldReason::NoClub,
        'consoleLoggedIn' => OrderHoldReason::ActiveSession,
        'FailedPersonaSwitch' => OrderHoldReason::Credentials,
        'TMLocked' => OrderHoldReason::MarketLocked,

        // SBC-specific errors (null when no honest enum match exists)
        'setNotFound' => null,
        'foundationNotSolved' => null,
        'challengeDataMissing' => null,
        'noSolutionFound' => OrderHoldReason::NoPlayer,
        'tooExpensive' => null,
        'clickFailed' => null,
        'submitFailed' => OrderHoldReason::EaServers,
        'squadCreateFailed' => null,

        // Player / market errors
        'playerBuyFailed' => null,
        'playerNotFound' => OrderHoldReason::NoPlayer,
        'playerNotMoved' => null,
        'clubQueryFailed' => OrderHoldReason::EaServers,
        'tooManyExchanges' => OrderHoldReason::Paused,
        'noPriceFound' => OrderHoldReason::NoPlayer,

        // Financial / Cooldown errors
        'noFunds' => OrderHoldReason::StoreStock,
        'OutOfCoins' => OrderHoldReason::InsufficientCoins,
        'tempban' => OrderHoldReason::Paused,
        'TempbanCooldown' => OrderHoldReason::Paused,
        'dailyReceiverLimit' => OrderHoldReason::Paused,

        // System errors
        'aborted' => OrderHoldReason::Paused,
        'failed' => null,
        'FailUnassignedFound' => OrderHoldReason::Unassigned,
    ];

    /**
     * Curation of supplier sbcStatus codes into ChallengeState cases.
     *
     * Placed beside SBC_STATUS_HOLDS so both tables are maintained and read together.
     * Internal diagnostic codes (401, 495, loop, etc.) are grouped into SignInFailed,
     * adhering to the owner's rule that customers never read about our plumbing.
     *
     * @var array<string, ChallengeState>
     */
    public const array SBC_STATUS_CHALLENGE_STATES = [
        // Progress statuses
        'entered' => ChallengeState::Queued,
        'waitingForOtherSolve' => ChallengeState::WaitingPreviousSolve,
        'started' => ChallengeState::Started,
        'fetchSBCInfo' => ChallengeState::FetchingChallenge,
        'fetchChallengeInfo' => ChallengeState::FetchingSquads,
        'solvingChallenge' => ChallengeState::Solving,
        'finished' => ChallengeState::Done,
        'alreadyCompleted' => ChallengeState::Done,

        // Auth / session errors
        'sessionExpired' => ChallengeState::SessionExpired,
        'needEmailConfirm' => ChallengeState::SignInFailed,
        'LoginFailed495' => ChallengeState::SignInFailed,
        'LoginFailed401' => ChallengeState::SignInFailed,
        'LoginFailedDeviceBan' => ChallengeState::SignInFailed,
        'LoginError' => ChallengeState::SignInFailed,
        'LoginFailed' => ChallengeState::SignInFailed,
        'loginFailed' => ChallengeState::SignInFailed,
        'WrongUserPass' => ChallengeState::SignInFailed,
        '2FADisabled' => ChallengeState::SignInFailed,
        'no2fa' => ChallengeState::SignInFailed,
        'No2FA' => ChallengeState::SignInFailed,
        'WrongBA' => ChallengeState::SignInFailed,
        'loginLoop' => ChallengeState::SignInFailed,

        // Proxy / connection errors
        'FailProxyConn' => ChallengeState::Failed,
        'FailedProxyConnectionError' => ChallengeState::Failed,
        'FailProxy' => ChallengeState::Failed,

        // Account / setup errors
        'failedNoClub' => ChallengeState::Failed,
        'consoleLoggedIn' => ChallengeState::Failed,
        'FailedPersonaSwitch' => ChallengeState::Failed,
        'TMLocked' => ChallengeState::Failed,

        // SBC-specific errors
        'setNotFound' => ChallengeState::Failed,
        'foundationNotSolved' => ChallengeState::Failed,
        'challengeDataMissing' => ChallengeState::Failed,
        'noSolutionFound' => ChallengeState::Failed,
        'tooExpensive' => ChallengeState::Failed,
        'clickFailed' => ChallengeState::Failed,
        'submitFailed' => ChallengeState::Failed,
        'squadCreateFailed' => ChallengeState::Failed,

        // Player / market errors
        'playerBuyFailed' => ChallengeState::Failed,
        'playerNotFound' => ChallengeState::Failed,
        'playerNotMoved' => ChallengeState::Failed,
        'clubQueryFailed' => ChallengeState::Failed,
        'tooManyExchanges' => ChallengeState::Failed,
        'noPriceFound' => ChallengeState::Failed,

        // Financial / Cooldown errors
        'noFunds' => ChallengeState::Failed,
        'OutOfCoins' => ChallengeState::Failed,
        'tempban' => ChallengeState::Failed,
        'TempbanCooldown' => ChallengeState::Failed,
        'dailyReceiverLimit' => ChallengeState::Failed,

        // System errors
        'aborted' => ChallengeState::Failed,
        'failed' => ChallengeState::Failed,
        'FailUnassignedFound' => ChallengeState::Failed,
    ];

    /**
     * SBC statuses that offer retry in the tracker (assets/js/ui.js:1012-1020).
     * Maps to SupplierAction::RetryChallenge.
     *
     * @var list<string>
     */
    private const array SBC_RETRYABLE_STATUSES = [
        'noSolutionFound',
        'tooExpensive',
        'clickFailed',
        'submitFailed',
        'squadCreateFailed',
        'playerBuyFailed',
        'playerNotFound',
        'playerNotMoved',
        'clubQueryFailed',
        'tooManyExchanges',
        'noFunds',
        'OutOfCoins',
        'failed',
        'aborted',
        'FailUnassignedFound',
        'setNotFound',
        'foundationNotSolved',
        'challengeDataMissing',
        'tempban',
        'TempbanCooldown',
        'WrongUserPass',
        'WrongBA',
        'consoleLoggedIn',
        'sessionExpired',
        'FailProxyConn',
        'LoginError',
        'LoginFailed',
        'LoginFailed401',
        'LoginFailed495',
        'LoginFailedDeviceBan',
        'noPriceFound',
        // Added with the owner's 2026-09-13 decision: every login failure offers a retry,
        // so the tracker's two unretryable spellings of one failure stop disagreeing.
        'loginFailed',
        'loginLoop',
        'needEmailConfirm',
    ];

    /**
     * SBC statuses that offer credential editing.
     *
     * The tracker allows exactly two, WrongUserPass and WrongBA (assets/js/ui.js:1070),
     * and its own API enforces that gate (includes/api-handlers.php:2106). That gate is
     * the tracker's product choice rather than a supplier constraint: the comment beside
     * it says retrySBCAPI "does not reliably gate on status upstream", so FFT accepts a
     * credential correction whatever the status.
     *
     * Owner decision, 2026-09-13, widening it to the login-failure family: a failed login
     * "could be a problem with the account or it could be the servers", so the customer
     * gets both a retry and a way to correct the email they may have typed wrong. His
     * words: "خليه فيه اوبشن انه يعمل اعادة تشغيل او انه يغير الايميل لو حط ايميل غلط".
     * The order stays InProgress meanwhile, because it may well be EA's side.
     *
     * needEmailConfirm is mine rather than his, extended on the same reasoning: it is an
     * email problem and correcting the email is the only thing that can resolve it.
     *
     * @var list<string>
     */
    private const array SBC_EDITABLE_STATUSES = [
        'WrongUserPass',
        'WrongBA',
        // The login-failure family, per the owner's 2026-09-13 decision above.
        'LoginFailed',
        'loginFailed',
        'LoginFailed401',
        'LoginFailed495',
        'LoginError',
        'loginLoop',
        'LoginFailedDeviceBan',
        'needEmailConfirm',
    ];

    public function translate(
        RawSupplierObservation $observation,
        OrderStatus $current,
        ?DeliveryPhase $phase,
    ): TranslatedState {
        $payload = $observation->payload;
        $progress = $this->progress($payload);

        $statusRaw = $payload['status'] ?? null;
        $accountCheckRaw = $payload['accountCheck'] ?? null;
        $economyStateRaw = $payload['economyState'] ?? null;

        if ((! is_string($statusRaw) && $statusRaw !== null)
            || (! is_string($accountCheckRaw) && $accountCheckRaw !== null)
            || (! is_string($economyStateRaw) && $economyStateRaw !== null)
        ) {
            return $this->unsupported($current, null, $progress);
        }

        $status = strtolower(trim($statusRaw ?? ''));
        $accountCheck = $accountCheckRaw ?? '';
        $economyState = $economyStateRaw ?? '';

        if ($status === '' && $accountCheck === '' && $economyState === '') {
            return $this->unsupported($current, null, $progress);
        }

        // Validation comes before completion: a finished-looking status must
        // never carry an unknown code past the guard into Completed.
        $unknown = $this->firstUnknownCode($status, $accountCheck, $economyState);

        if ($unknown !== null) {
            return $this->unsupported($current, $unknown, $progress);
        }

        $simplifiedRaw = $payload['simplifiedStatus'] ?? null;

        $resolved = $this->resolve(
            $observation->supplier,
            $status,
            $accountCheck,
            $economyState,
            $phase,
            $progress['coinsDelivered'],
            $progress['coinsOrdered'],
            is_string($simplifiedRaw) ? $simplifiedRaw : '',
        );

        if ($this->isTerminal($current)) {
            $terminalPresentation = match ($current) {
                OrderStatus::Completed => TrackingPresentation::Completed,
                OrderStatus::Cancelled => TrackingPresentation::Cancelled,
                OrderStatus::Refunded => TrackingPresentation::Refunded,
                default => TrackingPresentation::Stopped,
            };

            return $this->state(
                $current,
                null,
                [],
                $resolved->supported,
                $resolved->observedState,
                $progress,
                null,
                $terminalPresentation,
            );
        }

        return $this->state(
            $resolved->status,
            $resolved->holdReason,
            $resolved->allowedActions,
            $resolved->supported,
            $resolved->observedState,
            $progress,
            $resolved->holdTone,
            $resolved->presentation,
        );
    }

    /**
     * Translates an SBC / challenge status observation into canonical domain state.
     *
     * A challenge observation uses an entirely separate vocabulary from coins tracking;
     * keeping its own entry point prevents either path from accepting the other's codes.
     *
     * @param  list<string>  $requestedIds
     * @param  array<array-key, mixed>  $bulk  Decoded supplier JSON: an entry can be
     *                                         anything, and a numeric key arrives as an int.
     */
    public function translateChallenge(
        Supplier $supplier,
        array $requestedIds,
        array $bulk,
        OrderStatus $current,
    ): TranslatedState {
        $normalizedRequested = [];
        foreach ($requestedIds as $reqId) {
            $norm = $this->normaliseChallengeId((string) $reqId);
            if ($norm !== null && ! in_array($norm, $normalizedRequested, true)) {
                $normalizedRequested[] = $norm;
            }
        }

        // Two response keys can normalise to one id - a bare uuid and the same uuid with
        // an SBC- prefix or a different case. Letting the later one win meant a finished
        // alias could erase an unfinished observation and complete the order, so a
        // collision is treated as a response we cannot trust rather than a merge.
        $returned = [];
        $collided = false;

        foreach ($bulk as $key => $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $norm = $this->singleChallengeId((string) $key);

            if ($norm === null || ! in_array($norm, $normalizedRequested, true)) {
                continue;
            }

            if (array_key_exists($norm, $returned)) {
                $collided = true;
                break;
            }

            $returned[$norm] = $entry;
        }

        if ($collided) {
            return new TranslatedState(
                status: $current,
                holdReason: null,
                allowedActions: [],
                supported: false,
                observedState: null,
                coinsDelivered: null,
                coinsOrdered: null,
                squadsDone: null,
                squadsTotal: null,
                solvesDone: null,
                solvesTotal: null,
                holdTone: null,
                presentation: null,
            );
        }

        // 1. Nothing came back for any requested id. Fail closed: supported: false,
        // no actions, $current unchanged, all counters null. An empty answer is not zero progress.
        if ($returned === []) {
            return new TranslatedState(
                status: $current,
                holdReason: null,
                allowedActions: [],
                supported: false,
                observedState: null,
                coinsDelivered: null,
                coinsOrdered: null,
                squadsDone: null,
                squadsTotal: null,
                solvesDone: null,
                solvesTotal: null,
                holdTone: null,
                presentation: null,
            );
        }

        // 2. Pick the challenge being worked now: the first returned entry that is not finished,
        // falling back to the last returned entry when all of them are.
        $activeChallenge = null;
        $lastReturned = null;

        foreach ($returned as $entry) {
            $lastReturned = $entry;
            $rawStatus = $entry['sbcStatus'] ?? null;
            $status = is_string($rawStatus) ? trim($rawStatus) : '';

            if (! $this->isSbcFinished($status)) {
                $activeChallenge = $entry;
                break;
            }
        }

        $activeChallenge ??= $lastReturned;

        // Check the type before using rather than casting
        $activeRawStatus = $activeChallenge['sbcStatus'] ?? null;
        $activeStatus = is_string($activeRawStatus) ? trim($activeRawStatus) : '';

        // 4. Counters come from the active entry
        $squadsDone = $this->count($activeChallenge['challengesDone'] ?? $activeChallenge['squadsDone'] ?? null);
        $squadsTotal = $this->count($activeChallenge['totalChallenges'] ?? $activeChallenge['squadsTotal'] ?? null);
        $solvesDone = $this->count($activeChallenge['timesSolved'] ?? $activeChallenge['solvesDone'] ?? null);
        $solvesTotal = $this->count($activeChallenge['timesToSolve'] ?? $activeChallenge['solvesTotal'] ?? null);

        // Validation comes first: an unknown or empty challenge status on the active entry fails closed immediately
        if ($activeStatus === '' || ! $this->isKnownSbcStatus($activeStatus)) {
            return new TranslatedState(
                status: $current,
                holdReason: null,
                allowedActions: [],
                supported: false,
                observedState: $activeStatus === '' ? null : $activeStatus,
                coinsDelivered: null,
                coinsOrdered: null,
                squadsDone: $squadsDone,
                squadsTotal: $squadsTotal,
                solvesDone: $solvesDone,
                solvesTotal: $solvesTotal,
                holdTone: null,
                presentation: null,
            );
        }

        if ($this->isTerminal($current)) {
            $terminalPresentation = match ($current) {
                OrderStatus::Completed => TrackingPresentation::Completed,
                OrderStatus::Cancelled => TrackingPresentation::Cancelled,
                OrderStatus::Refunded => TrackingPresentation::Refunded,
                default => TrackingPresentation::Stopped,
            };

            return new TranslatedState(
                status: $current,
                holdReason: null,
                allowedActions: [],
                supported: true,
                observedState: $activeStatus,
                coinsDelivered: null,
                coinsOrdered: null,
                squadsDone: $squadsDone,
                squadsTotal: $squadsTotal,
                solvesDone: $solvesDone,
                solvesTotal: $solvesTotal,
                holdTone: null,
                presentation: $terminalPresentation,
            );
        }

        // 3. Completion requires every requested id to be present and finished.
        // If any requested id is missing from the response, the status is InProgress no matter what
        // the returned entries say. If any requested challenge is missing from FFT's bulk response,
        // it may still be running at the supplier; completing the order prematurely would pay out
        // cashback and invite reviews on an incomplete order.
        $allRequestedPresent = true;
        foreach ($normalizedRequested as $reqId) {
            if (! isset($returned[$reqId])) {
                $allRequestedPresent = false;
                break;
            }
        }

        $allReturnedFinished = true;
        foreach ($returned as $entry) {
            $entryRawStatus = $entry['sbcStatus'] ?? null;
            $entryStatus = is_string($entryRawStatus) ? trim($entryRawStatus) : '';
            if (! $this->isSbcFinished($entryStatus)) {
                $allReturnedFinished = false;
                break;
            }
        }

        $everyRequestedPresentAndFinished = $allRequestedPresent && $allReturnedFinished;

        if ($everyRequestedPresentAndFinished) {
            return new TranslatedState(
                status: OrderStatus::Completed,
                holdReason: null,
                allowedActions: [],
                supported: true,
                observedState: $activeStatus,
                coinsDelivered: null,
                coinsOrdered: null,
                squadsDone: $squadsDone,
                squadsTotal: $squadsTotal,
                solvesDone: $solvesDone,
                solvesTotal: $solvesTotal,
                holdTone: null,
                presentation: TrackingPresentation::Completed,
            );
        }

        // If the active returned entry looks finished but not all requested challenges are present
        // and finished, the order must remain InProgress.
        if ($this->isSbcFinished($activeStatus)) {
            return new TranslatedState(
                status: OrderStatus::InProgress,
                holdReason: null,
                allowedActions: [],
                supported: true,
                observedState: $activeStatus,
                coinsDelivered: null,
                coinsOrdered: null,
                squadsDone: $squadsDone,
                squadsTotal: $squadsTotal,
                solvesDone: $solvesDone,
                solvesTotal: $solvesTotal,
                holdTone: null,
                presentation: TrackingPresentation::Processing,
            );
        }

        if ($this->isSbcSafe($activeStatus)) {
            return new TranslatedState(
                status: OrderStatus::InProgress,
                holdReason: null,
                allowedActions: [],
                supported: true,
                observedState: $activeStatus,
                coinsDelivered: null,
                coinsOrdered: null,
                squadsDone: $squadsDone,
                squadsTotal: $squadsTotal,
                solvesDone: $solvesDone,
                solvesTotal: $solvesTotal,
                holdTone: null,
                presentation: TrackingPresentation::Processing,
            );
        }

        // Failure status: customer action reasons move order to WaitingForCustomer;
        // automatic recovery reasons keep it InProgress. If no honest match exists,
        // the reason stays null and the order stays InProgress.
        $holdReason = self::SBC_STATUS_HOLDS[$activeStatus] ?? null;
        $orderStatus = ($holdReason !== null && $this->isCustomerAction($holdReason))
            ? OrderStatus::WaitingForCustomer
            : OrderStatus::InProgress;

        $actions = $this->sbcActions($supplier, $activeStatus);

        $isInfo = in_array($activeStatus, self::SBC_SYSTEM_INFO_STATUSES, true);
        $holdTone = $isInfo ? HoldTone::Info : HoldTone::Action;
        $presentation = $isInfo ? TrackingPresentation::Processing : TrackingPresentation::NeedsReview;

        return new TranslatedState(
            status: $orderStatus,
            holdReason: $holdReason,
            allowedActions: $actions,
            supported: true,
            observedState: $activeStatus,
            coinsDelivered: null,
            coinsOrdered: null,
            squadsDone: $squadsDone,
            squadsTotal: $squadsTotal,
            solvesDone: $solvesDone,
            solvesTotal: $solvesTotal,
            holdTone: $holdTone,
            presentation: $presentation,
        );
    }

    /**
     * Whether the bulk response answered for at least one challenge we asked about.
     *
     * The caller needs this before applying anything: a response that names none of our
     * ids told us nothing, and storing "we learned nothing" over "here is what we last
     * learned" is a straight loss. It lives here so the id comparison has exactly one
     * implementation, shared with translateChallenge().
     *
     * @param  list<string>  $requestedIds
     * @param  array<array-key, mixed>  $bulk
     */
    public function challengeResponseAnswersRequest(array $requestedIds, array $bulk): bool
    {
        $requested = [];
        foreach ($requestedIds as $id) {
            $norm = $this->normaliseChallengeId((string) $id);
            if ($norm !== null) {
                $requested[$norm] = true;
            }
        }

        foreach (array_keys($bulk) as $key) {
            $norm = $this->normaliseChallengeId((string) $key);
            if ($norm !== null && isset($requested[$norm])) {
                return true;
            }
        }

        return false;
    }

    /**
     * The single challenge id a response key names, or null when it names none or several.
     *
     * A response key must stand for exactly one challenge. The permissive parse accepts a
     * comma or space separated list, so "uuid-a,uuid-b" would otherwise resolve to uuid-a
     * and let one entry answer for a challenge it says nothing about.
     */
    private function singleChallengeId(string $key): ?string
    {
        $tokens = ChallengeIds::parse([$key]);

        if (count($tokens) !== 1) {
            return null;
        }

        return $this->normaliseChallengeId($key);
    }

    private function isAutomaticRecovery(OrderHoldReason $reason): bool
    {
        return in_array($reason, self::AUTOMATIC_RECOVERY_REASONS, true);
    }

    private function isCustomerAction(OrderHoldReason $reason): bool
    {
        return in_array($reason, self::CUSTOMER_ACTION_REASONS, true);
    }

    private function normaliseChallengeId(string $id): ?string
    {
        $normalized = ChallengeIds::normalize([$id]);
        if ($normalized !== []) {
            return $normalized[0];
        }

        $parsed = ChallengeIds::parse([$id]);

        return $parsed[0] ?? null;
    }

    public static function challengeState(string $sbcStatus): ChallengeState
    {
        return self::SBC_STATUS_CHALLENGE_STATES[$sbcStatus] ?? ChallengeState::Unknown;
    }

    /**
     * @return list<SupplierAction>
     */
    public static function sbcChallengeActions(?Supplier $supplier, string $sbcStatus): array
    {
        if ($supplier !== null && ! $supplier->handlesChallenges()) {
            return [];
        }

        $actions = [];

        if (in_array($sbcStatus, self::SBC_EDITABLE_STATUSES, true)) {
            $actions[] = SupplierAction::EditCredentials;
        }

        if (in_array($sbcStatus, self::SBC_RETRYABLE_STATUSES, true)) {
            $actions[] = SupplierAction::RetryChallenge;
        }

        return $actions;
    }

    public function resolveHoldTone(string $status, string $accountCheck, string $economyState): ?HoldTone
    {
        // The tracker's comment at ui.js:292 ("deactivated = completely hidden from customer")
        // overstates its own implementation: in ui.js code, deactivated is merely absent from
        // the error and info maps, and getActionMessage() evaluates accountCheck first (ui.js:300).
        // Furthermore, showActionBox (ui.js:770) triggers if hasAction || isStopped || isInfoBox || isCriticalAccountError.
        // Therefore deactivated suppresses nothing on its own beside an account error or a stopped status.
        if ($this->in(self::CUSTOMER_ACTION_ACCOUNT_CHECKS, $accountCheck)) {
            return HoldTone::Action;
        }

        if ($this->in(self::CUSTOMER_ACTION_ECONOMY_STATES, $economyState)) {
            return HoldTone::Action;
        }

        if ($this->in(self::SYSTEM_INFO_ECONOMY_STATES, $economyState)) {
            return HoldTone::Info;
        }

        if ($this->isStopped($status)) {
            return HoldTone::Action;
        }

        return null;
    }

    public function resolvePresentation(
        string $status,
        string $accountCheck,
        string $economyState,
        ?int $coinsDelivered = null,
        ?int $coinsOrdered = null,
        string $simplifiedStatus = '',
    ): TrackingPresentation {
        $isStopped = $this->isStopped($status);
        $isFinished = $this->isFinished($status);

        $hasCustomerAction = $this->in(self::CUSTOMER_ACTION_ACCOUNT_CHECKS, $accountCheck)
            || $this->in(self::CUSTOMER_ACTION_ECONOMY_STATES, $economyState);

        // The tracker's branches guard on `!actualActionMsg`, and a message exists for a
        // system-info code too - getActionMessage() returns one with an `__info_box__:`
        // prefix rather than returning nothing (ui.js:310-313). Guarding only on
        // customer-action codes let an order with `accountCheck: entered` and
        // `economyState: noPlayer` read "جاري تسجيل الدخول" with an amber box underneath
        // saying we cannot find a player - a headline contradicting its own reason.
        $hasMessage = $hasCustomerAction || $this->in(self::SYSTEM_INFO_ECONOMY_STATES, $economyState);

        // 1. Tempban/system cooldown states (ui.js:425-442)
        if ($economyState === 'tempbanCooldown') {
            $presentation = TrackingPresentation::CooldownTempban;
        } elseif ($economyState === 'listingTempban') {
            $presentation = TrackingPresentation::CooldownListing;
        } elseif ($economyState === 'dailyReceiverLimit') {
            $presentation = TrackingPresentation::CooldownDailyLimit;
        } elseif ($economyState === 'transfersInProgress' && ! $hasCustomerAction && mb_strtolower($simplifiedStatus) !== 'error') {
            // The tracker's third condition, easy to drop and load-bearing: an order can
            // report transfersInProgress while its simplified status says error, and that is
            // not something to render as "transferring" (ui.js:443).
            $presentation = TrackingPresentation::Transferring;
        } elseif ($economyState === 'transferCycleComplete' && ! $hasCustomerAction && ! $isFinished && ! $isStopped) {
            $presentation = TrackingPresentation::TransferringPartDone;
        } elseif (($economyState === 'customerHasPlayer' || $economyState === 'customerListedPlayer') && ! $hasCustomerAction && ! $isFinished && ! $isStopped) {
            $presentation = TrackingPresentation::Preparing;
        } elseif (in_array($accountCheck, ['entered', 'started', 'userPassVerified', 'correctBA'], true) && ! $hasMessage && ! $isFinished && ! $isStopped) {
            $presentation = TrackingPresentation::LoggingIn;
        } elseif ($isFinished) {
            $presentation = TrackingPresentation::Completed;
        } elseif ($isStopped) {
            $presentation = TrackingPresentation::Stopped;
        } elseif ($hasCustomerAction) {
            $presentation = TrackingPresentation::NeedsReview;
        } else {
            $presentation = TrackingPresentation::Processing;
        }

        // Trailing override: ui.js:542: if (remaining <= 0 && !isFinished && !isStopped)
        if ($coinsOrdered !== null
            && $coinsOrdered > 0
            && $coinsDelivered !== null
            && ($coinsOrdered - $coinsDelivered) <= 0
            && ! $isFinished
            && ! $isStopped
        ) {
            return TrackingPresentation::Finishing;
        }

        return $presentation;
    }

    private function resolve(
        Supplier $supplier,
        string $status,
        string $accountCheck,
        string $economyState,
        ?DeliveryPhase $phase,
        ?int $coinsDelivered = null,
        ?int $coinsOrdered = null,
        string $simplifiedStatus = '',
    ): TranslatedState {
        $observed = $this->observedCode($status, $accountCheck, $economyState);
        $holdTone = $this->resolveHoldTone($status, $accountCheck, $economyState);
        $presentation = $this->resolvePresentation($status, $accountCheck, $economyState, $coinsDelivered, $coinsOrdered, $simplifiedStatus);

        if ($this->isFinished($status) && $phase !== DeliveryPhase::Coins) {
            return new TranslatedState(
                OrderStatus::Completed,
                null,
                [],
                true,
                $observed,
                holdTone: $holdTone,
                presentation: $presentation,
            );
        }

        if (isset(self::ACCOUNT_CHECK_HOLDS[$accountCheck])) {
            return $this->hold(
                self::ACCOUNT_CHECK_HOLDS[$accountCheck],
                $supplier,
                $status,
                $accountCheck,
                $economyState,
                $phase,
                $observed,
                $holdTone,
                $presentation,
            );
        }

        if (array_key_exists($economyState, self::ECONOMY_STATE_HOLDS)) {
            return $this->hold(
                self::ECONOMY_STATE_HOLDS[$economyState],
                $supplier,
                $status,
                $accountCheck,
                $economyState,
                $phase,
                $observed,
                $holdTone,
                $presentation,
            );
        }

        if ($economyState === self::ECONOMY_STATE_DEACTIVATED) {
            return $this->deactivated($supplier, $status, $accountCheck, $economyState, $phase, $observed, $holdTone, $presentation);
        }

        if ($this->isStopped($status)) {
            return new TranslatedState(
                OrderStatus::WaitingForCustomer,
                OrderHoldReason::Paused,
                $this->actions($supplier, $status, $accountCheck, $economyState, $phase, OrderStatus::WaitingForCustomer),
                true,
                $observed,
                holdTone: $holdTone,
                presentation: $presentation,
            );
        }

        return new TranslatedState(
            OrderStatus::InProgress,
            null,
            $this->actions($supplier, $status, $accountCheck, $economyState, $phase, OrderStatus::InProgress),
            true,
            $observed,
            holdTone: $holdTone,
            presentation: $presentation,
        );
    }

    /**
     * A known hold reason decides the order's status. Actions stay independent:
     * automatic recovery keeps whatever the tracker lists offer for the code.
     */
    private function hold(
        OrderHoldReason $hold,
        Supplier $supplier,
        string $status,
        string $accountCheck,
        string $economyState,
        ?DeliveryPhase $phase,
        ?string $observed,
        ?HoldTone $holdTone = null,
        ?TrackingPresentation $presentation = null,
    ): TranslatedState {
        if ($this->isAutomaticRecovery($hold)) {
            return new TranslatedState(
                OrderStatus::InProgress,
                $hold,
                $this->actions($supplier, $status, $accountCheck, $economyState, $phase, OrderStatus::InProgress),
                true,
                $observed,
                holdTone: $holdTone,
                presentation: $presentation,
            );
        }

        if ($this->isCustomerAction($hold)) {
            return new TranslatedState(
                OrderStatus::WaitingForCustomer,
                $hold,
                $this->actions($supplier, $status, $accountCheck, $economyState, $phase, OrderStatus::WaitingForCustomer),
                true,
                $observed,
                holdTone: $holdTone,
                presentation: $presentation,
            );
        }

        // A reason in neither set has no known customer demand; keeping the
        // order moving is the only safe reading.
        return new TranslatedState(OrderStatus::InProgress, $hold, [], true, $observed, holdTone: $holdTone, presentation: $presentation);
    }

    private function deactivated(
        Supplier $supplier,
        string $status,
        string $accountCheck,
        string $economyState,
        ?DeliveryPhase $phase,
        ?string $observed,
        ?HoldTone $holdTone = null,
        ?TrackingPresentation $presentation = null,
    ): TranslatedState {
        if ($this->isStopped($status)) {
            return new TranslatedState(
                OrderStatus::WaitingForCustomer,
                OrderHoldReason::Paused,
                $this->actions($supplier, $status, $accountCheck, $economyState, $phase, OrderStatus::WaitingForCustomer),
                true,
                $observed,
                holdTone: $holdTone,
                presentation: $presentation,
            );
        }

        return new TranslatedState(OrderStatus::InProgress, OrderHoldReason::Paused, [], true, $observed, holdTone: $holdTone, presentation: $presentation);
    }

    /**
     * @return list<SupplierAction>
     */
    private function actions(
        Supplier $supplier,
        string $status,
        string $accountCheck,
        string $economyState,
        ?DeliveryPhase $phase,
        OrderStatus $resolved,
    ): array {
        $actions = [];

        if ($this->in(self::EDIT_STATES, $accountCheck) || $this->in(self::EDIT_STATES, $economyState)) {
            $actions[] = SupplierAction::EditCredentials;
        }

        if ($this->in(self::RESUME_STATES, $accountCheck) || $this->in(self::RESUME_STATES, $economyState) || $this->isStopped($status)) {
            $actions[] = SupplierAction::Resume;
        }

        if ($supplier === Supplier::Fft && $phase === DeliveryPhase::Challenge && $resolved === OrderStatus::WaitingForCustomer) {
            $actions[] = SupplierAction::RetryChallenge;
        }

        return $actions;
    }

    private function firstUnknownCode(string $status, string $accountCheck, string $economyState): ?string
    {
        if ($accountCheck !== '' && ! $this->isKnownAccountCheck($accountCheck)) {
            return $accountCheck;
        }

        if ($economyState !== '' && ! $this->isKnownEconomyState($economyState)) {
            return $economyState;
        }

        if ($status !== '' && ! $this->isKnownStatus($status)) {
            return $status;
        }

        return null;
    }

    private function isKnownAccountCheck(string $code): bool
    {
        return isset(self::ACCOUNT_CHECK_HOLDS[$code]) || $this->in(self::SAFE_ACCOUNT_CHECKS, $code);
    }

    private function isKnownEconomyState(string $code): bool
    {
        return $code === self::ECONOMY_STATE_DEACTIVATED
            || array_key_exists($code, self::ECONOMY_STATE_HOLDS)
            || $this->in(self::SAFE_ECONOMY_STATES, $code);
    }

    private function isKnownStatus(string $status): bool
    {
        return $this->isFinished($status)
            || $this->isStopped($status)
            || $this->in(self::SAFE_STATUSES, $status);
    }

    /**
     * @param  list<string>  $codes
     */
    private function in(array $codes, string $code): bool
    {
        return $code !== '' && in_array($code, $codes, true);
    }

    private function isFinished(string $status): bool
    {
        return $this->in(self::FINISHED_STATUSES, $status);
    }

    private function isStopped(string $status): bool
    {
        return $this->in(self::STOPPED_STATUSES, $status);
    }

    private function isTerminal(OrderStatus $status): bool
    {
        return match ($status) {
            OrderStatus::Completed, OrderStatus::Cancelled, OrderStatus::Refunded => true,
            default => false,
        };
    }

    /**
     * @return list<SupplierAction>
     */
    private function sbcActions(Supplier $supplier, string $sbcStatus): array
    {
        return self::sbcChallengeActions($supplier, $sbcStatus);
    }

    private function isKnownSbcStatus(string $status): bool
    {
        return $this->isSbcFinished($status)
            || $this->isSbcSafe($status)
            || array_key_exists($status, self::SBC_STATUS_HOLDS);
    }

    private function isSbcFinished(string $status): bool
    {
        return in_array($status, self::SBC_FINISHED_STATUSES, true);
    }

    private function isSbcSafe(string $status): bool
    {
        return in_array($status, self::SBC_SAFE_STATUSES, true);
    }

    private function observedCode(string $status, string $accountCheck, string $economyState): ?string
    {
        if ($accountCheck !== '') {
            return $accountCheck;
        }

        if ($economyState !== '') {
            return $economyState;
        }

        return $status === '' ? null : $status;
    }

    /**
     * @param  array{coinsDelivered: ?int, coinsOrdered: ?int, squadsDone: ?int, squadsTotal: ?int, solvesDone: ?int, solvesTotal: ?int}  $progress
     */
    private function unsupported(OrderStatus $current, ?string $observed, array $progress): TranslatedState
    {
        return $this->state($current, null, [], false, $observed, $progress, null, null);
    }

    /**
     * @param  list<SupplierAction>  $actions
     * @param  array{coinsDelivered: ?int, coinsOrdered: ?int, squadsDone: ?int, squadsTotal: ?int, solvesDone: ?int, solvesTotal: ?int}  $progress
     */
    private function state(
        OrderStatus $status,
        ?OrderHoldReason $holdReason,
        array $actions,
        bool $supported,
        ?string $observedState,
        array $progress,
        ?HoldTone $holdTone = null,
        ?TrackingPresentation $presentation = null,
    ): TranslatedState {
        return new TranslatedState(
            $status,
            $holdReason,
            $actions,
            $supported,
            $observedState,
            $progress['coinsDelivered'],
            $progress['coinsOrdered'],
            $progress['squadsDone'],
            $progress['squadsTotal'],
            $progress['solvesDone'],
            $progress['solvesTotal'],
            $holdTone,
            $presentation,
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{coinsDelivered: ?int, coinsOrdered: ?int, squadsDone: ?int, squadsTotal: ?int, solvesDone: ?int, solvesTotal: ?int}
     */
    private function progress(array $payload): array
    {
        // Real tracker field names: each SBC job carries challengesDone and
        // totalChallenges (track/assets/js/ui.js:1024-1025, allowlisted in
        // track/includes/api-handlers.php:117-118) for squads in the current solve,
        // and timesSolved / timesToSolve for the solve track.
        return [
            'coinsDelivered' => $this->thousands($payload['amount'] ?? null),
            'coinsOrdered' => $this->thousands($payload['amountOrdered'] ?? null),
            'squadsDone' => $this->count($payload['challengesDone'] ?? null),
            'squadsTotal' => $this->count($payload['totalChallenges'] ?? null),
            'solvesDone' => $this->count($payload['timesSolved'] ?? null),
            'solvesTotal' => $this->count($payload['timesToSolve'] ?? null),
        ];
    }

    private function thousands(mixed $value): ?int
    {
        $number = $this->number($value);

        if ($number === null || $number < 0) {
            return null;
        }

        return (int) round($number * 1000);
    }

    private function count(mixed $value): ?int
    {
        $number = $this->number($value);

        if ($number === null || $number < 0) {
            return null;
        }

        return (int) round($number);
    }

    private function number(mixed $value): ?float
    {
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }

        if (is_string($value) && is_numeric($value)) {
            return (float) $value;
        }

        return null;
    }
}
