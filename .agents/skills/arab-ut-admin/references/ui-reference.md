# UI/UX and engineering references

Owner directive (2026-08-21). These repositories are references, not codebases
to blindly copy. Respect the architecture already present in this project
first.

## Laravel / React architecture

- Reference: https://github.com/laravel/react-starter-kit
- The starter kit is the canonical Laravel + Inertia + React 19 + TypeScript +
  Tailwind + shadcn/ui + Radix stack. This repository already runs that stack;
  follow its component and theming patterns for Admin UI instead of inventing
  parallel ones.

## Admin UI/UX

- Reference: https://github.com/satnaing/shadcn-admin
- Use as the UI/UX reference for: sidebar, navigation, page layouts, data
  tables, filters, forms, command/search interfaces, settings, responsive
  behavior, and admin information density.
- Owner decisions (2026-08-21): the Admin follows the faithful shadcn-admin
  look in a single dark theme; Thmanyah typography and the Arab UT logo are
  retained; the surface remains English-only.
- Do NOT clone its architecture blindly (it is Next.js/TanStack Router; this
  project stays Laravel-owned with Inertia and server-projected data).

## Tables

- Reference: https://github.com/TanStack/table
- Prefer TanStack Table patterns for complex admin tables, supporting relevant
  combinations of: server-side pagination, search, filters, sorting, column
  visibility, row selection, bulk actions, and persistent query state where
  useful.
- Never fetch massive datasets into the browser; the database paginates,
  filters, and sorts (see tables.md for the server contract).
