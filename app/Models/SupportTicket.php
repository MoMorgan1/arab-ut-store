<?php

namespace App\Models;

use App\Enums\Support\SupportTicketPriority;
use App\Enums\Support\SupportTicketStatus;
use App\Support\TicketNumber;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SupportTicket extends DomainModel
{
    /** @var array<string, string> */
    protected $casts = [
        'status' => SupportTicketStatus::class,
        'priority' => SupportTicketPriority::class,
        'last_notified_at' => 'datetime',
        'resolved_at' => 'datetime',
        'closed_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (SupportTicket $ticket): void {
            // blank() rather than a null/'' comparison: the attribute is typed as
            // string, so PHPStan proves the === null half can never hold.
            if (blank($ticket->ticket_number)) {
                $ticket->ticket_number = TicketNumber::generate();
            }
        });
    }

    /** @param Builder<SupportTicket> $query */
    public function scopeLive(Builder $query): void
    {
        $query->where('status', SupportTicketStatus::Open);
    }

    /** @return BelongsTo<ChatConversation, $this> */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(ChatConversation::class, 'conversation_id');
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /** @return BelongsTo<User, $this> */
    public function assignedAdmin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_admin_id');
    }
}
