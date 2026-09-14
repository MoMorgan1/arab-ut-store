<?php

namespace App\Suppliers\Translation;

use App\Enums\ChallengeState;
use App\Enums\ChallengeTone;

/**
 * The customer-facing presentation of one raw SBC status: its label and its
 * tone, plus an optional per-status help that overrides the coarse
 * ChallengeState help where the coarse wording is wrong.
 *
 * This is a presentation layer over SupplierStateTranslator, not a new
 * classification. It reuses the raw status codes that already flow through
 * SBC_STATUS_HOLDS and SBC_STATUS_CHALLENGE_STATES, and derives a card's tone
 * straight from the tracker's own colour (track/assets/js/ui.js SBC_STATUS_MAP):
 * success -> success, danger -> danger, warning -> waiting, primary -> working.
 *
 * A status absent from the tone table keeps the coarse ChallengeState label and
 * derives its tone from that state, so an unknown code never crashes the card.
 */
final class SbcStatusPresentation
{
    /**
     * Per-status tone, keyed by the raw supplier sbcStatus, transcribed from the
     * tracker's SBC_STATUS_MAP colour. The label key for a status is the status
     * itself: lang/ar/orders.php and lang/en/orders.php hold one entry per status
     * under orders.challenge_statuses, keyed identically in both languages.
     *
     * @var array<string, ChallengeTone>
     */
    public const array SBC_STATUS_TONES = [
        // Progress
        'entered' => ChallengeTone::Waiting,
        'waitingForOtherSolve' => ChallengeTone::Waiting,
        'started' => ChallengeTone::Working,
        'fetchSBCInfo' => ChallengeTone::Working,
        'fetchChallengeInfo' => ChallengeTone::Working,
        'solvingChallenge' => ChallengeTone::Working,
        'finished' => ChallengeTone::Success,

        // Auth / session errors
        'sessionExpired' => ChallengeTone::Danger,
        'needEmailConfirm' => ChallengeTone::Danger,
        'LoginFailed495' => ChallengeTone::Danger,
        'LoginFailed401' => ChallengeTone::Danger,
        'LoginFailedDeviceBan' => ChallengeTone::Danger,
        'LoginError' => ChallengeTone::Danger,
        'LoginFailed' => ChallengeTone::Danger,
        'WrongUserPass' => ChallengeTone::Danger,
        '2FADisabled' => ChallengeTone::Danger,
        'No2FA' => ChallengeTone::Danger,
        'WrongBA' => ChallengeTone::Danger,
        'loginLoop' => ChallengeTone::Danger,
        'loginFailed' => ChallengeTone::Danger,

        // Proxy / connection errors
        'FailProxyConn' => ChallengeTone::Waiting,
        'FailedProxyConnectionError' => ChallengeTone::Waiting,
        'FailProxy' => ChallengeTone::Waiting,

        // Account / setup errors
        'failedNoClub' => ChallengeTone::Danger,
        'consoleLoggedIn' => ChallengeTone::Danger,
        'FailedPersonaSwitch' => ChallengeTone::Danger,
        'TMLocked' => ChallengeTone::Danger,

        // SBC-specific errors
        'setNotFound' => ChallengeTone::Danger,
        'foundationNotSolved' => ChallengeTone::Danger,
        'alreadyCompleted' => ChallengeTone::Success,
        'challengeDataMissing' => ChallengeTone::Danger,
        'noSolutionFound' => ChallengeTone::Danger,
        'tooExpensive' => ChallengeTone::Danger,
        'clickFailed' => ChallengeTone::Danger,
        'submitFailed' => ChallengeTone::Danger,
        'squadCreateFailed' => ChallengeTone::Danger,

        // Player / market errors
        'playerBuyFailed' => ChallengeTone::Danger,
        'playerNotFound' => ChallengeTone::Danger,
        'playerNotMoved' => ChallengeTone::Danger,
        'clubQueryFailed' => ChallengeTone::Danger,
        'tooManyExchanges' => ChallengeTone::Danger,

        // Financial errors
        'noFunds' => ChallengeTone::Danger,
        'OutOfCoins' => ChallengeTone::Danger,
        'tempban' => ChallengeTone::Waiting,
        'TempbanCooldown' => ChallengeTone::Waiting,
        'dailyReceiverLimit' => ChallengeTone::Waiting,

        // System errors
        'aborted' => ChallengeTone::Danger,
        'failed' => ChallengeTone::Danger,
        'FailUnassignedFound' => ChallengeTone::Waiting,
    ];

