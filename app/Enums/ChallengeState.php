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
    case SignInFailed = 'sign_in_failed';
    case SessionExpired = 'session_expired';
    case Failed = 'failed';
    case Unknown = 'unknown';

    public function label(string $locale = 'ar'): string
    {
        return (string) trans("orders.challenge_states.{$this->value}", [], $locale);
    }
}
