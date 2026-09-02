# Per-service reviews on the service pages

Date: 2026-09-02
Status: Discovery done; canvas approved by Mohamed on 2026-09-02 ("okay good"). Decisions
confirmed: reviews are attributed at the **service level** (not per SBC product), and a block
shows only when the service has **at least 3** published reviews. Reviewed once (Opus,
read-only, "ready with changes"); every finding folded in below. Four follow-up decisions were
taken by the lead under the "decide the rest and say so" rule and reported to Mohamed:
cancelled/refunded items do not count toward attribution; the `/reviews` summary is scoped by
the service filter only; `service=coins` is not accepted on `/reviews` in v1; the threshold
counts reviews **with a comment**.

## Discovery

- **Users**: storefront visitors deciding on one service (Rivals, FUT Champions, an SBC or
  Objectives product) see what customers of *that* service said; Mohamed sees the service in
  the admin reviews list.
- **Look**: the approved canvas: a glass section in the existing manual-section language,
  placed after the ordering configurator and before the "services for you" rail; summary
  (average, count, verified count), up to six review cards reusing `ReviewCard`, one
  "read all X reviews" link. Same on the SBC/Objectives product page after the product panel
  and before the related rail. Phone: horizontal rail, no auto motion (the lesson from #86).
- **Technology**: nothing new. One nullable `service_type` column on `reviews`, backfilled by
  the migration, written by `SubmitOrderReview`, read by `StoreReviewReader`.
- **Existing data**: reviews link to an order (`order_id`, customer reviews) and optionally to
  an order item (`order_item_id`, Salla archive rows imported through n8n with an
  `order_item_public_id`). Every `order_items` row carries `service_type`
  (`coins|sbc|objectives|rivals|fut_champions`), so the service is derivable for both kinds.
  Reviews with neither link (most of the archive) have no service and stay global only.
- **Accounts and access**: none.
- **Constraints**: the home page query budget (12) must not move; the storefront only shows
  4–5 star published reviews (unchanged); the reviews page and its filters keep working; the
  service pages must not gain a query when the block is hidden beyond the one count query.
- **Success**: on production, the Rivals page shows the Rivals block once three Rivals
  reviews exist; a new post-order review on a Rivals-only order appears there on the next
  visit; the SBC block never shows Coins reviews.

## Purpose

The reviews section on the home page mixes every service. A customer on the Rivals page wants
to know how Rivals orders went, not how coin deliveries went. Attribute reviews to a service
and show them where the decision is made.

## Decisions

1. **Attribution rule.** `reviews.service_type` is set when it is unambiguous, by one PHP
   helper `App\Services\Reviews\ResolveReviewService` used everywhere (no SQL twin):
   - `forOrderItem(OrderItem)` → that item's `service_type`.
   - `forOrder(Order)` → the distinct `service_type` of the order's items whose status is
     **not** `cancelled` or `refunded`; exactly one value → it, zero or several → `null`.
     An order with no items (or only cancelled ones) resolves to `null`.
   - Anything else → `null` (mixed orders, unlinked archive rows). A mixed order's review
     stays in the global set only; no pivot table, no double counting.
   The migration backfills by chunking over reviews that have an `order_id` or an
   `order_item_id` and calling the helper (the table is a few thousand rows; this removes the
   MariaDB/SQLite portability question entirely). The backfill is one-shot: nothing
   re-resolves later, and the denormalised value survives the FK going null when an order is
   purged. Re-imports must write `service_type` explicitly (a resolved value or `null`) so a
   stale service never survives an archive row losing its item link.
2. **Service level, not product level.** SBC and Objectives products share their service's
   reviews. Per-product attribution is a later change if the data ever justifies it.
3. **Visibility threshold.** The block renders only when the service has ≥ 3 reviews that
   pass the storefront rule (visible, rating ≥ 4, published) **and carry a comment** (body not
   equal to the rating-only placeholder in either locale). Rating-only reviews still count in
   the summary numbers once the block shows, and still appear in the six cards after the
   commented ones (home page ordering). Below the threshold the page is exactly as today.
4. **Placement.** Rivals and FUT Champions: after the tabs (options/guide) region and before
   `ManualServiceSuggestions`. SBC and Objectives product pages: after the product panel and
   before the related rail (or at the end when there is none). Coins has no page of its own
   (the home page already carries the global section); nothing for Coins in v1.
5. **Reviews page filter.** `/reviews?service=<key>` with the allow-list `rivals`,
   `fut_champions`, `sbc`, `objectives` (the four services with pages; `coins` is rejected
   like any unknown value). The "read all" link on each block points there. The page's
   `query()` helper emits `service`, `DEFAULT_FILTERS` / `filterUrl('all')` keep it, so the
   rating chips, sort links and pagination stay inside the service; only the "all services"
   chip clears it. A chip row above the existing filters shows the four services plus "all".
   **Behaviour change, stated on purpose:** with a service filter the summary (average,
   distribution, verified count) is scoped to that service; it stays unscoped by the rating /
   verified / comment filters as today. The reader's class docblock and the pinned test in
   `StoreReviewsTest` are updated to say so.
6. **Admin.** The reviews list gains a "Service" column (localised service title, dash when
   null or unrecognised) and a `service` filter select; one column in the existing select
   list and one `where`, no join. No editing. Audit unchanged.
7. **Copy.** Arabic first, Gulf-leaning, no Egyptian slang:
   - eyebrow: `تقييمات العملاء`
   - title: `ماذا يقول عملاء :service` / `What :service customers say`
   - hint: `تقييمات موثّقة من طلبات :service اكتملت عبر المتجر`
   - link: `اقرأ كل تقييمات :service` / `Read all :service reviews`
   - service names: a new `store.reviews.service_names` map in both locales with the short
     forms used in sentences (ar: rivals `الرايفلز`, fut_champions `فوت تشامبيونز`, sbc
     `التحديات`, objectives `المهام`; en: `Rivals`, `FUT Champions`, `SBC`, `Objectives`).
     The long page titles stay in `store.services.<key>.title`.

## Technical approach (plain language)

- **Migration** `2026_09_02_000004_add_service_type_to_reviews.php`: add nullable string
  `service_type` (SQLite ignores `after()`, fine) with a composite index
  `['service_type', 'is_visible', 'rating', 'published_at', 'id']` so the block's query does
  not scan; backfill through the helper in chunks inside a transaction; `down()` drops the
  index and the column.
- **Model / actions**: `Review` unchanged apart from nothing (string column, no cast).
  `SubmitOrderReview::store` sets `service_type` from `ResolveReviewService::forOrder`.
  `ImportStoreReviews` sets it explicitly in both projections: the n8n one from
  `forOrderItem` when the item resolves, otherwise `null`; the archive one always `null`.
- **Reader**: the service constraint is a plain `where('service_type', ...)` added inside the
  query chain **before** any raw ordering fragment (bindings order). New
  `service(ServiceType $service, string $locale): ?array` returns `null` under the threshold,
  otherwise `{average, count, distribution, verifiedCount, items}` with up to six items
  ordered like the home page. Queries: the grouped summary extended with a commented-count
  expression (so the threshold needs no extra query), then the items only when the threshold
  passes. `paginate()` accepts `service` in `$filters`, scopes the summary and the list by it,
  and keeps the rating / verified / comment filters list-only as today.
- **Controllers**: `ManualServiceProductController` and `CategoryProductController` add a
  `serviceReviews` prop: `null` or `{service, title, hint, readAllUrl, reviews, translations}`.
  `ReviewsController` validates `service` against the four-key allow-list and passes it
  through `filters` (and `filters.service` to the page).
- **UI**: new `ServiceReviewsSection` in `components/store/service-reviews-section.tsx`
  using `ReviewSummary` and `ReviewCard`. The rail is a plain
  `<ul className="store-reviews-rail" dir={direction}>` with no hook, no arrows, no dots
  (the existing rail CSS already gives native horizontal scroll on phones and the
  three-column rhythm; `dir` is set by hand since `trackProps` is not used). Mounted in
  `manual-service.tsx` and `catalog-product.tsx` only when the prop is non-null. Reviews
  page: service chip row, `service` in `ReviewFilterState`, `query()` and `DEFAULT_FILTERS`
  carry it.
- **Types**: `ServiceReviewsProps` in `types/store-content.ts`; `ReviewFilterState.service`;
  admin row gains `serviceType` and `serviceLabel`, admin query state gains `service`.
- **Tests**: Pest for `ResolveReviewService` (item, single-service order, mixed order,
  cancelled item excluded, empty order → null), the migration backfill (item-linked,
  single-service order, mixed order, unlinked row), `SubmitOrderReview` attribution, the
  n8n and archive imports writing `service_type`, reader threshold (three rating-only rows
  do **not** show the block; three commented ones do) and service filter, both page
  controllers (block present/absent, no Coins leak, no block query when hidden beyond the
  summary), reviews page `service` validation and scoped summary (update the pinned count
  test), admin column and filter; Vitest for `ServiceReviewsSection` (renders, hidden on
  null, link href, rail `dir`) and the reviews page keeping `service` through the rating
  chips, sort, pagination and clearing it only through "all services". Home page query
  budget test stays at 12 (the home page is untouched); `/sbc` listing budget untouched.

## Complexity

Medium. One column, one helper, one reader method, one component, two page mounts, one
filter, one admin column.

## Needed accounts, services, decisions

None outstanding. The two product decisions (service level, threshold of 3) were confirmed by
Mohamed on 2026-09-02; the four follow-ups raised by the review were decided by the lead as
recorded in the status line and can be reversed by Mohamed before the merge.

## Outline of the finished product

A Rivals customer opens `/rivals`, configures the order, and below sees "ماذا يقول عملاء
الرايفلز": 4.9 from 128 reviews, six cards from Rivals orders, and a link to
`/reviews?service=rivals` where the summary and the list are Rivals-only. The SBC product
page shows the SBC service block the same way. Services with fewer than three reviews look
exactly as today. In the admin, every review shows its service.
