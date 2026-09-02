# Customer reviews after a completed order

Date: 2026-09-02
Status: approved by Mohamed on 2026-09-02 (all six decisions confirmed; screens approved on the canvas the same day; invitation delayed one hour at his request). Reviewed once (Opus, read-only) and revised.
Supersedes the deferral in `2026-08-12-salla-review-archive-design.md` ("future reviews will be
collected by a separate local post-order review system when the local order lifecycle is ready").

## Discovery

- **Users**: customers with a completed live order (account area); Mohamed and staff with the
  marketing permissions for moderation. Storefront visitors read the result on the home page
  and `/reviews`.
- **Look**: the account's existing card language and the storefront's `ReviewCard`; no new
  visual direction.
- **Technology**: nothing new. Laravel action + form request, queued mail notification,
  Inertia `useForm`, the admin list/toggle pattern already used by categories.
- **Existing data**: the `reviews` table already exists (Salla archive rows, `verified` false
  for all of them), `Review` model, `StoreReviewReader`, `OrderPaidNotification` as the mail
  template. Orders have `locale`, `completed_at`, `channel` (`salla_import` for the archive).
- **Accounts and access**: none beyond what exists; mail goes through the configured mailer.
- **Constraints**: one review per order; low ratings never reach the storefront; imported
  Salla orders are read-only and get no review.
- **Success**: new verified reviews appear on the storefront within a day of completion without
  staff work; low ratings are visible to staff the same day.

## Purpose

Every review on the storefront today is an imported Salla archive entry; nothing new arrives.
The store needs a steady stream of genuine, order-linked reviews, and the admin needs a place to
see and moderate them. The order lifecycle now has a terminal `Completed` state, which is the
trigger this was waiting for.

## v1 scope

In:

- **One review per order**, written by the order's owner, only once the order is `Completed`
  in this store (`completed_at` set and `channel !== 'salla_import'`). Salla-era imported orders
  are `Completed` from import and stay read-only, exactly as `TransitionAdminOrder` treats them.
- A review card on the account order page (`/my-account/orders/{order}`): five stars, an
  optional comment, one submit. After submitting, the card shows the stars and text back, read
  only.
- **Publication rule**: 4 and 5 stars publish immediately; 1 to 3 stars are stored hidden and
  wait for admin review. The storefront reader already shows only `rating >= 4`, so nothing low
  can leak even by mistake.
- The existing "verified order" badge (`طلب موثّق`) applies to every customer review.
- An email invitation **one hour after** an order becomes `Completed` (owner decision,
  2026-09-02: the customer has had time to see the result), sent once per order, linking to the
  order page.
- An admin page `/admin/reviews`: newest first, filter by visible / hidden, hide and re-show,
  the order number as a link to the order. Uses the existing `marketing.view` /
  `marketing.manage` permissions (reviews are marketing content; the tables were created in the
  marketing-content migration). The storefront keeps its `rating >= 4` filter, so 1 to 3 star
  rows are shown to staff as "not shown in the store" with no publish button; staff can only
  hide a 4 or 5 star review.

Later, not in v1:

- Editing or deleting a review by the customer.
- Replies from the store, photos, WhatsApp invitations, reminder emails.
- Per-item reviews (the schema keeps `order_item_id` for that future).

## Existing code this builds on

- `reviews` table: `public_id`, `user_id`, `order_item_id` (nullable), `reviewer_name`,
  `reviewer_location`, `rating` (1–5 enforced by trigger/check), `body_ar`, `body_en`, `source`,
  `source_key` + `external_id` (unique pair, both nullable, used by the importer only),
  `content_hash`, `is_visible` (default true), `published_at`. `Review` extends `DomainModel`
  (guarded `id`, `public_id`), relations `user()` and `orderItem()`.
- `StoreReviewReader::visible()`: `is_visible = true AND rating >= 4 AND published_at IS NOT
  NULL`, newest first. `project()` sets `verified` from `order_item_id !== null`. Homepage shows
  6, `/reviews` paginates 12. `ReviewCard` already renders the verified badge.
- `TransitionAdminOrder`: completion sets `completed_at` and calls `AccrueOrderCashback`
  synchronously inside a `DB::transaction`. No domain events exist in the app.
- `OrderPaidNotification`: queued, `afterCommit()`, `via ['mail']`, markdown mail with locale
  from `order.locale`. Tests in `tests/Feature/Mail`.
- Account pages post with Inertia `useForm` to server-provided URLs, controllers return a
  redirect (or JSON when `expectsJson()`), and the sonner toaster shows `flash.toast`. Routes use
  `whereUlid('order')` and the query scopes ownership.
- Admin list + toggle pattern: `CategoriesController` + `CategoryVisibilityController` (JSON
  POST with optimistic `expected` value, 409 on conflict), nav items built in `AdminShell`,
  icons mapped by key in `admin-sidebar.tsx`.
- Rate limiting: named limiters in `AppServiceProvider::configureRateLimiting()` or inline
  `throttle:N,1` on account POSTs.

## Technical approach

### Schema (one migration, reversible)

- `reviews.order_id`: nullable FK to `orders`, `nullOnDelete`, **unique** (SQLite and MySQL both
  allow many NULLs under a unique index, so archive rows are unaffected). This is the
  one-per-order guard. `down()` drops the index and column only; no `dropForeign` on SQLite.
- `orders.review_invited_at`: nullable timestamp. `Completed` is terminal, so this is not about
  a second completion; it makes the invitation idempotent across the transaction's three retry
  attempts and any future re-run.

### Submitting

- Route `POST /my-account/orders/{order}/review` (+ `/en` twin with
  `->defaults('locale', 'en')`), registered inside the existing account middleware group
  (`EnsureMyAccountEnabled`, `auth`, `EnsureActiveUser`, `NoStore`, `inertia.encrypt`), plus
  `whereUlid('order')` and `throttle:6,1`, name `account.orders.review.store`.
- Form request: `rating` integer 1–5 required; `body` string, nullable, max 600, control
  characters stripped, text only.
- Action `SubmitOrderReview`: loads the order scoped to the user, requires `completed_at` set
  and `channel !== 'salla_import'`, refuses if a review with this `order_id` exists (the unique
  index is the second line of defence), and builds the `Review` **field by field, never from the
  request array** (`DomainModel` guards only `id` and `public_id`, so `is_visible` and
  `published_at` would otherwise be fillable): `user_id`, `order_id`, `reviewer_name` (the
  customer's first name; falls back to the exact `store.reviews.anonymous_customer` string the
  reader maps), `rating`, `body_ar` or `body_en` by the order's locale, with the
  `rating_without_comment` placeholder when the comment is empty (as the importer does, so the
  card never renders blank and the reader's sort works), `source = 'customer'`,
  `is_visible = rating >= 4`, `published_at = now()` when visible else `null`. Runs in a
  transaction; a unique violation is reported as "already reviewed".
- Controller returns a redirect to the order page with `flash.toast` (success), or JSON for
  `expectsJson()`.

### Reading

- `ReadLiveOrder` selects `locale`, `completed_at` and `channel` too, and adds `review`:
  `null` when the order is not reviewable, otherwise
  `{ url, submitted: null | { rating, body, publishedAt, visible } }`.
- `StoreReviewReader::project()` sets `verified` when `order_id` or `order_item_id` is set.

### Invitation

- `ReviewInviteNotification` (queued with a one-hour delay, `afterCommit`, mail only, locale
  from the order; the queue worker that already delivers `OrderPaidNotification` handles it),
  markdown view `mail.review-invite`: one line of thanks, the order number, one button to the
  order page. Sent from `TransitionAdminOrder` right after the cashback accrual when the target
  is `Completed`, the order is not `salla_import`, and `review_invited_at` is null; the timestamp
  is set in the same transaction. `afterCommit` means a rolled-back attempt sends nothing.

### Order page card

- New component `OrderReviewCard` under `resources/js/components/account/`, mounted in
  `live-order.tsx` directly under the status bar when `order.review !== null`.
- Not yet reviewed: heading «قيّم طلبك», five star buttons (44px, keyboard: arrow keys move,
  Enter selects, `role="radiogroup"`), a textarea with a 600-character counter, submit button in
  the account's existing button style; disabled until a star is chosen.
- Reviewed: the stars and comment read only, plus one line: «شكراً، تقييمك ظاهر في المتجر» when
  visible, or «شكراً، سنراجع تقييمك» when hidden.
- Glass surface in the account's existing card language, Arabic first, RTL, no new
  dependencies.

### Admin page

- Routes registered in the per-locale admin closure like categories: `GET /reviews`
  (`can:marketing.view`) rendering `admin/reviews/index`, presenter `AdminReviewsPage`: rows
  with reviewer, rating, excerpt, order number (link), source, status, date; filter
  `status=all|visible|hidden`; pagination like the other lists.
- `POST /api/reviews/{publicId}/visibility` (`can:marketing.manage`) with `visible` and
  `expectedVisible`; re-showing sets `published_at` if empty; 409 on conflict like categories;
  refused for ratings below 4. The action records a `RecordStaffAudit` entry
  (`reviews.visibility_changed`) like every other admin action; nothing is audited for free.
- Nav: child of Marketing with key `marketingReviews` (camelCase like `marketingCoupons`), icon
  mapped in `admin-sidebar.tsx`.

This is two new interfaces (the order card and the admin page), so the UI gate applies: both go
on a `/design` canvas before code.

## Privacy and safety

- Only the customer's first name is shown, never email or phone; the anonymous label when the
  name is empty.
- Review text is stored as plain text, rendered as text (never HTML), capped at 600 characters.
- The customer can only reach their own orders; the admin action is audited through the existing
  staff audit for marketing changes.

## Testing

- Pest: submission allowed only for the owner of a completed live order; refused for a
  Salla-imported order, a cancelled or refunded order, a banned account, and a second time
  (action and unique index); posted `is_visible` / `published_at` are ignored; 5-star publishes,
  2-star stays hidden with null `published_at`; a rating-only review stores the placeholder;
  the `/en` twin works; reader shows the new review with `verified = true` and never a hidden
  one; the invitation is sent once and never for `salla_import`; admin list, filter, hide and
  re-show, refusal below 4 stars, conflict 409, audit entry, permission denied for
  `marketing.view` only users.
- Vitest: the card renders both states, stars are keyboard operable, submit disabled until a
  star is chosen, counter at 600.
- Playwright: complete an order through the admin, open the order as the customer, submit a
  review, see it on `/reviews`.

## Complexity

Medium. One migration, one notification, one action, two controllers, two screens.

## Needed from Mohamed

- Approval of the two screens on the canvas.
- Confirmation of the decisions below.

## Decisions taken in this design (confirmed 2026-09-02)

1. One review per order, not per item.
2. 4 and 5 stars publish automatically; 1 to 3 stars are stored for staff to read but never
   shown on the storefront (the reader's `rating >= 4` filter stays).
3. Salla-imported orders get no review and no invitation.
4. No time limit after completion; a customer can review a completed order whenever they open it.
5. One email invitation on completion; no reminders.
6. Reviews are moderated under the existing marketing permissions.
