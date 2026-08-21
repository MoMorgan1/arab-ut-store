# Security

## Threat model

Primary risks are privilege escalation, IDOR, secret exposure, replayed
financial mutations, stale concurrent updates, account takeover, audit gaps,
and unsafe automation/manual-authority overlap.

## Authentication

- Admin/Staff must be active, password-backed, and confirmed for Fortify TOTP.
- Privileged users authenticate through email/password plus Fortify's TOTP
  challenge. Google/WhatsApp login must fail generically for Admin/Staff because
  those existing controllers bypass the Fortify MFA pipeline.
- Missing TOTP routes only to enrollment; it never grants ordinary Admin access.
- A missing or unconfirmed TOTP factor may establish only the constrained
  authenticated session required for password setup and MFA enrollment. Task
  3's `EnsureAdminMfa` middleware denies ordinary Admin routes until the factor
  is confirmed.
- Sensitive actions require recent password confirmation.
- Rate-limit login, TOTP challenge/enrollment, credential reveal, refunds,
  wallet adjustments, and staff/settings mutations.

## Authorization

- Enforce permission at route, request/policy, and high-risk Action layers.
- Scope resource queries before resolution and use fail-closed responses.
- Customer and ServiceAccount cannot enter Admin.
- Prevent the last active Admin from being deactivated or demoted.

## Secrets

- Do not eager-load `encrypted_payload` into page queries.
- Decrypt one authorized secret only for an explicit reveal response.
- Use private no-store responses and clear browser memory on close/navigation.
- Never expose raw provider metadata, environment/config secrets, session data,
  TOTP seeds/recovery codes, or password hashes.

## Mutations

- Validate current state under row locks.
- Require explicit legal transitions and conflict on stale state.
- Use idempotency for refunds/wallet and any repeatable financial operation.
- Wallet adjustments intentionally use the latest locked balance; they do not
  invent an expected-balance token. Reject missing accounts, debit underflow,
  credit overflow, and changed/global idempotency-key reuse.
- Audit allowlisted context without sensitive payloads.
- Do not allow generic mass assignment from Admin requests.

## Infrastructure

- Keep Admin inside the existing same-origin session/CSRF boundary.
- Do not add an API token, separate frontend origin, Redis/Horizon, realtime
  transport, or generic settings store without a separately approved design.
