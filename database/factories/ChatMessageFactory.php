<?php

namespace Database\Factories;

use App\Enums\Chat\ChatMessageType;
use App\Enums\Chat\ChatSenderType;
use App\Models\ChatConversation;
use App\Models\ChatMessage;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ChatMessage>
 */
class ChatMessageFactory extends Factory
{
    protected $model = ChatMessage::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'conversation_id' => ChatConversation::factory(),
            'sender_type' => ChatSenderType::Customer,
            'message_type' => ChatMessageType::Text,
            'content' => fake()->sentence(),
            'metadata' => null,
            'agent_eligible_at' => null,
            'agent_prompt_blocked_at' => null,
        ];
    }

    public function customer(): static
    {
        return $this->state(fn () => [
            'sender_type' => ChatSenderType::Customer,
        ]);
    }

    public function agentEligible(): static
    {
        return $this->state(fn () => [
            'sender_type' => ChatSenderType::Customer,
            'agent_eligible_at' => now(),
            'agent_prompt_blocked_at' => null,
        ]);
    }

    public function assistant(): static
    {
        return $this->state(fn () => [
            'sender_type' => ChatSenderType::Assistant,
        ]);
    }

    public function system(): static
    {
        return $this->state(fn () => [
            'sender_type' => ChatSenderType::System,
            'message_type' => ChatMessageType::System,
        ]);
    }
}
