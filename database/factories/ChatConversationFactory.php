<?php

namespace Database\Factories;

use App\Enums\Chat\ChatConversationStatus;
use App\Models\ChatConversation;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ChatConversation>
 */
class ChatConversationFactory extends Factory
{
    protected $model = ChatConversation::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => null,
            'guest_key' => hash_hmac('sha256', bin2hex(random_bytes(32)), (string) config('app.key')),
            'status' => ChatConversationStatus::Open,
            'locale' => 'ar',
            'subject' => null,
            'last_message_at' => now(),
        ];
    }

    public function forUser(User $user): static
    {
        return $this->state(fn () => [
            'user_id' => $user->id,
            'guest_key' => null,
        ]);
    }

    public function forGuest(string $guestKey): static
    {
        return $this->state(fn () => [
            'user_id' => null,
            'guest_key' => $guestKey,
        ]);
    }
}
