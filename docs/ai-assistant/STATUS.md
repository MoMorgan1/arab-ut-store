# Live status

**Lifecycle:** Implemented
**Verified:** 2026-08-20

## Release snapshot

| Item                                           | Status                                                                                                                                                                                                                                                                                                                                          |
| ---------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Browser-verified application release SHA       | `fdba471af2fef38905581a309bf8b0e9119ab41b`                                                                                                                                                                                                                                                                                                      |
| Canonical handbook/final-review correction SHA | `c53efb87b9abee5e9ccbfe5d7dc95a2fd230fe75`                                                                                                                                                                                                                                                                                                      |
| Final application gate                         | [Successful](https://github.com/MoMorgan1/arab-ut-store/actions/runs/32369538233): Pest 820/820 (32,910 assertions); Vitest 350/350 across 51 files; Chromium 6/6 with one worker in 8.8s; Vite build, lint, format, main TypeScript, E2E TypeScript, and packaging passed; MariaDB lifecycle job passed 182 with 5 skipped (1,206 assertions). |
| Most recent canonical docs deployment          | [Successful](https://github.com/MoMorgan1/arab-ut-store/actions/runs/32369939283) (attempt 2) for the prior docs revision; correction `c53efb87b9abee5e9ccbfe5d7dc95a2fd230fe75` remains local pending controller push and authoritative CI/deploy evidence                                                                                     |
| Read-only production HTML                      | 2026-08-20: `/`, `/en`, `/login`, `/en/login`, and `/cart` each returned HTTP 200 HTML with `/build/assets/app-Bj8EfwXn.js` and `/build/assets/app-DEfxZB8X.css`; no obvious secret or debug output detected; `chat.enabled=true`; `chat.demoAssistant=true`                                                                                    |
| Operational flags                              | `CHAT_ENABLED=true`; `CHAT_DEMO_ASSISTANT=true`                                                                                                                                                                                                                                                                                                 |
| P0/P1 release gate                             | Proceed; no unresolved P0 or P1 audit finding                                                                                                                                                                                                                                                                                                   |
| Owner acceptance                               | `Pending Mohamed manual test`                                                                                                                                                                                                                                                                                                                   |

The browser-verified application release SHA, canonical handbook/final-review
correction SHA, and later `STATUS.md`-only commit are distinct. This later
status-only commit records the first correction commit as evidence: it neither
changes the application runtime nor claims a new browser verification of it.

## Open audit findings

| ID       | Severity | Current state                                                                                                                                                                                |
| -------- | -------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `AI-B03` | P2       | Open-conversation creation concurrency remains unguarded.                                                                                                                                    |
| `AI-B04` | P2       | Duplicate contention and lost-response demo-reply recovery remain open.                                                                                                                      |
| `AI-B05` | P2       | Conversation plus onboarding-message creation remains non-atomic.                                                                                                                            |
| `AI-B06` | P2       | MariaDB migration lifecycle passed CI; a direct-query owner-constraint regression remains open.                                                                                              |
| `AI-B08` | P2       | Chat 429/500 error-envelope and no-store coverage remains open.                                                                                                                              |
| `AI-B09` | P2       | Chat tables are HMAC-only, but the raw guest token remains in Laravel session storage; production driver/encryption values are unverified and the confidentiality boundary needs a decision. |
| `AI-F04` | P3       | Scroll geometry and unread-state assertions remain open.                                                                                                                                     |
| `AI-F06` | P2       | iOS keyboard and safe-area acceptance remains open.                                                                                                                                          |
| `AI-F07` | P2       | Composer accessible naming and some 44px secondary targets remain open.                                                                                                                      |

`AI-F08` is mitigated by the release-blocking Chromium application smoke. Its
remaining cross-browser and visual limits are handled by the manual owner gate,
not treated as proof of Safari behavior.

Full evidence and severity reasoning remain in [AUDIT.md](AUDIT.md); this status
does not redefine those findings.

## Next task and acceptance gate

**Exact next action:** Wait for Mohamed acceptance; then start Phase 2
discovery. Do not implement Phase 2 or start Luna/model runtime work until
Mohamed completes the deployed Arabic/English mobile/desktop checklist in
[UX.md](UX.md), accepts the release, and approves a Phase 2 design.
