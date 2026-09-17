<?php

namespace App\Actions\Fulfillment;

use App\Enums\FulfillmentAlarmKind;
use App\Models\FulfillmentAlarm;
use App\Notifications\FulfillmentSilenceAlert;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Notification;

/**
 * Sends the one mail the alarm table has earned, and records that it did.
 *
 * `notified_at` is stamped on every alarm the mail accounts for, including the
 * ones past the listing cap: a row left unstamped is a row the next sweep
 * mails again, and an outage that opens forty alarms would then mail forever.
 * The stamp says the mail was handed to the queue, which is the honest claim -
 * if the queue then fails to deliver it, that lands in `failed_jobs`, where
 * the admin panel already reports it.
 *
 * No address configured means no mail and no error, the same as the paid-order
 * alert: the alarms stay open and stay visible in the panel either way.
 */
final class AlertOwnerOfFulfillmentSilence
{
    /**
     * How many silences one mail spells out before it starts counting them.
     *
     * Past this the list has stopped being a thing to read and become a thing
     * to scroll; the total in the subject line carries the rest.
     */
    private const LISTED = 20;

    public function execute(): int
    {
        /** @var Collection<int, FulfillmentAlarm> $pending */
        $pending = FulfillmentAlarm::query()
            ->whereNull('resolved_at')
            ->whereNull('notified_at')
            ->with('orderItem')
            ->orderBy('raised_at')
            ->orderBy('id')
            ->get();

        if ($pending->isEmpty()) {
            return 0;
        }

        $email = config('store.order_alerts.email');

        if (! is_string($email) || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return 0;
        }

        $rows = [];

        foreach ($pending->take(self::LISTED) as $alarm) {
            $rows[] = $this->row($alarm);
        }

        Notification::route('mail', $email)
            ->notify(new FulfillmentSilenceAlert($rows, $pending->count()));

        FulfillmentAlarm::query()
            ->whereIn('id', $pending->pluck('id')->all())
            ->update(['notified_at' => CarbonImmutable::now()]);

        return $pending->count();
    }

    /** @return array{kind: string, order: string, detail: string, blocked: bool, pollFailures: int|null} */
    private function row(FulfillmentAlarm $alarm): array
    {
        $context = is_array($alarm->context) ? $alarm->context : [];
        $failures = $context['poll_failures'] ?? null;

        return [
            'kind' => $alarm->kind->value,
            'order' => is_string($context['order_number'] ?? null) ? $context['order_number'] : '',
            'detail' => $alarm->kind === FulfillmentAlarmKind::Stalled
                ? $this->stalledDetail($context)
                : $this->detail($context),
            // Says that no retry will clear this one, so the mail can ask for a
            // person rather than for patience. Absent on an alarm raised before
            // the store had a reason to record, which is read as "not yet".
            'blocked' => ($context['blocked'] ?? false) === true,
            'pollFailures' => is_int($failures) ? $failures : null,
        ];
    }

    /**
     * The three facts that make a stall triageable, which the shared detail
     * line does not carry.
     *
     * The item id still leads, for the reason {@see self::detail()} gives.
     * After it: how long is the first question anyone asks and the sweep
     * already stores it; which phase says whether to look at a coin shipment
     * or a solve; and a supplier inside its cooldown is a different problem
     * from a supplier that has gone quiet, so the line says which it is rather
     * than sending someone after a supplier that is behaving.
     *
     * @param  array<string, mixed>  $context
     */
    private function stalledDetail(array $context): string
    {
        $parts = [];

        if (is_string($context['order_item_public_id'] ?? null) && $context['order_item_public_id'] !== '') {
            $parts[] = $context['order_item_public_id'];
        }

        $quiet = $context['quiet_minutes'] ?? null;
        $parts[] = is_numeric($quiet) ? 'بدون قراءة منذ '.(int) $quiet.' دقيقة' : 'ما وصلت عنه أي قراءة';

        if (is_string($context['phase'] ?? null) && $context['phase'] !== '') {
            $parts[] = 'مرحلة '.$context['phase'];
        }

        if (is_string($context['supplier'] ?? null) && $context['supplier'] !== '') {
            $parts[] = $context['supplier'];
        }

        if (($context['circuit_open'] ?? false) === true) {
            $parts[] = 'المورد موقوف مؤقتاً، ما نسأله الآن';
        }

        return implode(' / ', $parts);
    }

    /**
     * Everything the recovery runbook's first step needs, in the order it is
     * needed.
     *
     * The mail is what the owner has at two in the morning, so a field that
     * only reaches the database row is a field nobody has. The item id leads
     * because every step takes it - the supplier lookup, the placement report -
     * and because one order can hold several items with one reason between
     * them, which without this produces two identical lines.
     *
     * @param  array<string, mixed>  $context
     */
    private function detail(array $context): string
    {
        $parts = [];

        foreach (['order_item_public_id', 'service', 'supplier', 'supplier_order_id'] as $key) {
            $value = $context[$key] ?? null;

            if (is_string($value) && $value !== '') {
                $parts[] = $value;
            }
        }

        // The publisher's own word for what stopped it - `budget_unavailable`,
        // `credentials_purged` - passed through rather than translated. It is
        // the string in the log and in `integration_events.last_error`, and an
        // operator searching for the order wants the same spelling in all
        // three. The same is true of the identifiers and codes above.
        if (is_string($context['reason'] ?? null) && $context['reason'] !== '') {
            $parts[] = $context['reason'];
        }

        return implode(' / ', $parts);
    }
}
