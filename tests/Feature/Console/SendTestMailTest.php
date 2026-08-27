<?php

use Illuminate\Support\Facades\Mail;

test('it refuses a mailer that never delivers', function (string $mailer): void {
    // The store shipped with MAIL_MAILER=log and nobody noticed for months,
    // because the log mailer reports success for every message it drops.
    config()->set('mail.default', $mailer);

    $this->artisan('mail:test', ['recipient' => 'someone@example.test'])
        ->expectsOutputToContain("The '{$mailer}' mailer never delivers anything.")
        ->assertExitCode(1);
})->with(['log', 'array', 'null']);

test('it refuses an address that is not an address', function (): void {
    $this->artisan('mail:test', ['recipient' => 'not-an-address'])
        ->expectsOutputToContain('is not a valid email address')
        ->assertExitCode(1);
});

test('it sends through a real transport and reports the recipient', function (): void {
    config()->set('mail.default', 'smtp');
    Mail::fake();

    $this->artisan('mail:test', ['recipient' => 'buyer@example.test'])
        ->expectsOutputToContain('buyer@example.test')
        ->assertExitCode(0);
});
