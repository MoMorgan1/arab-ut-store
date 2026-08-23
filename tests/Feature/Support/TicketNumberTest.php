<?php

use App\Models\SupportTicket;
use App\Support\TicketNumber;

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

it('does not reuse a ticket number already stored', function (): void {
    $taken = TicketNumber::candidate();
    SupportTicket::factory()->create(['ticket_number' => $taken]);

    // 50 draws is enough to make an accidental pass vanishingly unlikely.
    for ($i = 0; $i < 50; $i++) {
        expect(TicketNumber::generate())->not->toBe($taken);
    }
});
