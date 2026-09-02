# FAQ editor in the admin

Date: 2026-09-02
Status: proposed, awaiting Mohamed's approval. Reviewed once (Opus, read-only) and revised.
Scope decision (2026-09-02): v1 covers the home-page FAQ only; the policy pages stay in the
language files and get an editor later if they ever change often enough to need one.

## Discovery

- **Users**: Mohamed (the Admin role is the only one holding the marketing permissions
  today) edits the questions; every storefront visitor reads them on the home page.
- **Look**: the admin's existing list + dialog pattern (coupons, categories); the storefront
  FAQ section keeps its current design.
- **Technology**: nothing new. A `faq_entries` table and `FaqEntry` model already exist and
  are unused (created with the marketing-content tables, no reader, no writer). No rich-text
  editor: answers are plain text with line breaks, rendered as text.
- **Existing data**: today the FAQ is the `store.faq.entries` array in `lang/ar/store.php`
  and `lang/en/store.php` (four entries), read by `HomeController` and rendered by
  `faq-section.tsx` as `<details>` items.
- **Accounts and access**: none.
- **Constraints**: bilingual, ordered, hideable; the home page must render exactly what it
  renders today on the deploy that ships this, with zero manual steps.
- **Success**: Mohamed changes a question from the admin and sees it on the home page on the
  next visit, in both languages, without a deploy.

## Purpose

Editing an FAQ today means a code change and a deploy. The store's answers change with EA's
seasons and with new services; that should be a two-minute edit in the admin.

## v1 scope

In:

- Admin page `/admin/marketing/faq` (nav child of Marketing, key `marketingFaq`; permissions
  `marketing.view` to read, `marketing.manage` to write): the entries in order, each with the
  Arabic question, a visibility badge, and edit / hide / delete actions; a "new question" button.
- Create and edit in a dialog: `question_ar`, `question_en`, `answer_ar`, `answer_en` (all
  required, questions up to 200 characters, answers up to 2000, plain text, line breaks kept).
- Ordering with up / down buttons on each row (writes `sort_order` for the two rows swapped).
- Hide / show (toggle `is_visible`), and delete behind a confirmation dialog (hard delete: the
  table holds public copy only, nothing a customer owns).
- Storefront reads visible entries by `sort_order` from the table; the section is hidden
  when there are none.
- A migration seeds the table when it is empty from a **literal copy** of the four current
  entries (Arabic and English) written inside the migration itself, so the live home page does
  not change on deploy. It must not call `trans()`: the deploy script runs `migrate` on the new
  release tree after the language-file entries are gone, and CI's `migrate:fresh` would meet
  the same empty array. The language-file entries are removed in the same PR (the table becomes
  the only source), and `public_id` is generated explicitly since the query builder bypasses the
  model's ULID hook.

Later, not in v1:

- Policy pages (privacy, returns, warranty, terms, EA backup codes) editing.
- Drag-and-drop ordering, categories, rich text, images, per-page FAQs.
- FAQ structured data (`FAQPage` JSON-LD); worth its own small change once the content is
  dynamic.
- The AI assistant's own FAQ topics (`resources/ai-assistant/knowledge/arab-ut.json`) overlap
  with the home FAQ and stay separate for now; editing the admin FAQ does not change what the
  assistant answers. Making those editable is a later, separate decision.

## Existing code this builds on

- `app/Models/FaqEntry.php` (extends `DomainModel`, casts `is_visible`, `sort_order`), table
  `faq_entries`: `public_id`, `question_ar`, `question_en`, `answer_ar`, `answer_en`,
  `sort_order` (default 0), `is_visible` (default true, indexed), timestamps.
- `HomeController` passes `faq` and `faqTranslations` inside `homeContent`;
  `resources/js/components/store/faq-section.tsx` renders `FaqEntry[]` (`{question, answer}`).
- Admin marketing pattern: `CouponsController` (list) + `CreateCouponController`,
  `UpdateCouponController`, `ToggleCouponStatusController` as separate JSON POST/PUT
  controllers under `/api/marketing/...`, each `can:marketing.manage`, actions under
  `app/Admin/Actions/*` that call `RecordStaffAudit`, presenters under
  `app/Admin/Presenters/*`, nav built in `AdminShell`, icons mapped in `admin-sidebar.tsx`,
  React pages with `useHttp` from `@inertiajs/react` and server-provided URL templates.
- Admin copy in `lang/ar/admin.php` / `lang/en/admin.php`; storefront copy in `store.php`.

## Technical approach

### Data

- Migration: no schema change; inserts the four entries from a literal array (both
  locales, `sort_order` 10, 20, 30, 40, explicit `Str::ulid()` public ids) **only when the table
  is empty**, inside a transaction. `down()` is a no-op (the rows are content, not schema).
