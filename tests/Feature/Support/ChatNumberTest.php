<?php

use App\Models\ChatConversation;
use App\Support\ChatNumber;

it('generates a short id matching the documented pattern', function (): void {
    $number = ChatNumber::generate();

    expect($number)->toMatch(ChatNumber::PATTERN)
        ->and($number)->toStartWith('CHT-')
        ->and(strlen($number))->toBe(10);
});

it('never emits ambiguous characters', function (): void {
    for ($i = 0; $i < 200; $i++) {
        expect(ChatNumber::candidate())->not->toContain('0')
            ->and(ChatNumber::candidate())->not->toContain('O')
            ->and(ChatNumber::candidate())->not->toContain('1')
            ->and(ChatNumber::candidate())->not->toContain('I');
    }
});

it('does not reuse a short id already stored', function (): void {
    $taken = ChatNumber::candidate();
    ChatConversation::factory()->create(['short_id' => $taken]);

    // 50 draws is enough to make an accidental pass vanishingly unlikely.
    for ($i = 0; $i < 50; $i++) {
        expect(ChatNumber::generate())->not->toBe($taken);
    }
});
