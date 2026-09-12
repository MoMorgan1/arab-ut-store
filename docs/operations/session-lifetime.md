# The thirty-day session, and what it drags with it

Owner decision, 2026-09-12: `SESSION_LIFETIME` moves from 120 minutes to **43200** (30 days), so
the store stops asking Mohamed to sign in and re-confirm 2FA several times a day. The number is
matched deliberately to `App\Auth\TrustedDeviceRegistry::LIFETIME_DAYS`, which is also 30: a
session should not outlive the device trust that justified it.

This file exists because the change is not local. Four things move with it, and one of them would
have been a security regression if it had not been handled first.

## It is an inactivity window, not a login date

`config/session.php:35` reads the value, and Laravel renews the cookie on every response. Thirty
days means thirty days **since the last request**, not thirty days from signing in. Someone who
uses the store weekly is never signed out. That is the intent.

The same number also lengthens the XSRF cookie, which Laravel mints with the session lifetime.

## Session rows live thirty times longer

`SESSION_DRIVER=database`, so every session is a row, and the garbage-collection lottery
(`config/session.php:117`, `[2, 100]`) only deletes rows that are already past the lifetime. The
sessions table now holds a month of rows instead of two hours of them. Nothing in the code cares;
the table just gets bigger. Watch it once after a month, before assuming it is free.

## Guest cart claims now last thirty days too

This one is easy to miss. `config/coins.php:5`:

    'guest_claim_retention_hours' => max(24, env(COINS_GUEST_CLAIM_RETENTION_HOURS, 24),
                                         ceil(env(SESSION_LIFETIME, 120) / 60)),

The retention deliberately tracks the session lifetime, because a guest cart that disappears while
its session is alive is a bug. So it moves from 24 hours to 720. Guest cart rows are kept for a
month. If that ever becomes a storage problem, the fix is to lower this on its own, not to shorten
the session — they are coupled in one direction only.

## What did NOT move, and must not

- **Password confirmation stays at three hours** (`config/auth.php:115`, `AUTH_PASSWORD_TIMEOUT`).
  A long session is not a reason to stop asking for a password before a sensitive change.
- **Sensitive identity confirmation stays at ten minutes.**
- **Pending phone verification gets its own TTL of 30 minutes**
  (`App\Actions\Auth\PendingVerifiedRegistrationPhone::TTL_MINUTES`). Without it, a one-time
  WhatsApp verification held in the session would have been redeemable for a month —
  `app/Actions/Fortify/CreateNewUser.php` turns that session value straight into
  `phone_verified_at`. A payload with no timestamp counts as stale, so sessions minted before this
  deploy expire rather than inherit the longer window.
- **The admin 2FA gate does not simply trust a long-lived session marker.** See
  `App\Auth\AdminMfaSession`: a marker earned by an actual TOTP challenge is good for 30 days, a
  marker earned by a trusted device is re-checked against the device row every 10 minutes, and
  revoking trusted devices clears the marker on the spot. Otherwise a thirty-day session would
  have turned a revoked device into a standing bypass.

## Deploying it

`SESSION_LIFETIME` lives in the server's `shared/.env` on Hostinger, not in the release. Change it
there and run the config cache step from `docs/operations/hostinger-deployment.md`; `.env.example`
in the repo only documents the intended value. Existing sessions keep the lifetime they were
written with until their next request.
