<?php

declare(strict_types=1);

use App\Actions\AI\SelectSupportKnowledge;

function selectKnowledge(string $text, int $limit = 3): array
{
    return array_map(
        static fn ($topic): string => $topic->id,
        app(SelectSupportKnowledge::class)->execute($text, $limit),
    );
}

it('finds the warranty topic from an Arabic question', function (): void {
    expect(selectKnowledge('كم مدة الضمان بعد الشحن؟'))->toContain('warranty');
});

it('finds the same topic from the English question', function (): void {
    expect(selectKnowledge('how long is the warranty?'))->toContain('warranty');
});

it('matches Arabic spelling variants of the same word', function (): void {
    // Customers type إسترجاع / استرجاع and ضمانة / ضمانه interchangeably.
    expect(selectKnowledge('أبغى إسترجاع فلوسي'))->toContain('returns');
});

it('matches a mixed Arabic and English question', function (): void {
    expect(selectKnowledge('ابغى refund لطلبي'))->toContain('returns');
});

it('routes an error message to its troubleshooting topic', function (): void {
    expect(selectKnowledge('طلع لي رصيد الكوينز في الحساب غير كافٍ'))
        ->toContain('issue-insufficient-coins');
});

it('returns nothing for chatter that matches no topic', function (): void {
    expect(selectKnowledge('السلام عليكم كيف حالك'))->toBe([]);
});

it('never returns more topics than the limit', function (): void {
    expect(selectKnowledge('الضمان والاسترجاع والكوينز والتحديات والرايفلز', 2))->toHaveCount(2);
});

it('returns nothing when grounding is switched off', function (): void {
    expect(selectKnowledge('كم مدة الضمان؟', 0))->toBe([]);
});

it('orders topics deterministically for the same question', function (): void {
    $first = selectKnowledge('كم مدة الضمان بعد الشحن؟');
    $second = selectKnowledge('كم مدة الضمان بعد الشحن؟');

    expect($first)->toBe($second);
});

it('does not match a keyword fragment hiding inside another word', function (): void {
    // Regression from the 2026-08-23 production batch: "وين" (where) is a
    // literal substring of "كوينز" (coins), so an order-status question was
    // pulling in the coins topic and, with it, a buy card.
    $topics = selectKnowledge('شيك طلبي وقولي وين وصل');

    expect($topics)->toContain('order-tracking')
        ->and($topics)->not->toContain('coins-service');
});
