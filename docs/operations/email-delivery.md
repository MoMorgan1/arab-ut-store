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

## What the store sends

A paid order sends the customer a receipt (`App\Notifications\OrderPaidNotification`) from both
places an order becomes paid: the wallet-covered path in `PlaceOrder`, and the Paylink confirmation
in `ReconcilePaylinkPayment`. Alongside it the store sends email-change confirmation, pending email
change, support replies, and Fortify's password reset and email verification.

All of them share the Arab UT template, because `config/mail.php` points the markdown theme at it —
including the two Fortify sends, which were never touched.

A paid order also publishes an `order.paid` integration event to n8n
(`app/Actions/Fulfillment/PublishOrderPaidEvent.php`). That path is unrelated to email.

## Delivery is queued

The order receipt is a queued notification, dispatched after the checkout
transaction commits. A slow or refusing mail server must never be able to fail a
checkout that has already taken the customer's money — and Zoho does time out
occasionally, which was observed twice during setup.

Queued work is drained by the scheduler, not by a long-running worker: the host
runs `schedule:run` every minute by cron and has no supervisor to keep a daemon
alive. `routes/console.php` therefore schedules:

```
queue:work --stop-when-empty --max-time=55 --tries=3 --backoff=30
```

Each run finishes when the queue drains, never overlaps the next minute, and
retries a failed send three times thirty seconds apart.

**If mail stops arriving, check the queue before blaming the mailer:**

```bash
php artisan queue:monitor default
php artisan queue:failed
```

Jobs piling up in the `jobs` table means the scheduler cron is not running.
Rows in `failed_jobs` mean the send itself failed three times; the exception is
stored with each row.

## Domain authentication

Gmail shows a red question mark beside the sender when it cannot authenticate
the domain. As of 2026-08-28 `arab-ut.com` publishes a DMARC record and Zoho's
verification TXT, but **no SPF and no DKIM** — so DMARC asks receivers to check
something that does not exist. Only `p=none` keeps those messages out of spam.

Both records are added in Hostinger's DNS panel:

| Type | Name | Value |
| --- | --- | --- |
| TXT | `@` | `v=spf1 include:zoho.com ~all` |
| TXT | `<selector>._domainkey` | the key Zoho generates under Email Authentication → DKIM |

A domain must never carry two `v=spf1` records — that fails authentication
outright. Edit the existing one rather than adding a second.

Once both resolve and a test message authenticates, tighten DMARC from
`p=none` to `p=quarantine`.