    /**
     * Raw statuses whose coarse ChallengeState help is wrong for the customer,
     * mapped to the help key that answers them under orders.challenge_status_help.
     * Everything else keeps the state's help unchanged.
     *
     * Several statuses share a key. Nine of them said the same two paragraphs as
     * six and three verbatim copies, which is eighteen places across two
     * languages for one sentence to drift out of step with itself.
     *
     * @var array<string, string>
     */
    public const array SBC_STATUS_HELP = [
        // EA refused the sign-in; the action is to retry, not to retype a password.
        'LoginFailed401' => 'sign_in_refused',
        'LoginFailed495' => 'sign_in_refused',
        'LoginError' => 'sign_in_refused',
        'LoginFailed' => 'sign_in_refused',
        'loginFailed' => 'sign_in_refused',
        'loginLoop' => 'sign_in_refused',
        // The details really are wrong; the action is to update them.
        'WrongUserPass' => 'WrongUserPass',
        'WrongBA' => 'WrongBA',
        // The tracker carries a specific instruction for each of these.
        'TMLocked' => 'TMLocked',
        'failedNoClub' => 'failedNoClub',
        'consoleLoggedIn' => 'consoleLoggedIn',
        'FailUnassignedFound' => 'FailUnassignedFound',
        '2FADisabled' => 'two_factor_off',
        'No2FA' => 'two_factor_off',
        'no2fa' => 'two_factor_off',
        'LoginFailedDeviceBan' => 'LoginFailedDeviceBan',
        // Nothing is asked of the customer; the wait ends on its own.
        'tempban' => 'tempban',
        'TempbanCooldown' => 'TempbanCooldown',
        'dailyReceiverLimit' => 'dailyReceiverLimit',
        // A failed-looking status that offers no retry button, so the coarse
        // "press Try again" would be a lie.
        'FailedPersonaSwitch' => 'FailedPersonaSwitch',
        // Email confirmation is not a wrong-password problem.
        'needEmailConfirm' => 'needEmailConfirm',
    ];

    public static function has(string $status): bool
    {
        return array_key_exists($status, self::SBC_STATUS_TONES);
    }

    /**
     * The customer-facing label for a status already known to the tone table.
     */
    public static function label(string $status, string $locale): string
    {
        return (string) trans("orders.challenge_statuses.{$status}", [], $locale);
    }

    /**
     * The card tone for a status: its table entry when it has one, else a tone
     * derived from the coarse ChallengeState so an unknown code never crashes.
     */
    public static function tone(string $status): ChallengeTone
    {
        return self::SBC_STATUS_TONES[$status] ?? self::toneForState(SupplierStateTranslator::challengeState($status));
    }

    public static function toneForState(ChallengeState $state): ChallengeTone
    {
        return match ($state) {
            ChallengeState::Done => ChallengeTone::Success,
            ChallengeState::Started,
            ChallengeState::FetchingChallenge,
            ChallengeState::FetchingSquads,
            ChallengeState::Solving => ChallengeTone::Working,
            ChallengeState::Queued,
            ChallengeState::WaitingPreviousSolve,
            ChallengeState::Cooldown,
            ChallengeState::Reconnecting => ChallengeTone::Waiting,
            ChallengeState::SignInFailed,
            ChallengeState::SessionExpired,
            ChallengeState::Failed => ChallengeTone::Danger,
            // An unknown status is not "done", not clearly stopped, and not
            // moving; the least misleading of the four is a neutral wait.
            ChallengeState::Unknown => ChallengeTone::Waiting,
        };
    }

    /**
     * Per-status help when the status has its own; otherwise the coarse state's
     * help, unchanged. Shaped identically to ChallengeState::help().
     *
     * @return array{title: string, desc: string, action: string}
     */
    public static function help(string $status, ChallengeState $state, string $locale): array
    {
        $key = self::SBC_STATUS_HELP[$status] ?? null;

        if ($key === null) {
            return $state->help($locale);
        }

        /** @var array{title?: string, desc?: string, action?: string} $help */
        $help = trans("orders.challenge_status_help.{$key}", [], $locale);

        return [
            'title' => $help['title'] ?? $state->label($locale),
            'desc' => $help['desc'] ?? '',
            'action' => $help['action'] ?? '',
        ];
    }
}
