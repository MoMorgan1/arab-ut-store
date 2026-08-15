<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Hidden(['candidate_value', 'candidate_hash', 'verification_hash'])]
class UserIdentityChange extends DomainModel
{
    public const KIND_EMAIL = 'email';

    public const KIND_PHONE = 'phone';

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'candidate_value' => 'encrypted',
            'attempts' => 'integer',
            'expires_at' => 'immutable_datetime',
            'last_sent_at' => 'immutable_datetime',
            'consumed_at' => 'immutable_datetime',
        ];
    }

    public static function candidateHash(string $normalized): string
    {
        return hash_hmac('sha256', $normalized, (string) config('app.key'));
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
