# New Admin feature checklist

- [ ] Read the Admin design spec and relevant skill references.
- [ ] Identify actor roles and exact permission.
- [ ] Verify required Admin foundations already exist; implement the approved
      foundation phase before any dependent leaf feature.
- [ ] Define safe query/request/response fields and secret exclusions.
- [ ] Apply every binding password-confirmation, rate-limit, lock, idempotency,
      and audit requirement, then decide whether additional controls are needed.
- [ ] Reuse an existing domain Action or explain why a new Action is needed.
- [ ] Define legal success, validation, authorization, stale/conflict, provider,
      empty, and retry behavior.
- [ ] Define server search/filter/sort/pagination and required indexes.
- [ ] Check downstream presenters/UI whenever a new enum subtype or metadata
      meaning changes how an existing record must display.
- [ ] Write the failing behavior/security tests before production code.
- [ ] For UI, inspect WordPress/current repo parity and load required UI skills.
- [ ] Include Arabic/English, mobile/desktop, keyboard, focus, touch, reduced
      motion, loading, empty, error, success, and confirmation states.
- [ ] Update this skill/spec when an approved decision changes the source of
      truth.
