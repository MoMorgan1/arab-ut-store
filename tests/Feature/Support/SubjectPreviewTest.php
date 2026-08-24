<?php

use App\Support\SubjectPreview;

it('never exceeds the 160-character support_tickets.subject column, ellipsis included', function (string $content): void {
    $subject = SubjectPreview::fromMessage($content);

    expect(mb_strlen($subject))->toBeLessThanOrEqual(160);
})->with([
    'long spaced English' => [str_repeat('order status question ', 40)],
    'long spaced Arabic' => [str_repeat('وين وصل طلبي بالضبط ', 40)],
    // A single unbroken token has no word boundary to fall back to, which is
    // the case that would overflow if the clip did not reserve room first.
    'one unbroken token' => [str_repeat('x', 400)],
    'boundary at exactly 160' => [str_repeat('a', 160)],
    'boundary at 161' => [str_repeat('b', 161)],
]);

it('collapses whitespace and returns the fallback for an empty message', function (): void {
    expect(SubjectPreview::fromMessage("  hello\n\n   world  "))->toBe('hello world')
        ->and(SubjectPreview::fromMessage(null, 'طلب دعم فني'))->toBe('طلب دعم فني')
        ->and(SubjectPreview::fromMessage('   ', 'fallback'))->toBe('fallback');
});

it('truncates on a word boundary rather than mid-word', function (): void {
    $subject = SubjectPreview::fromMessage(str_repeat('coins ', 60));

    expect($subject)->toEndWith('…')
        ->and($subject)->not->toContain('coin…');
});
