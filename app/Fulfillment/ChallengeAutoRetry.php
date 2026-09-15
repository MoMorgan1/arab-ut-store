<?php

namespace App\Fulfillment;

use App\Models\FulfillmentJob;
use App\Models\FulfillmentPlacement;
use App\Suppliers\Exceptions\SupplierUnavailable;
use App\Suppliers\SupplierClient;
use Illuminate\Support\Facades\Log;

/**
 * v14's automatic retrySBCAPI, moved into the store's poll (F2d).
 *
 * Fulfillment v14 polled a solve every few minutes and, on the second poll
 * and every fourth after that up to the fourteenth, sent `retrySBCAPI` for
 * every challenge whose status was on its transient list - the supplier's
 * own hiccups: a session that expired, a login that failed, a click that
 * did not land, no solution found this time. Owner decision, 2026-09-15:
 * that stays automatic ("ايوه نفضل تعيد"), unlike a coins order the owner
 * may have stopped on purpose. Statuses that need a person - wrong
 * credentials, no coins, a console still signed in - are never retried here;
 * the customer's retry button and the hold copy handle those, as before.
 *
 * The count lives on the job (`challenge_retries`), per challenge: reads in
 * a row showing a transient status, and retries sent. Any other status
 * clears the entry, so a challenge that recovers and stalls again gets a
 * fresh budget, as it would have in a fresh v14 run.
 */
final class ChallengeAutoRetry
{
    /**
     * v14's TRANSIENT list in "SBC Solve: Evaluate", verbatim.
     *
     * @var list<string>
     */
    public const array TRANSIENT_STATUSES = [
        'sessionExpired',
        'FailProxyConn',
        'FailedProxyConnectionError',
        'LoginError',
        'LoginFailed',
        'LoginFailed401',
        'LoginFailed495',
        'loginFailed',
        'loginLoop',
        'clickFailed',
        'submitFailed',
        'playerBuyFailed',
        'playerNotFound',
        'playerNotMoved',
        'clubQueryFailed',
        'squadCreateFailed',
        'challengeDataMissing',
        'noPriceFound',
        'noSolutionFound',
    ];

    /** v14: `pollCount % 4 === 2 && pollCount <= 14` - reads 2, 6, 10 and 14. */
    private const int RETRY_EVERY_READS = 4;

    private const int FIRST_RETRY_READ = 2;

    private const int MAX_RETRIES = 4;

    /**
     * @param  array<string, array<string, mixed>>  $bulk  sbcStatusBulkAPI's answer, keyed by challenge id
     */
    public function execute(FulfillmentJob $job, FulfillmentPlacement $placement, array $bulk, SupplierClient $client): void
    {
        $counts = is_array($job->challenge_retries) ? $job->challenge_retries : [];
        $next = [];

        foreach ($placement->challengeIds() as $challengeId) {
            $status = $bulk[$challengeId]['sbcStatus'] ?? null;

            if (! is_string($status) || ! in_array($status, self::TRANSIENT_STATUSES, true)) {
                continue;
            }

            $entry = is_array($counts[$challengeId] ?? null) ? $counts[$challengeId] : [];
            $reads = max(0, (int) ($entry['reads'] ?? 0)) + 1;
            $retries = max(0, (int) ($entry['retries'] ?? 0));
            $due = $reads >= self::FIRST_RETRY_READ
                && ($reads - self::FIRST_RETRY_READ) % self::RETRY_EVERY_READS === 0
                && $retries < self::MAX_RETRIES;

            if ($due && $this->retry($job, $placement, $challengeId, $status, $client)) {
                $retries++;
            }

            $next[$challengeId] = ['status' => $status, 'reads' => $reads, 'retries' => $retries];
        }

        if ($next !== $counts) {
            $job->forceFill(['challenge_retries' => $next === [] ? null : $next])->save();
        }
    }

    private function retry(FulfillmentJob $job, FulfillmentPlacement $placement, string $challengeId, string $status, SupplierClient $client): bool
    {
        try {
            $result = $client->retryChallenge((string) $placement->supplier_order_id, $challengeId);
        } catch (SupplierUnavailable $exception) {
            // Counted as a read, not as a retry: the next due read tries again.
            Log::warning('Automatic challenge retry could not reach the supplier.', [
                'job_id' => $job->id,
                'challenge_id' => $challengeId,
                'status' => $status,
                'reason' => $exception->getMessage(),
            ]);

            return false;
        }

        Log::info('Automatic challenge retry sent.', [
            'job_id' => $job->id,
            'challenge_id' => $challengeId,
            'status' => $status,
            'accepted' => $result->accepted,
            'code' => $result->code,
        ]);

        return true;
    }
}
