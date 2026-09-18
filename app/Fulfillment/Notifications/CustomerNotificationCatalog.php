<?php

namespace App\Fulfillment\Notifications;

use App\Enums\OrderHoldReason;

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
 * The `action` column is derived from the translator's EDIT_STATES /
 * RESUME_STATES over its three hold maps, not from prose: credentials and
 * backup_codes resolve only to codes in EDIT_STATES, so their messages name
 * only the edit form; email_confirm resolves to no code in either list, so
 * its message names nothing; every other mapped reason resolves to at least
 * one code in RESUME_STATES, so its message names «تشغيل الطلب». A template
 * naming a button its reason never renders fails the catalogue test.
 */
final class CustomerNotificationCatalog
{
    public const string EVENT_TYPE = 'customer.notify';

    public const int SCHEMA_VERSION = 1;

    public const string TEMPLATE_ORDER_CANCELLED = 'order_cancelled';

    public const string TEMPLATE_ORDER_REFUNDED = 'order_refunded';

    /**
     * Hold-reason value => [template key, button the order page renders].
     *
     * `action` is one of `edit` («تعديل بيانات الطلب»), `resume`
     * («تشغيل الطلب») or `none`.
     *
     * @var array<string, array{template: string, action: string}>
     */
    public const array MAPPED = [
        'backup_codes' => ['template' => 'backup_codes', 'action' => 'edit'],
        'credentials' => ['template' => 'credentials', 'action' => 'edit'],
        'platform' => ['template' => 'platform', 'action' => 'resume'],
        'market_locked' => ['template' => 'market_locked', 'action' => 'resume'],
        'insufficient_coins' => ['template' => 'insufficient_coins', 'action' => 'resume'],
        'active_session' => ['template' => 'active_session', 'action' => 'resume'],
        'no_club' => ['template' => 'no_club', 'action' => 'resume'],
        'transfer_list_full' => ['template' => 'transfer_list_full', 'action' => 'resume'],
        'captcha' => ['template' => 'captcha', 'action' => 'resume'],
        'unassigned' => ['template' => 'unassigned', 'action' => 'resume'],
        'account_banned' => ['template' => 'account_banned', 'action' => 'resume'],
        'two_factor_off' => ['template' => 'two_factor_off', 'action' => 'resume'],
        'email_confirm' => ['template' => 'email_confirm', 'action' => 'none'],
        'web_app_locked' => ['template' => 'web_app_locked', 'action' => 'resume'],
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
     * @return 'edit'|'resume'|'none'|null  null when the reason is silent.
     */
    public static function actionFor(OrderHoldReason $reason): ?string
    {
        return self::MAPPED[$reason->value]['action'] ?? null;
    }

    public static function isSilent(OrderHoldReason $reason): bool
    {
        return in_array($reason->value, self::SILENT, true);
    }
}
