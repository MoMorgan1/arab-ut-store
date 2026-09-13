<?php

namespace App\Enums;

/**
 * Curated customer-facing state chip for an individual challenge card.
 *
 * Curates ~50 raw supplier sbcStatus codes (ui.js:815-900) into a small, meaningful set.
 * Internal diagnostics and plumbing (e.g. 401, 495, loop) are intentionally abstracted
 * away so customers never read internal plumbing details.
 */
enum ChallengeState: string
{
    case Queued = 'queued';
    case WaitingPreviousSolve = 'waiting_previous_solve';
    case Started = 'started';
    case FetchingChallenge = 'fetching_challenge';
    case FetchingSquads = 'fetching_squads';
    case Solving = 'solving';
    case Done = 'done';
    /**
     * A deliberate wait, not a failure. The tracker labels these amber and says
     * the system resumes on its own; grouping them under Failed told the
     * customer the challenge had failed while the card beside it said we were
     * still working.
     */
    case Cooldown = 'cooldown';
    case Reconnecting = 'reconnecting';
    case SignInFailed = 'sign_in_failed';
    case SessionExpired = 'session_expired';
    case Failed = 'failed';
    case Unknown = 'unknown';

    public function label(string $locale = 'ar'): string
    {
        return (string) trans("orders.challenge_states.{$this->value}", [], $locale);
    }

    /**
     * What the "?" beside the chip opens: what this state is, and what the
     * customer is meant to do about it - which for most of them is nothing.
     *
     * @return array{title: string, desc: string, action: string}
     */
    public function help(string $locale = 'ar'): array
    {
        /** @var array{title?: string, desc?: string, action?: string} $help */
        $help = trans("orders.challenge_help.{$this->value}", [], $locale);

        return [
            'title' => $help['title'] ?? $this->label($locale),
            'desc' => $help['desc'] ?? '',
            'action' => $help['action'] ?? '',
        ];
    }
}
