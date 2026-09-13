<?php

namespace App\Actions\Fulfillment;

use App\Account\Presenters\ItemTracking;
use App\Enums\SupplierAction;
use App\Models\OrderItem;
use App\Models\OrderItemSecret;
use App\Models\SecretAccessLog;
use App\Models\User;
use App\Suppliers\Exceptions\SupplierNotConfigured;
use App\Suppliers\Exceptions\SupplierUnavailable;
use App\Suppliers\SupplierRegistry;
use App\ValueObjects\EaAccountCredentials;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

final class SubmitCredentialCorrection
{
    public function __construct(
        private readonly SupplierRegistry $registry,
        private readonly ResolveActionableItem $resolveActionableItem,
    ) {}

    /**
     * Accepts corrected EA credentials, writes them to our own record, then
     * forwards them to the supplier for the phase running now.
     *
     * @param  array<string, mixed>  $validated  the form request's validated
     *                                           ea_email / ea_password / backup_codes
     * @return array{
     *     tracking: array<string, mixed>|null,
     *     status: string,
     * }
     */
    public function execute(
        OrderItem $item,
        User $user,
        string $ipAddress,
        array $validated,
        string $locale,
    ): array {
        $resolved = $this->resolveActionableItem->for($item, SupplierAction::EditCredentials);

        $credentials = EaAccountCredentials::fromValidated($validated);

        // One correction at a time for this item, write and forward together.
        // Two tabs were enough to break it otherwise: both read the same version,
        // both wrote, and whichever supplier call happened to land last decided
        // what the supplier used - which could be the details the store had
        // already replaced. The next Challenge placement would then be composed
        // from one account while the supplier worked on another.
        //
        // A held lock is refused rather than queued: the second press is the same
        // customer pressing twice, and making them wait behind a supplier call to
        // be told nothing changed is worse than telling them to try again.
        $lock = Cache::lock("credential-correction:item:{$item->id}", 30);

        if (! $lock->get()) {
            return $this->response($item, $locale, 'refused');
        }

        try {
            return $this->correct($item, $resolved, $credentials, $user, $ipAddress, $locale);
        } finally {
            $lock->release();
        }
    }

    /**
     * The protocol itself, run under the item's lock.
     *
     * @return array{tracking: array<string, mixed>|null, status: string}
     */
    private function correct(
        OrderItem $item,
        ActionableItem $resolved,
        EaAccountCredentials $credentials,
        User $user,
        string $ipAddress,
        string $locale,
    ): array {

        // Write before forwarding, deliberately. The order of writes is the point: a
        // Challenge order ships coins first and the solve second, and a wrong EA email
        // surfaces during the coins phase. If the correction only ever reached the
        // supplier, the store would still hold the original, and phase two would be
        // placed against an account that does not exist. Our record is the source of
        // truth, so it is written first and the supplier is told second.
        $version = $this->writeSecret($item, $credentials, $user, $ipAddress);

        try {
            $client = $this->registry->for($resolved->supplier);
            $result = $client->correctCredentials(
                $resolved->supplierOrderId,
                $credentials->toSupplierCredentials(),
            );
        } catch (SupplierUnavailable) {
            // The stored correction stays. That is intended: the customer's real details
            // are now on record and the next placement will use them, so this is "saved,
            // not yet sent" rather than a failure.
            return $this->response($item, $locale, 'saved_not_sent');
        } catch (SupplierNotConfigured $exception) {
            // A missing key is ours to fix, not the customer's. The correction is kept
            // exactly as for an outage, and the config gap is logged loudly.
            Log::error('Supplier not configured while correcting credentials for fulfillment job {job_id}', [
                'job_id' => $resolved->job->id,
                'supplier' => $resolved->supplier->value,
                'reason' => $exception->reason,
            ]);

            return $this->response($item, $locale, 'saved_not_sent');
        }

        if (! $result->accepted) {
            // The record was written before the call, so this is not "nothing
            // happened": the store holds the corrected details and the next
            // placement will use them. Saying "that did not go through" would be
            // false, and would invite the customer to type it all again.
            return $this->response($item, $locale, 'saved_not_accepted');
        }

        // The supplier's ack means "received", nothing more; only a fresh poll reveals
        // whether the details work. Stamp the job into the attention band so the sweep
        // reads it now, and record which version we sent so a later observation can clear
        // the pending state.
        $now = CarbonImmutable::now();
        $resolved->job->forceFill([
            'next_poll_at' => $now,
            'last_viewed_at' => $now,
            'credential_version_sent' => $version,
            'credentials_sent_at' => $now,
        ])->save();

        return $this->response($item, $locale, 'accepted');
    }

    /**
     * Writes the corrected credentials into order_item_secrets and logs the access.
     */
    private function writeSecret(
        OrderItem $item,
        EaAccountCredentials $credentials,
        User $user,
        string $ipAddress,
    ): int {
        // The payload and its audit row are one write. Without this a failing log
        // insert left the credentials changed with nothing recording who changed
        // them, and the caller was told the whole thing failed.
        return DB::transaction(function () use ($item, $credentials, $user, $ipAddress): int {
            return $this->persistSecret($item, $credentials, $user, $ipAddress);
        });
    }

    private function persistSecret(
        OrderItem $item,
        EaAccountCredentials $credentials,
        User $user,
        string $ipAddress,
    ): int {
        $secret = OrderItemSecret::query()->where('order_item_id', $item->id)->first();

        if ($secret instanceof OrderItemSecret) {
            $secret->encrypted_payload = $credentials->payload();
            $secret->masked_summary = $credentials->maskedSummary();
            $secret->version = ((int) ($secret->version ?? 1)) + 1;
            $secret->deleted_at = null;
            // A replaced payload is new, so the retention clock starts again with
            // it. Keeping the old date meant the supplier had working details
            // that support was told had already been purged.
            $secret->retained_until = null;
            $secret->save();
        } else {
            $secret = new OrderItemSecret([
                'order_item_id' => $item->id,
                'version' => 1,
                'masked_summary' => $credentials->maskedSummary(),
                'deleted_at' => null,
                'retained_until' => null,
            ]);
            $secret->encrypted_payload = $credentials->payload();
            $secret->save();
        }

        SecretAccessLog::create([
            'order_item_secret_id' => $secret->id,
            'user_id' => $user->id,
            'purpose' => 'customer_credential_correction',
            'ip_address' => $ipAddress,
        ]);

        return (int) $secret->version;
    }

    /**
     * @return array{tracking: array<string, mixed>|null, status: string}
     */
    private function response(OrderItem $item, string $locale, string $status): array
    {
        return [
            'tracking' => ItemTracking::for($item, $locale),
            'status' => $status,
        ];
    }
}
