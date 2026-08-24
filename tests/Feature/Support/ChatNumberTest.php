<?php

use App\Models\ChatConversation;
use App\Support\ChatNumber;
use Illuminate\Database\QueryException;

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

it('cannot store the same short id twice', function (): void {
    // The in-PHP doesntExist() retry inside generate() is not observable: over a
    // 32^6 space a collision never happens on demand, so drawing N numbers and
    // finding no repeat passes just as well with that retry deleted — it tests
    // the alphabet size, not the guard. The unique index is the guarantee that
    // actually holds, so that is what this asserts.
    $taken = ChatNumber::candidate();
    ChatConversation::factory()->create(['short_id' => $taken]);

    expect(fn () => ChatConversation::factory()->create(['short_id' => $taken]))
        ->toThrow(QueryException::class);
});

it('does not repeat a short id across a batch', function (): void {
    $seen = [];

    for ($i = 0; $i < 200; $i++) {
        $seen[] = ChatNumber::candidate();
    }

    expect(array_unique($seen))->toHaveCount(200);
});
