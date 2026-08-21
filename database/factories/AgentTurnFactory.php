<?php

namespace Database\Factories;

use App\Enums\AI\AgentErrorCode;
use App\Enums\AI\AgentTurnStatus;
use App\Models\AgentTurn;
use App\Models\ChatConversation;
use App\Models\ChatMessage;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<AgentTurn> */
class AgentTurnFactory extends Factory
{
    protected $model = AgentTurn::class;

    public function configure(): static
    {
        return $this->afterMaking(function (AgentTurn $turn): void {
            if ($turn->getAttribute('first_customer_message_id') === null) {
                $customer = ChatMessage::factory()
                    ->customer()
                    ->agentEligible()
                    ->create(['conversation_id' => $turn->conversation_id]);

                $turn->setAttribute('first_customer_message_id', $customer->id);
            }

            if ($turn->getAttribute('last_customer_message_id') === null) {
                $turn->setAttribute(
                    'last_customer_message_id',
                    $turn->getAttribute('first_customer_message_id'),
                );
            }
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'conversation_id' => ChatConversation::factory(),
            'status' => AgentTurnStatus::Waiting,
            'first_customer_message_id' => null,
            'last_customer_message_id' => null,
            'assistant_message_id' => null,
            'debounce_until' => now(),
            'prompt_version' => 'support-v1',
            'attempt_count' => 0,
            'started_at' => null,
            'completed_at' => null,
            'terminal_error_code' => null,
        ];
    }

    public function waiting(): static
    {
        return $this->state(fn () => [
            'status' => AgentTurnStatus::Waiting,
            'assistant_message_id' => null,
            'started_at' => null,
            'completed_at' => null,
            'terminal_error_code' => null,
        ]);
    }

    public function running(): static
    {
        return $this->state(fn () => [
            'status' => AgentTurnStatus::Running,
            'assistant_message_id' => null,
            'attempt_count' => 1,
            'started_at' => now(),
            'completed_at' => null,
            'terminal_error_code' => null,
        ]);
    }

    public function completed(): static
    {
        return $this->state(fn () => [
            'status' => AgentTurnStatus::Completed,
            'assistant_message_id' => null,
            'attempt_count' => 1,
            'started_at' => now()->subSecond(),
            'completed_at' => now(),
            'terminal_error_code' => null,
        ])->afterMaking(function (AgentTurn $turn): void {
            if ($turn->assistant_message_id !== null) {
                return;
            }

            $assistant = ChatMessage::factory()->assistant()->create([
                'conversation_id' => $turn->conversation_id,
                'reply_to_message_id' => $turn->last_customer_message_id,
            ]);

            $turn->setAttribute('assistant_message_id', $assistant->id);
        });
    }

    public function failed(AgentErrorCode $errorCode): static
    {
        return $this->state(fn () => [
            'status' => AgentTurnStatus::Failed,
            'assistant_message_id' => null,
            'attempt_count' => 1,
            'started_at' => now()->subSecond(),
            'completed_at' => now(),
            'terminal_error_code' => $errorCode,
        ]);
    }
}
