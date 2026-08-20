# Live status

**Lifecycle:** Implemented
**Verified:** 2026-08-20

## Release snapshot

| Item                                      | Status                                                                                                                                                                                                                |
| ----------------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Last verified main/production release SHA | `8fb90688cf635547c4e6f20452aaf489c3edf215`                                                                                                                                                                            |
| Approved design revision SHA              | `eb3f0be25cdc2a90238e4f0827abdc61b83e3e7f` — documentation-only source for the current planned work                                                                                                                   |
| Latest verified tests workflow            | [Successful](https://github.com/MoMorgan1/arab-ut-store/actions/runs/32376102979): full PHP/frontend/static/Vite gate, MariaDB lifecycle, strengthened Chromium smoke, release packaging, and artifact-hygiene checks |
| Latest verified production deployment     | [Successful](https://github.com/MoMorgan1/arab-ut-store/actions/runs/32376545588)                                                                                                                                     |
| Read-only production HTML                 | 2026-08-20: `/`, `/en`, `/login`, `/en/login`, and `/cart` each returned HTTP 200 with current assets; `chat.enabled=true`; `chat.demoAssistant=true`                                                                 |
| Operational flags                         | `CHAT_ENABLED=true`; `CHAT_DEMO_ASSISTANT=true`; no AI runtime flag or OpenAI credential is deployed                                                                                                                  |
| P0/P1 release gate                        | Proceed; no unresolved P0 or P1 audit finding                                                                                                                                                                         |
| Owner acceptance                          | Phase 1 Completion requested: account launcher and conversation-lifetime issues remain to be implemented and manually accepted                                                                                        |

The verified production release, approved design revision, and later
`STATUS.md`-only commit are distinct. This status update records the design
commit as evidence: neither documentation commit changes the application
runtime nor claims a new browser verification of it.

## Current phase and approved design

Current phase: **Phase 1 Completion — design complete, implementation plan not
yet written**.

Mohamed approved the conversation-lifecycle policy and Hostinger-native direct
streaming direction. The binding design is
[`2026-08-20-ai-assistant-phases-1-2-design.md`](../superpowers/specs/2026-08-20-ai-assistant-phases-1-2-design.md).

It specifies:

- the mobile account launcher layering fix;
- one open conversation per owner;
- 24-hour inactivity close and seven-day reopen;
- 30-day guest and 180-day authenticated retention;
- explicit New conversation behavior;
- Phase 1 concurrency/error/accessibility hardening;
- OpenAI Responses API with `gpt-5.6-luna`, `store: false`, durable turns/runs,
  1.5-second coalescing, direct POST streaming, and authenticated-tester rollout
  for Phase 2.

No Phase 1 Completion or Phase 2 production code is implemented by the design
revision.

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

## Open decisions and next gate

- Inspect production `SESSION_DRIVER` / `SESSION_ENCRYPT` securely; any change
  that invalidates customer sessions requires separate approval.
- Before real Luna testing, Mohamed provisions an OpenAI API project with
  billing/model access and stores its key through the secure Hostinger path.
- The production-path fake-provider gate must prove Hostinger sends incremental
  deltas and recovers after disconnect. Buffering stops Phase 2 rollout.

**Exact next action:** Mohamed reviews the written design spec. After explicit
approval, write the Phase 1 Completion implementation plan. Implement, deploy,
and manually accept Phase 1 before writing or executing the Phase 2
implementation plan. Phase 2 public rollout remains a separate decision.
