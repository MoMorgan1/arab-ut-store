# Email Delivery

## What was wrong

The store never sent a single email. `MAIL_MAILER` was `log`, and the `log` mailer accepts every
message and delivers none. `LOG_LEVEL=warning` then discarded even the logged copy, because the log
mailer writes at `debug` — so there was no bounce, no error, and no trace anywhere. Confirmed on
2026-08-27 by sending the same notification twice: zero occurrences in the log at `warning`, one at
`debug`, and no `Message-ID:` in any log file the machine had ever written.

Nothing about this surfaced to a customer or to an operator. Password resets, email verification,
email-change confirmations, and support replies all silently went nowhere.

## Configuration

Mail goes out through Zoho SMTP as `info@arab-ut.com`.

| Variable | Value |
| --- | --- |
| `MAIL_MAILER` | `smtp` |
| `MAIL_SCHEME` | `smtps` |
| `MAIL_HOST` | `smtp.zoho.com` — use `smtp.zoho.eu` or `smtp.zoho.sa` if the account lives in that data centre |
| `MAIL_PORT` | `465` |
| `MAIL_USERNAME` | `info@arab-ut.com` |
| `MAIL_PASSWORD` | a Zoho **app-specific password**, not the account password |
| `MAIL_FROM_ADDRESS` | `info@arab-ut.com`, and it must be a sender Zoho has verified |

Mohamed adds `MAIL_PASSWORD` directly in Hostinger's environment settings. It is never committed,
printed, or pasted into chat.

Zoho requires an app-specific password whenever two-factor authentication is on the account. Using
the normal account password fails with `535 Authentication Failed`.

## Verifying delivery

```bash
php artisan mail:test you@example.com
```

The command prints the active mailer, host, port, and whether a username and password are set, then
sends a real message. It fails loudly rather than quietly when:

- the mailer is `log`, `array`, or `null` — these never deliver;
- the transport refuses, printing the provider's own message.

A refusal to authenticate against `smtp.zoho.com` means the host and port are reachable and only the
credentials are wrong — that is a useful signal, not a dead end.

Run it after any change to mail configuration, and after a deploy that touched the environment.

## Still missing

There is **no order confirmation or payment receipt email**. The only mail the application sends is
email-change confirmation, pending email change, support replies, and Fortify's password reset and
email verification. A paid order publishes an `order.paid` integration event to n8n
(`app/Actions/Fulfillment/PublishOrderPaidEvent.php`) and nothing else. A customer who pays receives
no email from the store.
