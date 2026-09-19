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
 * so ten of these messages, which say «اضغط تشغيل الطلب», would be naming
 * a button that is not on the screen. (An earlier draft of this comment
 * said "eleven"; the table holds ten `resume` entries - count them below.)
 * Each of the ten has a challenge-phase sibling in CHALLENGE_MAPPED below,
 * saying the same fact and pointing at «إعادة المحاولة» instead. The
 * selector is `templateForRendered()`: it looks at what the card actually
 * renders and returns whichever wording - default or challenge - is true
 * of that card, or null when neither is.
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

    /**
     * Challenge-phase sibling for every MAPPED reason whose wording names
     * `resume`.
     *
     * The same fact, pointing at «إعادة المحاولة» instead of
     * «تشغيل الطلب». Nine of the ten name only the retry button; the
     * hedging `account_banned` message names retry and edit, exactly as its
     * default names resume and edit. Reasons whose default wording already
     * fits the challenge card (`credentials`, `backup_codes`, `platform`,
     * `email_confirm`) have no sibling and need none.
     *
     * @var array<string, array{template: string, buttons: list<string>}>
     */
    public const array CHALLENGE_MAPPED = [
        'market_locked' => ['template' => 'market_locked_challenge', 'buttons' => ['retry']],
        'insufficient_coins' => ['template' => 'insufficient_coins_challenge', 'buttons' => ['retry']],
        'active_session' => ['template' => 'active_session_challenge', 'buttons' => ['retry']],
        'no_club' => ['template' => 'no_club_challenge', 'buttons' => ['retry']],
        'transfer_list_full' => ['template' => 'transfer_list_full_challenge', 'buttons' => ['retry']],
        'captcha' => ['template' => 'captcha_challenge', 'buttons' => ['retry']],
        'unassigned' => ['template' => 'unassigned_challenge', 'buttons' => ['retry']],
        'account_banned' => ['template' => 'account_banned_challenge', 'buttons' => ['retry', 'edit']],
        'two_factor_off' => ['template' => 'two_factor_off_challenge', 'buttons' => ['retry']],
        'web_app_locked' => ['template' => 'web_app_locked_challenge', 'buttons' => ['retry']],
    ];

    /** The button name each wording uses, as the card's own label key. */
    public const array BUTTON_ACTIONS = [
        'edit' => SupplierAction::EditCredentials,
        'resume' => SupplierAction::Resume,
        'retry' => SupplierAction::RetryChallenge,
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

    public static function challengeTemplateFor(OrderHoldReason $reason): ?string
    {
        return self::CHALLENGE_MAPPED[$reason->value]['template'] ?? null;
    }

    /**
     * The buttons this reason's challenge wording names, or null when it has
     * no challenge sibling.
     *
     * @return list<SupplierAction>|null
     */
    public static function challengeButtonsFor(OrderHoldReason $reason): ?array
    {
        $names = self::CHALLENGE_MAPPED[$reason->value]['buttons'] ?? null;

        if ($names === null) {
            return null;
        }

        return array_map(
            static fn (string $name): SupplierAction => self::BUTTON_ACTIONS[$name],
            $names,
        );
    }

    /**
     * Which wording - if any - is true of a card offering `$rendered`.
     *
     * The default wording wins where it fits, so the coins phase keeps the
     * exact sentence it has always sent. Where it does not fit, the
     * challenge sibling is owed if the card offers what its wording names.
     * Null means neither wording is true there, and the hold stays silent
     * rather than pointing at a button that is not on the screen.
     *
     * @param  list<SupplierAction>  $rendered  what the card will show
     */
    public static function templateForRendered(OrderHoldReason $reason, array $rendered): ?string
    {
        if (self::wordingFits(self::buttonsFor($reason), $rendered)) {
            return self::templateFor($reason);
        }

        if (self::wordingFits(self::challengeButtonsFor($reason), $rendered)) {
            return self::challengeTemplateFor($reason);
        }

        return null;
    }

    /**
     * @param  list<SupplierAction>|null  $named  null names no wording at all
     * @param  list<SupplierAction>  $rendered
     */
    private static function wordingFits(?array $named, array $rendered): bool
    {
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

    /**
     * Whether a queued template's own wording is still true of a card
     * offering `$rendered`. Asked at send time against the stored template,
     * so a card that changed buttons since queue time expires the row
     * instead of sending a sentence about a button that is gone.
     *
     * @param  list<SupplierAction>  $rendered  what the card will show
     */
    public static function templateFits(string $template, array $rendered): bool
    {
        foreach ([self::MAPPED, self::CHALLENGE_MAPPED] as $map) {
            foreach ($map as $entry) {
                if ($entry['template'] !== $template) {
                    continue;
                }

                $named = array_map(
                    static fn (string $name): SupplierAction => self::BUTTON_ACTIONS[$name],
                    $entry['buttons'],
                );

                foreach ($named as $button) {
                    if (! in_array($button, $rendered, true)) {
                        return false;
                    }
                }

                return true;
            }
        }

        // Anything not in either table names no buttons this class knows, so
        // it cannot be shown to be still true. The cancelled and refunded
        // templates never arrive here - `isCurrent` answers for those against
        // the order's status and returns long before the card is consulted.
        return false;
    }

    /**
     * Whether this reason's message is true of a card offering `$rendered`.
     *
     * A message naming a button the card does not show is worse than no
     * message: it sends the customer looking for something that is not
     * there. So every button the wording names must be on offer, and a
     * wording that names none always fits. Either sibling fitting is
     * enough - `templateForRendered()` says which one.
     *
     * @param  list<SupplierAction>  $rendered  what the card will show
     */
    public static function fits(OrderHoldReason $reason, array $rendered): bool
    {
        return self::templateForRendered($reason, $rendered) !== null;
    }

    public static function isSilent(OrderHoldReason $reason): bool
    {
        return in_array($reason->value, self::SILENT, true);
    }
}
