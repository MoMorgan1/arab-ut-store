<?php

use App\Enums\AI\AgentErrorCode;
use App\Enums\AI\AgentTurnStatus;
use App\Models\AgentRun;
use App\Models\AgentTurn;
use App\Services\AI\AgentTurnRetryPolicy;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Carbon;

afterEach(function (): void {
    Carbon::setTestNow();
});

test('stale running turn and run fail safely and remain explicitly retryable', function () {
    config()->set('ai-assistant.stale_turn_seconds', 120);
    $turn = AgentTurn::factory()->running()->create(['updated_at' => now()->subSeconds(120)]);
    $run = AgentRun::factory()->running()->for($turn, 'turn')->create(['updated_at' => now()->subSeconds(120)]);

    $this->artisan('agent:recover-stale-turns')
        ->expectsOutputToContain('Recovered 1 stale agent turn(s).')
        ->doesntExpectOutputToContain($turn->public_id)
        ->assertSuccessful();

    $freshTurn = $turn->fresh();
    $freshRun = $run->fresh();

    expect($freshTurn->status)->toBe(AgentTurnStatus::Failed)
        ->and($freshTurn->terminal_error_code)->toBe(AgentErrorCode::StaleTurnRecovered)
        ->and($freshTurn->completed_at)->not->toBeNull()
        ->and($freshRun->status->value)->toBe('failed')
        ->and($freshRun->error_code)->toBe(AgentErrorCode::StaleTurnRecovered)
        ->and($freshRun->completed_at)->not->toBeNull();

    expect(app(AgentTurnRetryPolicy::class)->canRetry($freshTurn))->toBeTrue();
});

test('stale waiting turn is recovered safely', function () {
    config()->set('ai-assistant.stale_turn_seconds', 60);
    $turn = AgentTurn::factory()->waiting()->create(['updated_at' => now()->subSeconds(65)]);

    $this->artisan('agent:recover-stale-turns')
        ->expectsOutputToContain('Recovered 1 stale agent turn(s).')
        ->assertSuccessful();

    $freshTurn = $turn->fresh();
    expect($freshTurn->status)->toBe(AgentTurnStatus::Failed)
        ->and($freshTurn->terminal_error_code)->toBe(AgentErrorCode::StaleTurnRecovered);
});

test('fresh waiting or running turn is not recovered', function () {
    config()->set('ai-assistant.stale_turn_seconds', 120);
    $runningTurn = AgentTurn::factory()->running()->create(['updated_at' => now()->subSeconds(30)]);
    $waitingTurn = AgentTurn::factory()->waiting()->create(['updated_at' => now()->subSeconds(10)]);

    $this->artisan('agent:recover-stale-turns')
        ->expectsOutputToContain('Recovered 0 stale agent turn(s).')
        ->assertSuccessful();

    expect($runningTurn->fresh()->status)->toBe(AgentTurnStatus::Running)
        ->and($waitingTurn->fresh()->status)->toBe(AgentTurnStatus::Waiting);
});

test('completed or already failed turn is not modified by stale recovery', function () {
    config()->set('ai-assistant.stale_turn_seconds', 60);
    $completedTurn = AgentTurn::factory()->completed()->create(['updated_at' => now()->subSeconds(300)]);
    $failedTurn = AgentTurn::factory()->failed(AgentErrorCode::ProviderTimeout)->create(['updated_at' => now()->subSeconds(300)]);

    $this->artisan('agent:recover-stale-turns')
        ->expectsOutputToContain('Recovered 0 stale agent turn(s).')
        ->assertSuccessful();

    expect($completedTurn->fresh()->status)->toBe(AgentTurnStatus::Completed)
        ->and($failedTurn->fresh()->status)->toBe(AgentTurnStatus::Failed)
        ->and($failedTurn->fresh()->terminal_error_code)->toBe(AgentErrorCode::ProviderTimeout);
});

test('stale agent turn recovery command is scheduled every minute without overlapping', function () {
    $events = collect(app(Schedule::class)->events())
        ->filter(fn ($event): bool => str_contains($event->command ?? '', 'agent:recover-stale-turns'));

    expect($events)->toHaveCount(1)
        ->and($events->first()->expression)->toBe('* * * * *')
        ->and($events->first()->withoutOverlapping)->toBeTrue();
});
