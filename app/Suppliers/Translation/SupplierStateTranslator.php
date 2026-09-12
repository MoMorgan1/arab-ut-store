<?php

namespace App\Suppliers\Translation;

use App\Enums\DeliveryPhase;
use App\Enums\OrderHoldReason;
use App\Enums\OrderStatus;
use App\Enums\Supplier;
use App\Enums\SupplierAction;
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
     * and system codes live in one map; a null reason is a state with no hold
     * to name, never a hold on the customer.
     *
     * @var array<string, OrderHoldReason|null>
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
        'belowMinTransfer' => null, // the system is finishing the order itself
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
    private const array SBC_STATUS_HOLDS = [
        // Auth / session errors
        'WrongUserPass' => OrderHoldReason::Credentials,
        'WrongBA' => OrderHoldReason::BackupCodes,
        'sessionExpired' => OrderHoldReason::ActiveSession,
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
    ];

    /**
     * SBC statuses that allow credential editing in the tracker (assets/js/ui.js:1070
     * and includes/api-handlers.php:2106). Exactly two statuses.
     * Maps to SupplierAction::EditCredentials.
     *
     * @var list<string>
     */
    private const array SBC_EDITABLE_STATUSES = [
        'WrongUserPass',
        'WrongBA',
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

        $resolved = $this->resolve($observation->supplier, $status, $accountCheck, $economyState, $phase);

        if ($this->isTerminal($current)) {
            return $this->state($current, null, [], $resolved->supported, $resolved->observedState, $progress);
        }

        return $this->state(
            $resolved->status,
            $resolved->holdReason,
            $resolved->allowedActions,
            $resolved->supported,
            $resolved->observedState,
            $progress,
        );
    }

    /**
     * Translates an SBC / challenge status observation into canonical domain state.
     *
     * A challenge observation uses an entirely separate vocabulary from coins tracking;
     * keeping its own entry point prevents either path from accepting the other's codes.
     *
     * @param  array<string, mixed>  $counters
     */
    public function translateChallenge(
        Supplier $supplier,
        string $sbcStatus,
        array $counters,
        OrderStatus $current,
    ): TranslatedState {
        $status = trim($sbcStatus);

        $squadsDone = $this->count($counters['challengesDone'] ?? $counters['squadsDone'] ?? null);
        $squadsTotal = $this->count($counters['totalChallenges'] ?? $counters['squadsTotal'] ?? null);
        $solvesDone = $this->count($counters['timesSolved'] ?? $counters['solvesDone'] ?? null);
        $solvesTotal = $this->count($counters['timesToSolve'] ?? $counters['solvesTotal'] ?? null);

        // Validation comes first: an unknown challenge status fails closed immediately,
        // leaving the current status untouched with supported=false and no allowed actions.
        if ($status === '' || ! $this->isKnownSbcStatus($status)) {
            return new TranslatedState(
                status: $current,
                holdReason: null,
                allowedActions: [],
                supported: false,
                observedState: $status === '' ? null : $status,
                coinsDelivered: null,
                coinsOrdered: null,
                squadsDone: $squadsDone,
                squadsTotal: $squadsTotal,
                solvesDone: $solvesDone,
                solvesTotal: $solvesTotal,
            );
        }

        if ($this->isTerminal($current)) {
            return new TranslatedState(
                status: $current,
                holdReason: null,
                allowedActions: [],
                supported: true,
                observedState: $status,
                coinsDelivered: null,
                coinsOrdered: null,
                squadsDone: $squadsDone,
                squadsTotal: $squadsTotal,
                solvesDone: $solvesDone,
                solvesTotal: $solvesTotal,
            );
        }

        if ($this->isSbcFinished($status)) {
            return new TranslatedState(
                status: OrderStatus::Completed,
                holdReason: null,
                allowedActions: [],
                supported: true,
                observedState: $status,
                coinsDelivered: null,
                coinsOrdered: null,
                squadsDone: $squadsDone,
                squadsTotal: $squadsTotal,
                solvesDone: $solvesDone,
                solvesTotal: $solvesTotal,
            );
        }

        if ($this->isSbcSafe($status)) {
            return new TranslatedState(
                status: OrderStatus::InProgress,
                holdReason: null,
                allowedActions: [],
                supported: true,
                observedState: $status,
                coinsDelivered: null,
                coinsOrdered: null,
                squadsDone: $squadsDone,
                squadsTotal: $squadsTotal,
                solvesDone: $solvesDone,
                solvesTotal: $solvesTotal,
            );
        }

        // Failure status: customer action reasons move order to WaitingForCustomer;
        // automatic recovery reasons keep it InProgress. If no honest match exists,
        // the reason stays null and the order stays InProgress.
        $holdReason = self::SBC_STATUS_HOLDS[$status] ?? null;
        $orderStatus = ($holdReason !== null && $this->isCustomerAction($holdReason))
            ? OrderStatus::WaitingForCustomer
            : OrderStatus::InProgress;

        $actions = $this->sbcActions($supplier, $status);

        return new TranslatedState(
            status: $orderStatus,
            holdReason: $holdReason,
            allowedActions: $actions,
            supported: true,
            observedState: $status,
            coinsDelivered: null,
            coinsOrdered: null,
            squadsDone: $squadsDone,
            squadsTotal: $squadsTotal,
            solvesDone: $solvesDone,
            solvesTotal: $solvesTotal,
        );
    }

    private function resolve(
        Supplier $supplier,
        string $status,
        string $accountCheck,
        string $economyState,
        ?DeliveryPhase $phase,
    ): TranslatedState {
        $observed = $this->observedCode($status, $accountCheck, $economyState);

        if ($this->isFinished($status) && $phase !== DeliveryPhase::Coins) {
            return new TranslatedState(OrderStatus::Completed, null, [], true, $observed);
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
            );
        }

        if (array_key_exists($economyState, self::ECONOMY_STATE_HOLDS)) {
            $hold = self::ECONOMY_STATE_HOLDS[$economyState];

            if ($hold === null) {
                return new TranslatedState(
                    OrderStatus::InProgress,
                    null,
                    $this->actions($supplier, $status, $accountCheck, $economyState, $phase, OrderStatus::InProgress),
                    true,
                    $observed,
                );
            }

            return $this->hold($hold, $supplier, $status, $accountCheck, $economyState, $phase, $observed);
        }

        if ($economyState === self::ECONOMY_STATE_DEACTIVATED) {
            return $this->deactivated($supplier, $status, $accountCheck, $economyState, $phase, $observed);
        }

        if ($this->isStopped($status)) {
            return new TranslatedState(
                OrderStatus::WaitingForCustomer,
                OrderHoldReason::Paused,
                $this->actions($supplier, $status, $accountCheck, $economyState, $phase, OrderStatus::WaitingForCustomer),
                true,
                $observed,
            );
        }

        return new TranslatedState(
            OrderStatus::InProgress,
            null,
            $this->actions($supplier, $status, $accountCheck, $economyState, $phase, OrderStatus::InProgress),
            true,
            $observed,
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
    ): TranslatedState {
        if ($this->isAutomaticRecovery($hold)) {
            return new TranslatedState(
                OrderStatus::InProgress,
                $hold,
                $this->actions($supplier, $status, $accountCheck, $economyState, $phase, OrderStatus::InProgress),
                true,
                $observed,
            );
        }

        if ($this->isCustomerAction($hold)) {
            return new TranslatedState(
                OrderStatus::WaitingForCustomer,
                $hold,
                $this->actions($supplier, $status, $accountCheck, $economyState, $phase, OrderStatus::WaitingForCustomer),
                true,
                $observed,
            );
        }

        // A reason in neither set has no known customer demand; keeping the
        // order moving is the only safe reading.
        return new TranslatedState(OrderStatus::InProgress, $hold, [], true, $observed);
    }

    private function isAutomaticRecovery(OrderHoldReason $reason): bool
    {
        return in_array($reason, self::AUTOMATIC_RECOVERY_REASONS, true);
    }

    private function isCustomerAction(OrderHoldReason $reason): bool
    {
        return in_array($reason, self::CUSTOMER_ACTION_REASONS, true);
    }

    private function deactivated(
        Supplier $supplier,
        string $status,
        string $accountCheck,
        string $economyState,
        ?DeliveryPhase $phase,
        ?string $observed,
    ): TranslatedState {
        if ($this->isStopped($status)) {
            return new TranslatedState(
                OrderStatus::WaitingForCustomer,
                OrderHoldReason::Paused,
                $this->actions($supplier, $status, $accountCheck, $economyState, $phase, OrderStatus::WaitingForCustomer),
                true,
                $observed,
            );
        }

        return new TranslatedState(OrderStatus::InProgress, OrderHoldReason::Paused, [], true, $observed);
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
        if (! $supplier->handlesChallenges()) {
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
        return $this->state($current, null, [], false, $observed, $progress);
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