- `FaqEntry` gains a `visible()` scope, `question($locale)` / `answer($locale)` helpers, and a
  `FaqEntryFactory` for tests.

### Storefront

- `app/Services/Content/StoreFaqReader::entries(string $locale)` returns
  `list<{id (public id), question, answer}>` ordered by `sort_order`, then `id`.
  `HomeController` uses it instead of the language array; the `FaqEntry` type in
  `store-content.ts` gains `id`, and `faq-section.tsx` keys on it. The home page hides the
  FAQ section when the list is empty.
- Answers keep their line breaks: `.store-faq details p` gets `white-space: pre-line`
  (React already escapes the text).
- The English fallback: if an English field is blank (cannot happen through the admin, which
  requires both), the Arabic text is shown rather than nothing.

### Admin

- Routes in the per-locale admin closure: `GET /marketing/faq` (`can:marketing.view`,
  name `marketing.faq`) → `FaqController` rendering `admin/marketing/faq` via presenter
  `AdminFaqPage` (rows: `id`, `questionAr`, `questionEn`, `answerAr`, `answerEn`,
  `sortOrder`, `isVisible`, `updatedAt`; URL templates for update, visibility, move, delete;
  create URL).
- `POST /api/marketing/faq` (create), `PUT /api/marketing/faq/{publicId}` (update),
  `POST /api/marketing/faq/{publicId}/visibility` (`visible` + `expectedVisible`, 409 through
  a typed `AdminFaqEntryVisibilityConflict` exception like categories),
  `POST /api/marketing/faq/{publicId}/move` (`direction: up|down`, swaps `sort_order` with
  the neighbour in a transaction, 422 at the ends), `DELETE /api/marketing/faq/{publicId}`.
  Authorization at all four layers the repository uses: route `can:marketing.manage`, the form
  request's `authorize()`, a controller `Gate::authorize`, and the action's own check. Actions
  are flat in `app/Admin/Actions/` (`CreateAdminFaqEntry`, `UpdateAdminFaqEntry`,
  `SetAdminFaqEntryVisibility`, `MoveAdminFaqEntry`, `DeleteAdminFaqEntry`), the list comes
  from `app/Admin/Queries/ListAdminFaqEntries`, and each action records a staff audit named
  `faq_entries.created` / `.updated` / `.visibility_changed` / `.moved` / `.deleted`. The delete
  audit carries all four text fields in its metadata, so the content is recoverable from the
  audit trail after a hard delete.
- Nav: the FAQ child is appended **last** under Marketing so the group's first URL stays the
  coupons page; no tile on `/admin/more` (reviews has none either). `AdminMoreTest` and the
  admin sidebar count in the storefront smoke spec are updated with it.
- Page `resources/js/pages/admin/marketing/faq.tsx` + components under
  `resources/js/components/admin/faq/`: header, "new question" button, ordered list (table on
  desktop, cards on phones) with the Arabic question, an English caption, the visibility
  badge, up / down / edit / hide / delete controls; create-edit dialog with four fields and
  inline validation; delete confirmation dialog. All through `useHttp` with the categories
  pattern (optimistic conflict, `router.reload({ only: ['entries'] })` after success).

This is a new admin screen, so the UI gate applies: the list and the dialog go on a
`/design` canvas before code.

## Privacy and safety

- Plain text only, rendered as text; no HTML is ever stored or interpreted.
- Deleting is behind a confirmation and audited; nothing customer-owned is involved.
- Only `marketing.manage` holders can write; `marketing.view` holders read.

## Testing

- Pest: the seed migration inserts the four entries once and leaves an already-filled table
  alone (tested by running the migration class directly); the home page renders the table's
  visible entries in order for both locales and hides the section when empty; create / update
  validation (lengths, all four fields required); move up / down swaps order and refuses at
  the ends; visibility toggle with 409 on conflict; delete with the text in the audit metadata;
  every audit entry; permission denial for `marketing.view`-only users and for Staff.
- Vitest: the admin page renders rows and badges, disables move at the ends, opens the dialog
  with the row's values, and hides write controls without `marketing.manage`.
- Playwright: the storefront smoke spec's admin sidebar link count grows by one; nothing
  else.

## Complexity

Medium. One seed migration, one reader, five admin endpoints, one admin screen.

## Needed from Mohamed

- Approval of this plan and of the admin screen on the canvas.

## Decisions taken in this design

1. FAQ only in v1; policy pages later.
2. Plain text answers with line breaks, no rich text.
3. Ordering by up / down buttons, no drag-and-drop.
4. Hard delete behind a confirmation.
5. Permissions reuse `marketing.view` / `marketing.manage`.
6. The language-file entries are removed once the table is the source, so there is one truth.
7. The AI assistant's FAQ knowledge stays separate for now (drift accepted; revisit later).
8. No `/admin/more` tile for the FAQ.
