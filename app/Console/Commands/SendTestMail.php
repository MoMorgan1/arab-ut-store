<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Throwable;

/**
 * Proves the configured mailer actually delivers.
 *
 * The store shipped with MAIL_MAILER=log, which accepts every message and
 * delivers none, and LOG_LEVEL=warning discarded even the logged copy - so the
 * failure was completely silent. This command makes delivery something you can
 * check in one line instead of something you assume.
 */
final class SendTestMail extends Command
{
    protected $signature = 'mail:test {recipient : Where to send the test message}';

    protected $description = 'Send a real test message through the configured mailer and report what happened';

    public function handle(): int
    {
        $recipient = (string) $this->argument('recipient');

        if (filter_var($recipient, FILTER_VALIDATE_EMAIL) === false) {
            $this->components->error("'{$recipient}' is not a valid email address.");

            return self::FAILURE;
        }

        $mailer = (string) config('mail.default');
        $from = (string) config('mail.from.address');

        $this->components->twoColumnDetail('Mailer', $mailer);
        $this->components->twoColumnDetail('From', $from);

        if ($mailer === 'smtp') {
            $this->components->twoColumnDetail('Host', (string) config('mail.mailers.smtp.host'));
            $this->components->twoColumnDetail('Port', (string) config('mail.mailers.smtp.port'));
            $this->components->twoColumnDetail(
                'Username',
                config('mail.mailers.smtp.username') === null ? 'NOT SET' : 'set',
            );
            $this->components->twoColumnDetail(
                'Password',
                config('mail.mailers.smtp.password') === null ? 'NOT SET' : 'set',
            );
        }

        if (in_array($mailer, ['log', 'array', 'null'], true)) {
            $this->components->error(
                "The '{$mailer}' mailer never delivers anything. Set MAIL_MAILER to a real transport."
            );

            return self::FAILURE;
        }

        $sentAt = now()->toDateTimeString();

        try {
            Mail::raw(
                "Arab UT mail check sent at {$sentAt}.\n\n"
                .'If you are reading this, the store can deliver email.',
                function ($message) use ($recipient): void {
                    $message->to($recipient)->subject('Arab UT mail check');
                },
            );
        } catch (TransportExceptionInterface $exception) {
            // The transport itself refused - wrong host, port, credentials, or
            // a sender address the provider will not accept.
            $this->components->error('The mail transport refused the message.');
            $this->line((string) $exception->getMessage());

            return self::FAILURE;
        } catch (Throwable $exception) {
            $this->components->error('Sending failed.');
            $this->line($exception::class.': '.$exception->getMessage());

            return self::FAILURE;
        }

        $this->components->info("Handed to the mailer for {$recipient}. Check the inbox, and the spam folder.");

        return self::SUCCESS;
    }
}
