<?php

use App\Enums\TrackingPresentation;

/**
 * Every presentation case is customer copy and nothing else, so a missing lang key
 * is a blank headline on the order page rather than an error anyone would notice.
 * It lives in Feature because resolving a key needs the container.
 */
test('every tracking presentation case resolves a real headline and subline in both locales', function (
    TrackingPresentation $case,
): void {
    foreach (['ar', 'en'] as $locale) {
        $headline = $case->headline($locale);
        $subline = $case->subline($locale);

        expect($headline)->not->toBe('')
            ->and($headline)->not->toContain('orders.tracking_states')
            ->and($subline)->not->toBe('')
            ->and($subline)->not->toContain('orders.tracking_states');
    }
})->with(TrackingPresentation::cases());

test('the three cooldown cases share one headline and carry three different sublines', function (): void {
    $cases = [
        TrackingPresentation::CooldownTempban,
        TrackingPresentation::CooldownListing,
        TrackingPresentation::CooldownDailyLimit,
    ];

    $headlines = array_map(fn (TrackingPresentation $c): string => $c->headline('ar'), $cases);
    $sublines = array_map(fn (TrackingPresentation $c): string => $c->subline('ar'), $cases);

    expect(array_unique($headlines))->toHaveCount(1)
        ->and($headlines[0])->toBe(TrackingPresentation::Processing->headline('ar'))
        ->and(array_unique($sublines))->toHaveCount(3)
        ->and($sublines)->not->toContain(TrackingPresentation::Processing->subline('ar'));
});
