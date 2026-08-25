<?php

namespace App\Enums;

/**
 * The curated set of reasons an order stops and waits on the customer.
 *
 * These mirror the issue topics Luna already answers in the knowledge base,
 * so the message on the order page and the message in the chat agree.
 */
enum OrderHoldReason: string
{
    case BackupCodes = 'backup_codes';
    case Credentials = 'credentials';
    case Platform = 'platform';
    case MarketLocked = 'market_locked';
    case InsufficientCoins = 'insufficient_coins';
    case ActiveSession = 'active_session';
    case EaServers = 'ea_servers';
    case NoClub = 'no_club';
    case TransferListFull = 'transfer_list_full';
    case Captcha = 'captcha';
    case Unassigned = 'unassigned';
    case AccountBanned = 'account_banned';
    case StoreStock = 'store_stock';
    case Connection = 'connection';
    case NoPlayer = 'no_player';
    case Maintenance = 'maintenance';
    case Paused = 'paused';

    /**
     * The customer-facing message for this reason, frozen at transition time.
     */
    public function message(string $locale): string
    {
        return (string) trans('orders.hold_reasons.'.$this->value, locale: $locale);
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $reason): string => $reason->value, self::cases());
    }
}
