<?php

namespace App\Fulfillment\Notifications;

use App\Enums\OrderHoldReason;
use App\Enums\SupplierAction;

/**
 * Which hold reasons earn a WhatsApp message, which stay silent, and which
 * button each message may name.
 *
 * The catalogue copy itself lives in lang/{ar,en}/notifications.php; this is
 * the mapping between a reason and its template. Seven reasons stay silent
 * on purpose - the automatic-recovery set, where a message about something
 * that fixes itself is noise. That mirrors the translator's
 * AUTOMATIC_RECOVERY_REASONS; the agreement is pinned by test rather than by
 * sharing the constant, so a reason added to one side without the other
 * fails loudly instead of shipping unnoticed.
 *
 * The `buttons` column names the buttons the message's own wording points
 * at, and it is a contract in two directions. A test reads the copy and
 * fails if the two disagree, so the column can never quietly drift from the
 * sentence. And `fits()` is asked at every queue site: a message is owed
 * only when the card the customer will open renders every button its
 * wording names.
 *
 * That check is on the state, not on the reason, because the same reason
 * renders different buttons in different places. `wrongConsole` and
 * `wrongPersona` both mean `platform`, and only the second offers resume.
 * The challenge phase never offers resume at all - `sbcChallengeActions()`
 * emits only `EditCredentials` and `RetryChallenge` («إعادة المحاولة») -
 * so eleven of these messages, which say «اضغط تشغيل الطلب», would be
 * naming a button that is not on the screen. Rather than send a wrong
 * sentence, those holds stay silent until they have wording of their own.
 */
final class CustomerNotificationCatalog
{
    public const string EVENT_TYPE = 'customer.notify';

    public const int SCHEMA_VERSION = 1;

    public const string TEMPLATE_ORDER_CANCELLED = 'order_cancelled';

    public const string TEMPLATE_ORDER_REFUNDED = 'order_refunded';

    /**
     * Hold-reason value => [template key, the buttons its wording names].
     *
     * `buttons` holds `edit` («تعديل بيانات الطلب»), `resume`
     * («تشغيل الطلب»), both, or neither.
     *
     * @var array<string, array{template: string, buttons: list<string>}>
     */
    public const array MAPPED = [
        'backup_codes' => ['template' => 'backup_codes', 'buttons' => ['edit']],
        'credentials' => ['template' => 'credentials', 'buttons' => ['edit']],
        // The platform field itself stays locked - platforms are priced
        // differently - so this message asks for an account on the platform
        // that was ordered, through the edit form. Its wording never said
        // «تشغيل الطلب», and `wrongConsole`, one of the two codes that reach
        // it, renders no resume button at all.
        'platform' => ['template' => 'platform', 'buttons' => ['edit']],
        'market_locked' => ['template' => 'market_locked', 'buttons' => ['resume']],
        'insufficient_coins' => ['template' => 'insufficient_coins', 'buttons' => ['resume']],
        'active_session' => ['template' => 'active_session', 'buttons' => ['resume']],
        'no_club' => ['template' => 'no_club', 'buttons' => ['resume']],
        'transfer_list_full' => ['template' => 'transfer_list_full', 'buttons' => ['resume']],
        'captcha' => ['template' => 'captcha', 'buttons' => ['resume']],
        'unassigned' => ['template' => 'unassigned', 'buttons' => ['resume']],
        // The one message that hedges, because the code behind it might be a
        // device ban the customer can clear or details they mistyped. It
        // names both buttons, so it is owed only where both are offered.
        'account_banned' => ['template' => 'account_banned', 'buttons' => ['resume', 'edit']],
        'two_factor_off' => ['template' => 'two_factor_off', 'buttons' => ['resume']],
        'email_confirm' => ['template' => 'email_confirm', 'buttons' => []],
        'web_app_locked' => ['template' => 'web_app_locked', 'buttons' => ['resume']],
    ];

    /** The button name each wording uses, as the card's own label key. */
    public const array BUTTON_ACTIONS = [
        'edit' => SupplierAction::EditCredentials,
        'resume' => SupplierAction::Resume,
    ];

    /**
     * Reasons that recover without the customer doing anything.
     *
     * Deliberately silent: the order page already offers a "try now" button
     * for these, and a WhatsApp message about something that fixes itself is
     * noise. Must stay identical to the translator's AUTOMATIC_RECOVERY_REASONS.
     *
     * @var list<string>
     */
    public const array SILENT = [
        'ea_servers',
        'store_stock',
        'connection',
        'no_player',
        'maintenance',
        'paused',
        'below_minimum',
    ];

    public static function templateFor(OrderHoldReason $reason): ?string
    {
        return self::MAPPED[$reason->value]['template'] ?? null;
    }

    /**
     * The buttons this reason's wording names, or null when it is silent.
     *
     * @return list<SupplierAction>|null
     */
    public static function buttonsFor(OrderHoldReason $reason): ?array
    {
        $names = self::MAPPED[$reason->value]['buttons'] ?? null;

        if ($names === null) {
            return null;
        }

        return array_map(
            static fn (string $name): SupplierAction => self::BUTTON_ACTIONS[$name],
            $names,
        );
    }

    /**
     * Whether this reason's message is true of a card offering `$rendered`.
     *
     * A message naming a button the card does not show is worse than no
     * message: it sends the customer looking for something that is not
     * there. So every button the wording names must be on offer, and a
     * wording that names none always fits.
     *
     * @param  list<SupplierAction>  $rendered  what the card will show
     */
    public static function fits(OrderHoldReason $reason, array $rendered): bool
    {
        $named = self::buttonsFor($reason);

        if ($named === null) {
            return false;
        }

        foreach ($named as $button) {
            if (! in_array($button, $rendered, true)) {
                return false;
            }
        }

        return true;
    }

    public static function isSilent(OrderHoldReason $reason): bool
    {
        return in_array($reason->value, self::SILENT, true);
    }
}
