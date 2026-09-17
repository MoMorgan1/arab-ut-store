<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The owner's heads-up that paid work has gone quiet.
 *
 * One mail per sweep rather than one per item: the three silences it reports
 * all have failure modes that hit every open order at once - the publisher
 * losing its credentials, a supplier going down - and an alarm that turns one
 * outage into forty mails is an alarm that gets filtered.
 *
 * Staff copy, so the dialect rule for customer screens does not apply. It
 * carries plain rows rather than models because it is queued, and a queued
 * notification holding an order item is a payload that can fail to unserialise
 * after the row it names is gone.
 */
final class FulfillmentSilenceAlert extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @param  list<array{kind: string, order: string, detail: string, blocked: bool, pollFailures: int|null}>  $rows
     * @param  int  $total  Every alarm this mail accounts for, listed or not.
     */
    public function __construct(
        public readonly array $rows,
        public readonly int $total,
    ) {
        $this->afterCommit();
    }

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject("تنبيه تنفيذ: {$this->total} عنصر بلا حركة")
            ->greeting('عناصر مدفوعة توقفت عن الحركة')
            ->line('كل سطر هنا عنصر مدفوع لم يصل لمورد، أو عنصر عند مورد توقفت قراءاته أو تأخرت:');

        foreach ($this->rows as $row) {
            $mail->line('- '.$this->describe($row));
        }

        $hidden = $this->total - count($this->rows);

        if ($hidden > 0) {
            $mail->line("و{$hidden} عنصر آخر بنفس الحالة.");
        }

        return $mail
            ->action('افتح لوحة الإدارة', rtrim((string) config('app.url'), '/').'/admin')
            ->salutation('متجر عرب التيميت');
    }

    /**
     * A match on the row rather than a ternary: three kinds share this list,
     * and two of them need more than their own name to be worth reading.
     *
     * @param  array{kind: string, order: string, detail: string, blocked: bool, pollFailures: int|null}  $row
     */
    private function describe(array $row): string
    {
        $label = match (true) {
            // Two sentences for one kind, because they ask for different
            // things. A request the store cannot compose will read the same
            // tomorrow, so the line has to say so rather than look like one
            // more order still on its way.
            $row['kind'] === 'unplaced' && $row['blocked'] => 'متوقف ولن يُرسل بدون تدخل',
            $row['kind'] === 'unplaced' => 'لم يُرسل لأي مورد بعد',
            $row['kind'] === 'stalled' => 'ما وصلت عنه قراءة جديدة من فترة',
            // The count says how deep the silence is, and it belongs in the
            // Arabic sentence rather than in the code list beside it: a number
            // with no word on it is the sort of field that gets read as an
            // order id at two in the morning.
            $row['kind'] === 'silent' && $row['pollFailures'] !== null => "المورد توقف عن الرد عليه بعد {$row['pollFailures']} قراءات فاشلة",
            $row['kind'] === 'silent' => 'المورد توقف عن الرد عليه',
            // A kind added later says what it is worth saying rather than
            // borrowing a sentence that is true of a different failure.
            default => 'توقف عن الحركة',
        };

        $order = $row['order'] === '' ? '-' : $row['order'];
        $detail = trim($row['detail']);

        return "طلب {$order}: {$label}".($detail === '' ? '' : " ({$detail})");
    }
}
