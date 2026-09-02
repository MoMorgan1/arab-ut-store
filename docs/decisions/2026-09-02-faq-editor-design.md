# FAQ editor in the admin

Date: 2026-09-02
Status: proposed, awaiting Mohamed's approval
Scope decision (2026-09-02): v1 covers the home-page FAQ only; the policy pages stay in the
language files and get an editor later if they ever change often enough to need one.

## Discovery

- **Users**: Mohamed and staff with the marketing permissions edit the questions; every
  storefront visitor reads them on the home page.
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
- A migration seeds the table from the current language-file entries when it is empty, so the
  live home page does not change on deploy. The language-file entries are removed afterwards
  in the same PR (the table becomes the only source).

Later, not in v1:

- Policy pages (privacy, returns, warranty, terms, EA backup codes) editing.
- Drag-and-drop ordering, categories, rich text, images, per-page FAQs.
- FAQ structured data (`FAQPage` JSON-LD); worth its own small change once the content is
  dynamic.

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

- Migration: no schema change; seeds `faq_entries` from `trans('store.faq.entries')` for
  both locales (pairing by index, `sort_order` 10, 20, 30…) **only when the table is empty**,
  inside a transaction. `down()` is a no-op (the rows are content, not schema).
- `FaqEntry` gains a `visible()` scope and `question($locale)` / `answer($locale)` helpers.

### Storefront

- `app/Services/Content/StoreFaqReader::entries(string $locale)` returns
  `list<{id, question, answer}>` ordered by `sort_order`, then `id`. `HomeController` uses it
  instead of the language array. The home page hides the FAQ section when the list is empty.
- The English fallback: if an English field is blank (cannot happen through the admin, which
  requires both), the Arabic text is shown rather than nothing.

### Admin

- Routes in the per-locale admin closure: `GET /marketing/faq` (`can:marketing.view`,
  name `marketing.faq`) → `FaqController` rendering `admin/marketing/faq` via presenter
  `AdminFaqPage` (rows: `id`, `questionAr`, `questionEn`, `answerAr`, `answerEn`,
  `sortOrder`, `isVisible`, `updatedAt`; URL templates for update, visibility, move, delete;
  create URL).
- `POST /api/marketing/faq` (create), `PUT /api/marketing/faq/{publicId}` (update),
  `POST /api/marketing/faq/{publicId}/visibility` (`visible` + `expectedVisible`, 409 on
  conflict like categories), `POST /api/marketing/faq/{publicId}/move` (`direction: up|down`,
  swaps `sort_order` with the neighbour in a transaction), `DELETE /api/marketing/faq/{publicId}`;
  all `can:marketing.manage`, form requests with the length rules above, actions under
  `app/Admin/Actions/Faq/*`, each recording a staff audit (`faq.created`, `faq.updated`,
  `faq.visibility_changed`, `faq.moved`, `faq.deleted`).
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

- Pest: the seed migration fills the table from the language files once and never again;
  the home page renders the table's visible entries in order for both locales and hides the
  section when empty; create / update validation (lengths, all four fields required); move up
  / down swaps order and refuses at the ends; visibility toggle with 409 on conflict; delete;
  audit entries; permission denial for `marketing.view`-only users.
- Vitest: the admin page renders rows and badges, disables move at the ends, opens the dialog
  with the row's values, and hides write controls without `marketing.manage`.
- Playwright: not needed for v1; the admin smoke test's sidebar count grows by one.

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
