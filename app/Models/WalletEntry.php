<?php

namespace App\Models;

use App\Enums\WalletEntryType;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property string $public_id
 * @property int $wallet_account_id
 * @property WalletEntryType $type
 * @property int $sequence
 * @property int $amount_halalah
 * @property int $balance_after_halalah
 * @property array<string, mixed>|null $metadata
 * @property CarbonImmutable $created_at
 */
class WalletEntry extends DomainModel
{
    public const UPDATED_AT = null;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'type' => WalletEntryType::class,
            'sequence' => 'integer',
            'amount_halalah' => 'integer',
            'balance_after_halalah' => 'integer',
            'metadata' => 'array',
            'created_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<WalletAccount, $this> */
    public function walletAccount(): BelongsTo
    {
        return $this->belongsTo(WalletAccount::class);
    }

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /** @return BelongsTo<Refund, $this> */
    public function refund(): BelongsTo
    {
        return $this->belongsTo(Refund::class);
    }

    /** @return BelongsTo<User, $this> */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }
}
