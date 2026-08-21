# Architecture

## Current stack

- Laravel 13 / PHP 8.3, Fortify session authentication, MariaDB production.
- Inertia 3 / React 19 / TypeScript / Vite 8 / Tailwind 4.
- Wayfinder routes, Radix-based UI primitives, database cache/queues.

## Placement

- Routes: planned `routes/admin.php`, loaded by `bootstrap/app.php`.
- Controllers/requests: `app/Http/Controllers/Admin` and
  `app/Http/Requests/Admin`.
- Application logic: `app/Admin/Actions`, `app/Admin/Queries`, and
  `app/Admin/Presenters`; reuse existing domain Actions when one exists.
- Authorization: planned `App\Enums\AdminPermission`, central access matrix,
  Laravel Gates/policies, route/request/action checks.
- UI: `resources/js/pages/admin`, `resources/js/layouts/admin-layout.tsx`, and
  focused components under `resources/js/components/admin`.
- Types: `resources/js/types/admin.ts` split only when domain size warrants it.
- Tests: `tests/Feature/Admin`, MariaDB process tests under `tests/Integration`,
  React tests under `resources/js/__tests__/admin`, and browser acceptance.

## Data flow

`Request → middleware → Form Request/policy → thin controller → Query/Action →
Presenter → Inertia/JSON`.

Queries select only fields needed by the page. Presenters serialize explicit
safe shapes. A secret-reveal JSON controller is the only Admin response allowed
to serialize decrypted credentials, and only after the security checks in the
design spec.

## Separation rules

- No separate Admin SPA, REST layer, repository pattern, or second design
  system.
- No business rules in controllers or React components.
- Do not reuse customer presenters if their ownership assumptions or response
  shapes are wrong for Admin; reuse domain primitives and create explicit Admin
  presenters instead.
- Do not add an empty navigation destination for a deferred feature.
