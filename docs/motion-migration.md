# Motion Migration Inventory

Inventory of the `var(--store-ease-out)` call sites that the motion scale replaced.
The plan is [`docs/decisions/2026-09-14-motion-system.md`](decisions/2026-09-14-motion-system.md).

- Started 2026-09-14 with 114 `var()` call sites plus one declaration, all in `resources/css/app.css`
  (store 74, manual 30, auth 7, account 3, chat 0).
- Closed 2026-09-15: every call site reads `var(--motion-ease)` from `resources/js/styles/tokens.css`
  and the `.store-shell { --store-ease-out }` alias is deleted. Both names carried the same curve,
  `cubic-bezier(0.16, 1, 0.3, 1)`, so the storefront's motion did not change.
- One behaviour note: `--store-ease-out` was only ever declared on `.store-shell`, and the auth pages
  render outside that shell. Their seven transitions (login method tab, Google action, submit button)
  therefore never resolved and snapped; they now ease like the rest of the store.

## Exit Criterion

Met. `--store-ease-out` no longer exists; new motion reads `--motion-ease` and the `--motion-*`
durations directly.
