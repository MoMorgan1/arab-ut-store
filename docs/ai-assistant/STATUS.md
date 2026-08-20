# Live status

**Lifecycle:** Implemented; owner acceptance pending
**Verified:** 2026-08-20

## Release snapshot

| Item                                                          | Evidence                                                                                                                                                                                                                                                                                                                                |
| ------------------------------------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Verified Phase 1 application SHA observed/deployed 2026-08-20 | `e7f230d2ea01dc456aef1a51035f4d88f39542e2`                                                                                                                                                                                                                                                                                              |
| Backend/MariaDB release checkpoint                            | [tests 32398600493](https://github.com/MoMorgan1/arab-ut-store/actions/runs/32398600493) and [deploy 32399022501](https://github.com/MoMorgan1/arab-ut-store/actions/runs/32399022501) passed for `1fd83d37b990833cd451d7c3a7b48314976a9f6f`.                                                                                           |
| Final release checkpoint                                      | [tests 32410960971](https://github.com/MoMorgan1/arab-ut-store/actions/runs/32410960971) and [deploy 32411415481](https://github.com/MoMorgan1/arab-ut-store/actions/runs/32411415481) passed for that verified Phase 1 application SHA. The tests workflow passed CI, MariaDB lifecycle, seven Chromium checks, and release packaging. |
| Production read-only evidence                                 | On 2026-08-20, `/up` returned 200; the release/current path resolved to that Phase 1 SHA; all four chat routes, including restart, were registered; `CHAT_SCHEMA_OK`, `ACTIVE_OWNER_DUPLICATE_GROUPS=0`, and `LOCK_TABLES_OK` were returned.                                                                                            |
| Production session decision evidence                          | `SESSION_DRIVER=database`; `SESSION_ENCRYPT=true`.                                                                                                                                                                                                                                                                                      |

On 2026-08-20, read-only production configuration observed
`CHAT_ENABLED=true` and `CHAT_DEMO_ASSISTANT=true` for that Phase 1 release.
This observation does not describe repository or nonproduction defaults. The
application has no provider runtime, OpenAI credential, RAG,
tools, streaming, operator inbox, or Phase 2 implementation.

## Phase status

| Phase                                 | State                                                                                                         |
| ------------------------------------- | ------------------------------------------------------------------------------------------------------------- |
| Phase 0 stabilization                 | Implemented.                                                                                                  |
| Phase 1 deterministic chat foundation | Implemented.                                                                                                  |
| Phase 1 Completion                    | Implemented and deployed; **not accepted** until Mohamed completes the manual account/iPhone checklist below. |
| Phase 2 AI runtime/Luna               | Not started. Do not create or execute its implementation plan before Phase 1 acceptance.                      |

## Remaining owner gate

Mohamed must verify the deployed release with a real account, including on an
iPhone/Safari device:

- the account launcher is above the mobile navigation;
- the full sheet covers navigation and closes back to the focused launcher;
- Arabic, English, and mixed-language messages remain readable;
- New conversation returns a distinct public ID; refresh, navigation, and
  login preserve the appropriate conversation; and an explicit old thread does
  not reopen;
- iPhone zoom, keyboard/safe-area behavior, and touch targets are acceptable.

This is an owner acceptance gate, not a failed automated check. The final
Chromium fixture covers the synthetic authenticated account at 390px, safe-area
geometry, focus, restart-control availability/keyboard order, both account
locales, and console/overflow checks; it does not exercise replacement behavior
and is not Safari proof. See [UX.md](UX.md) and [AUDIT.md](AUDIT.md).

## Open decision

`AI-B09` remains open as a documented session-boundary decision. Chat tables
contain only an HMAC guest key. The raw 32-byte-token representation remains in
the Laravel session; production database session payloads are encrypted, as
shown above. Repository and nonproduction defaults must not be inferred from
that production observation, and changing session configuration may invalidate
active sessions. No configuration change is authorized by this release.

Read this file first, then select the canonical subject from [README.md](README.md).
