<?php

namespace Database\Factories;

use App\Enums\Support\SupportTicketPriority;
use App\Enums\Support\SupportTicketStatus;
use App\Models\ChatConversation;
use App\Models\SupportTicket;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SupportTicket>
 */
class SupportTicketFactory extends Factory
{
    protected $model = SupportTicket::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'conversation_id' => ChatConversation::factory(),
            'user_id' => User::factory(),
            'subject' => fake()->sentence(4),
            'status' => SupportTicketStatus::Open,
            'priority' => SupportTicketPriority::Normal,
            'assigned_admin_id' => null,
            'last_notified_at' => null,
            'resolved_at' => null,
            'closed_at' => null,
        ];
    }

    public function open(): static
    {
        return $this->state(fn () => [
            'status' => SupportTicketStatus::Open,
            'resolved_at' => null,
            'closed_at' => null,
        ]);
    }

    public function resolved(): static
    {
        return $this->state(fn () => [
            'status' => SupportTicketStatus::Resolved,
            'resolved_at' => now(),
            'closed_at' => null,
        ]);
    }

    public function closed(): static
    {
        return $this->state(fn () => [
            'status' => SupportTicketStatus::Closed,
            'closed_at' => now(),
        ]);
    }
}
