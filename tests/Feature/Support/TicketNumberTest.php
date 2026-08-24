<?php

use App\Models\SupportTicket;
use App\Support\TicketNumber;
use Illuminate\Database\QueryException;

it('generates a ticket number matching the documented pattern', function (): void {
    $number = TicketNumber::generate();

    expect($number)->toMatch(TicketNumber::PATTERN)
        ->and($number)->toStartWith('TKT-')
        ->and(strlen($number))->toBe(10);
});

it('never emits ambiguous characters', function (): void {
    for ($i = 0; $i < 200; $i++) {
        expect(TicketNumber::candidate())->not->toContain('0')
            ->and(TicketNumber::candidate())->not->toContain('O')
            ->and(TicketNumber::candidate())->not->toContain('1')
            ->and(TicketNumber::candidate())->not->toContain('I');
    }
});

it('cannot store the same ticket number twice', function (): void {
    // The in-PHP doesntExist() retry inside generate() is not observable: over a
    // 32^6 space a collision never happens on demand, so drawing N numbers and
    // finding no repeat passes just as well with that retry deleted — it tests
    // the alphabet size, not the guard. The unique index is the guarantee that
    // actually holds, so that is what this asserts.
    $taken = TicketNumber::candidate();
    SupportTicket::factory()->create(['ticket_number' => $taken]);

    expect(fn () => SupportTicket::factory()->create(['ticket_number' => $taken]))
        ->toThrow(QueryException::class);
});

it('does not repeat a ticket number across a batch', function (): void {
    $seen = [];

    for ($i = 0; $i < 200; $i++) {
        $seen[] = TicketNumber::candidate();
    }

    expect(array_unique($seen))->toHaveCount(200);
});
