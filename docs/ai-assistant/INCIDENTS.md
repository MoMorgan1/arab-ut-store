# Incidents

**Lifecycle:** Implemented
**Verified:** 2026-08-20

## 2026-08-19/20 — Blank storefront after deployment

- **Symptom:** The HTTP deployment succeeded, but the React/Inertia storefront was blank.
- **Technical cause:** Invalid persistent-layout resolution reached the production JavaScript bundle while server health stayed green.
- **Corrective commits:** `53f8d40` and `29f4fb3`.
- **Detection gap:** CI had no real-browser mount.
- **Prevention:** Chromium storefront smoke runs before Hostinger release packaging.
- **Remaining limit:** This does not prove Safari visuals or checkout behavior.
- **Release-gate verification:** The `tests` workflow for application release
  `fdba471af2fef38905581a309bf8b0e9119ab41b` completed successfully, including
  the Chromium smoke, before the production deployment workflow completed
  successfully.
- **Operational response:** Follow [AI assistant operations](OPERATIONS.md) for
  safe disable and health checks. Follow the project
  [rollback runbook](../operations/hostinger-rollback.md) if the application
  release must be reverted; production migrations are not reversed as part of
  an application rollback.
