# Live status

**Lifecycle:** Pre-deployment Phase 1 Completion handoff

**Verified locally:** 2026-08-20

## Current state

Phase 1 Completion is implemented in the local repository but is not marked deployed or production-implemented. The canonical implementation adds one-open conversation enforcement, lifecycle/retention maintenance, restart behavior, safe chat errors, account-surface hardening, and local authenticated browser coverage. The authoritative design is [2026-08-20-ai-assistant-phases-1-2-design.md](../superpowers/specs/2026-08-20-ai-assistant-phases-1-2-design.md).

| Evidence area                                                 | State                                                                                                                                             |
| ------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------- |
| Local routes, configuration, migrations, actions, and UI      | Verified from source in this handoff.                                                                                                             |
| Focused PHP chat/lifecycle gates                              | Passed locally; exact command and result are in the Task 7 report.                                                                                |
| Focused chat Vitest                                           | Passed locally; exact command and result are in the Task 7 report.                                                                                |
| Complete `php C:\temp\arabut-composer\composer.phar ci:check` | Passed locally: exit 0; Composer validation, Pint, PHPStan, Pest, Vitest, ESLint, Prettier, TypeScript, E2E TypeScript, and Vite build completed. |
| Complete `npm run test:e2e`                                   | Passed locally: exit 0; 7 Chromium tests passed in 42.2 seconds, including the authenticated account test in 27.7 seconds.                        |
| MariaDB CI migration and concurrency gate                     | Pending GitHub CI; no CI run is claimed.                                                                                                          |
| Production `SESSION_DRIVER` / `SESSION_ENCRYPT`               | Pending approved read-only inspection.                                                                                                            |
| Deployment and release packaging                              | Pending authorized push and deployment.                                                                                                           |
| Production route health                                       | Pending authorized read-only checks.                                                                                                              |
| Mohamed manual acceptance                                     | Pending deployed-release review.                                                                                                                  |

## Audit state

`AI-B03`, `AI-B04`, and `AI-B06` are locally implemented but await MariaDB verification. `AI-B05`, `AI-B08`, and `AI-F07` have local implementation evidence. `AI-F06` is partially addressed: the local fixture/code covers the layout work, while real iPhone/Safari safe-area and keyboard acceptance remains open. `AI-B09` remains open until the production session-storage confidentiality boundary is inspected and an approved decision is made. `AI-F04` remains an automated precision gap.

## External approval checkpoints

- Do not inspect or change production session configuration without the approved secure read-only path and a separate decision for any change.
- Do not push, deploy, or create a production synthetic account as part of the local documentation handoff.
- After an authorized release, verify `/`, `/en`, `/login`, `/en/login`, `/cart`, and an authenticated account route. Then Mohamed performs the [manual acceptance checklist](UX.md).

## Exact next action

An authorized release owner should run the complete local/CI gates, arrange the approved deployment, collect read-only production health evidence, and hand the deployed build to Mohamed for manual acceptance. Phase 2 remains blocked behind that acceptance.
