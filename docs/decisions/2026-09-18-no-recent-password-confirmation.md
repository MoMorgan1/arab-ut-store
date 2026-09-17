# The Admin asks for a password once, at sign in — owner decision, 2026-09-18

**Decision.** Recent-password confirmation is not wanted anywhere in the Admin, and the
half-built mechanism for it is deleted rather than finished.

## What existed

The contract asked for it. `.agents/skills/arab-ut-admin/references/forms.md` required "recent
password confirmation for credential reveal, refund, wallet adjustment, customer activation,
staff/role changes, catalog/pricing changes, and settings changes". Around that sentence the
repository had:

- copy for the prompt — `passwordModalTitle`, `passwordModalDescription`, `passwordLabel` and
  `passwordPlaceholder`, in seven separate blocks of both language files, and the TypeScript types
  and test fixtures to match;
- an entry point — `/admin/security/confirm-password`, which put the settings page in
  `url.intended` and redirected to Fortify's confirm screen;
- 146 test calls seeding `auth.password_confirmed_at` into the session before an admin request.

**And not one of our own routes required it.** No admin route carries `password.confirm`;
`EnsureAdminPassword`, which they do carry, checks only that the actor *has* a password and sends
them to set one otherwise. So the copy was a promise the store did not keep, and 146 of those test
session seeds were guarding nothing.

The exception, found by running the suite rather than by reading: **Fortify guards its own
two-factor endpoints** with `password.confirm` and answers 423 when the confirmation has lapsed -
enabling, disabling, the QR code, the secret and the recovery codes. That gate is real, the
security section already handles the 423, and `/admin/security/confirm-password` is the link it
offers. It stays. Removing it would let a stolen session switch two-factor off without knowing the
password, which is not what "the prompt is not important" was about.

E1's review (sol, 2026-09-17) is what surfaced the rest: reading the re-send action against the
contract, the screen could not honestly say "you will be asked for your password".

## Why not finish it instead

The owner's answer, asked directly: it is not important. Two-factor is already required to reach
any admin screen, the sensitive actions each need their own permission that Staff do not hold, and
every one of them already has a confirm step that names the record and the consequence plus an
audit row that names the actor. A second password prompt in front of that is friction on the one
person who owns the store, not a control against anybody else.

## What the code does now

- `forms.md` says there is no password confirmation, and says not to write copy promising one.
- The four copy keys are gone from both language files, the types and the fixtures.
- `/admin/security/confirm-password` stays, with a comment saying which gate it serves, because
  Fortify's two-factor endpoints still need it.
- The test suite seeds `auth.password_confirmed_at` only where a Fortify two-factor endpoint is
  called. The other 146 seeds are gone; the suite is what proved which ones mattered.

## What would reopen this

A second person with `orders.refund`, `wallet.adjust` or `fulfillment.act` and a shared machine.
The decision rests on the Admin being one owner today; it is not an argument that a re-authentication
step is worthless for a team.
