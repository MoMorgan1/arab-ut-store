<?php

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;

test('all expected schedule events are registered with correct frequencies and overlapping settings', function (): void {
    /** @var Schedule $schedule */
    $schedule = app(Schedule::class);
    $events = collect($schedule->events());

    expect($events)->toHaveCount(9);

    $findEvent = function (string $commandSubstring) use ($events): ?Event {
        return $events->first(fn (Event $event): bool => str_contains((string) $event->command, $commandSubstring));
    };

    // 1. RefreshDisplayExchangeRates - daily
    $rates = $findEvent('currency:refresh-display-rates');
    expect($rates)->not->toBeNull()
        ->and($rates->expression)->toBe('0 0 * * *');

    // 2. PurgeGuestCartClaims - hourly, without overlapping
    $guestCart = $findEvent('guest-cart-claims:purge');
    expect($guestCart)->not->toBeNull()
        ->and($guestCart->expression)->toBe('0 * * * *')
        ->and($guestCart->withoutOverlapping)->toBeTrue();

    // 3. PublishOrderPaidEvents - everyMinute, without overlapping
    $publishEvents = $findEvent('orders:publish-paid-events');
    expect($publishEvents)->not->toBeNull()
        ->and($publishEvents->expression)->toBe('* * * * *')
        ->and($publishEvents->withoutOverlapping)->toBeTrue();

    // 4. MaintainChatConversations - hourly, without overlapping
    $maintainChat = $findEvent('chat:maintain-conversations');
    expect($maintainChat)->not->toBeNull()
        ->and($maintainChat->expression)->toBe('0 * * * *')
        ->and($maintainChat->withoutOverlapping)->toBeTrue();

    // 5. RecoverStaleAgentTurns - everyMinute, without overlapping
    $recoverTurns = $findEvent('agent:recover-stale-turns');
    expect($recoverTurns)->not->toBeNull()
        ->and($recoverTurns->expression)->toBe('* * * * *')
        ->and($recoverTurns->withoutOverlapping)->toBeTrue();

    // 6. ExpireAbandonedCheckouts - hourly, without overlapping
    $expireCheckouts = $findEvent('checkouts:expire-abandoned');
    expect($expireCheckouts)->not->toBeNull()
        ->and($expireCheckouts->expression)->toBe('0 * * * *')
        ->and($expireCheckouts->withoutOverlapping)->toBeTrue();

    // 7. PurgeDeadCancelledOrders - hourly, without overlapping
    $purgeDeadOrders = $findEvent('orders:purge-cancelled');
    expect($purgeDeadOrders)->not->toBeNull()
        ->and($purgeDeadOrders->expression)->toBe('0 * * * *')
        ->and($purgeDeadOrders->withoutOverlapping)->toBeTrue();

    // 8. PrunePricingHistory - daily at 03:20, without overlapping
    $prunePricing = $findEvent('pricing-history:prune');
    expect($prunePricing)->not->toBeNull()
        ->and($prunePricing->expression)->toBe('20 3 * * *')
        ->and($prunePricing->withoutOverlapping)->toBeTrue();

    // 9. queue:work with required arguments - every minute, without overlapping (2 min), run in background
    $queueWork = $findEvent('queue:work');
    expect($queueWork)->not->toBeNull()
        ->and($queueWork->expression)->toBe('* * * * *')
        ->and($queueWork->withoutOverlapping)->toBeTrue()
        ->and($queueWork->expiresAt)->toBe(2)
        ->and($queueWork->runInBackground)->toBeTrue()
        ->and($queueWork->command)->toContain('--stop-when-empty')
        ->and($queueWork->command)->toContain('--max-time=55')
        ->and($queueWork->command)->toContain('--tries=3')
        ->and($queueWork->command)->toContain('--backoff=30');
});
