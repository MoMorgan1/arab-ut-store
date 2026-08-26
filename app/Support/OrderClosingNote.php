<?php

namespace App\Support;

/**
 * What a customer is told when their order ends.
 *
 * The status cannot carry this on its own. A cancelled order and a refunded one
 * read the same to a customer - and deliberately so, because they are one state
 * on the order page - which leaves nothing to say whether money came back, how
 * much, or where to. That answer is frozen into order_status_history at the
 * moment the order closes, in both locales, the same way a paused order freezes
 * its reason.
 */
final class OrderClosingNote
{
    /**
     * A refund can land in two places at once: the gateway returns what the card
     * paid, and the wallet is credited back what it covered. Saying only one of
     * them leaves the customer looking for money that is already somewhere else.
     *
     * @return array{note_ar: string, note_en: string}
     */
    public static function refund(int $cardHalalah, int $walletHalalah): array
    {
        if ($cardHalalah <= 0 && $walletHalalah <= 0) {
            // Unreachable from the refund path today, which refuses a payment
            // with nothing captured. Saying "0.00 was returned" would be a lie
            // told by default, so name no figure at all.
            return self::reason('payment_cancelled');
        }

        if ($cardHalalah > 0 && $walletHalalah > 0) {
            return self::render('closed.refund_split', [
                'card' => self::amount($cardHalalah),
                'wallet' => self::amount($walletHalalah),
            ]);
        }

        if ($walletHalalah > 0) {
            return self::render('closed.refund_to_wallet', ['amount' => self::amount($walletHalalah)]);
        }

        return self::render('closed.refund_to_card', ['amount' => self::amount($cardHalalah)]);
    }

    /**
     * An order that closed without a refund still owes the customer a reason.
     *
     * @return array{note_ar: string, note_en: string}
     */
    public static function reason(string $key): array
    {
        return self::render('closed.'.$key, []);
    }

    /**
     * @param  array<string, string>  $replacements
     * @return array{note_ar: string, note_en: string}
     */
    private static function render(string $key, array $replacements): array
    {
        return [
            'note_ar' => (string) trans('orders.'.$key, $replacements, 'ar'),
            'note_en' => (string) trans('orders.'.$key, $replacements, 'en'),
        ];
    }

    private static function amount(int $halalah): string
    {
        // Latin digits in both locales, matching every other money figure the
        // storefront prints.
        return number_format($halalah / 100, 2, '.', ',');
    }
}
