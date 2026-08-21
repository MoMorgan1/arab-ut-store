# Testing

## Required layers

- Feature: guest/customer/inactive staff/missing-MFA/permitted staff/Admin for
  every route and mutation.
- Authentication: privileged Google/WhatsApp attempts fail with generic customer
  copy and do not reveal role membership; customer social/phone login remains.
- Unit/feature: every permission mapping and Gate/policy decision.
- Security: secret absence from HTML/Inertia props, errors, URLs, logs, browser
  storage contracts, and cross-owner responses.
- Action: validation, legal/illegal transitions, stale conflicts, audit rows,
  provider failures, missing wallet, wallet underflow/overflow, latest locked
  balance behavior, idempotent replay/conflict across wallets, and unique-key
  race recovery.
- MariaDB integration: any contract that depends on row locks, unique generated
  columns, transaction isolation, or concurrent sequence allocation.
- React/Vitest: URL table state, permission-filtered navigation, loading,
  empty/error/success UI, confirmations, secret clearing, and wallet-adjustment
  UUID creation/retention/regeneration across validation, transport, canonical
  success, and explicit reset.
- Browser/Chromium: Arabic/English at 320/390/768/1440, keyboard, focus, touch
  targets, reduced motion, overflow, and console/request errors.
- Route/request coverage includes recent reauthentication, throttling,
  idempotent replay/conflict, and amount bounds for sensitive financial forms.

## Test quality

- Name the production break each test catches.
- Use real models, migrations, policies, and database boundaries; do not mock
  state objects or internal services.
- Provider HTTP is a justified boundary fake; assert resulting domain state, not
  only request call counts.
- Mutation-prove concurrency/security regressions when practical.
- Do not add broad snapshots or shallow constructor/constant tests.

## Gates

- Focused red/green tests per task.
- Pint, PHPStan with the established memory limit, full Pest.
- Exact MariaDB migration lifecycle and focused concurrency command.
- Vitest, ESLint, Prettier, TypeScript application/e2e, production build.
- Full Chromium acceptance before release.
