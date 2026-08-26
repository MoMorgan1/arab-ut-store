<?php

use App\Actions\Pricing\PrunePricingHistory;
use App\Enums\ServiceType;
use App\Models\PriceRule;
use App\Models\PriceRun;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function pricingRun(string $status, int $daysAgo): PriceRun
{
    $run = PriceRun::create([
        'run_id' => (string) Str::ulid(),
        'event_id' => (string) Str::ulid(),
        'status' => $status,
        'mode' => $status === 'applied' ? 'apply' : 'dry_run',
        'pricing_version' => 1,
        'payload' => [],
        'started_at' => now()->subDays($daysAgo),
        'completed_at' => now()->subDays($daysAgo),
    ]);

    $run->forceFill(['created_at' => now()->subDays($daysAgo)])->save();

    return $run;
}

function supersededRule(bool $isActive, int $daysAgo): PriceRule
{
    $rule = PriceRule::create([
        'name' => 'Coins '.Str::ulid(),
        'service_type' => ServiceType::Coins,
        'configuration' => ['group' => 'pc'],
        'is_active' => $isActive,
    ]);

    $rule->forceFill(['created_at' => now()->subDays($daysAgo)])->save();

    return $rule;
}

it('deletes pricing runs and superseded rules past the retention window', function () {
    $stale = pricingRun('proposed', 45);
    $recent = pricingRun('proposed', 3);
    $newest = pricingRun('proposed', 1);
    $staleRule = supersededRule(isActive: false, daysAgo: 45);
    $recentRule = supersededRule(isActive: false, daysAgo: 3);

    expect((new PrunePricingHistory)->execute())->toBe(['runs' => 1, 'rules' => 1])
        ->and(PriceRun::find($stale->id))->toBeNull()
        ->and(PriceRun::find($recent->id))->not->toBeNull()
        ->and(PriceRun::find($newest->id))->not->toBeNull()
        ->and(PriceRule::find($staleRule->id))->toBeNull()
        ->and(PriceRule::find($recentRule->id))->not->toBeNull();
});

it('never deletes the rule the storefront is pricing from, however old it is', function () {
    // Prices freeze on the last applied rules whenever a pricing run stops
    // landing. Age is exactly the wrong reason to take those off the shelf.
    $active = supersededRule(isActive: true, daysAgo: 400);

    expect((new PrunePricingHistory)->execute()['rules'])->toBe(0)
        ->and(PriceRule::find($active->id))->not->toBeNull();
});

it('keeps the newest run and the newest applied run whatever their age', function () {
    // In arrival order, the way runs actually land.
    $oldApplied = pricingRun('applied', 400);
    $alsoStale = pricingRun('proposed', 350);
    $newestOfAll = pricingRun('proposed', 300);

    expect((new PrunePricingHistory)->execute()['runs'])->toBe(1)
        ->and(PriceRun::find($alsoStale->id))->toBeNull()
        ->and(PriceRun::find($oldApplied->id))->not->toBeNull()
        ->and(PriceRun::find($newestOfAll->id))->not->toBeNull();
});

it('refuses to run when the retention window is not a usable number', function () {
    config()->set('coins.pricing.retention_days', 0);

    expect(fn () => (new PrunePricingHistory)->execute())
        ->toThrow(RuntimeException::class);
});

it('prunes more rows than one chunk holds', function () {
    foreach (range(1, 12) as $ignored) {
        supersededRule(isActive: false, daysAgo: 60);
    }

    // Keep the loop honest if CHUNK_SIZE is ever lowered below the fixture count.
    expect((new PrunePricingHistory)->execute()['rules'])->toBe(12)
        ->and(PriceRule::count())->toBe(0);
});

test('the prune is scheduled daily and reports what it removed', function () {
    $stale = pricingRun('proposed', 60);
    pricingRun('proposed', 1);
    supersededRule(isActive: false, daysAgo: 60);

    $this->artisan('pricing-history:prune')
        ->expectsOutputToContain('Pruned 1 pricing run(s) and 1 superseded price rule(s).')
        ->assertSuccessful();

    expect(PriceRun::find($stale->id))->toBeNull();

    $events = collect(app(Schedule::class)->events())
        ->filter(fn ($event): bool => str_contains($event->command ?? '', 'pricing-history:prune'));

    expect($events)->toHaveCount(1)
        ->and($events->first()->expression)->toBe('20 3 * * *');
});
