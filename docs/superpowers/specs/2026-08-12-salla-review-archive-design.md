# Salla Review Archive Design

**Status:** Approved by Mohamed on 2026-08-12

**Complexity:** Medium

## Outcome

All currently published Salla reviews are imported once into Laravel as a durable historical archive. The public homepage and reviews page then read only Laravel. After the import count and rendering are verified, the Salla review synchronization workflow is disabled. Future reviews will be collected by a separate local post-order review system when the local order lifecycle is ready; no fake submission form is added now.

## Safe archive projection

The importer stores only:

- stable source identity;
- rating from 1 to 5;
- public review text;
- explicitly public reviewer display name or localized anonymous fallback;
- published timestamp;
- source label `salla-import`;
- optional safe review type/reference needed for deduplication.

It never persists or exposes phone numbers, email addresses, customer objects, avatars, raw order identifiers, webhook metadata, or the raw Salla payload. Imported reviews are not marked verified because the new store has no local order-item evidence for them. Low ratings remain visible.

## Import behavior

- The n8n boundary projects the current published Salla dataset into the exact safe Laravel review contract before returning it.
- The one-time archive supports more than 500 reviews by deterministic bounded batches or one complete validated archive command; a partial/malformed batch cannot hide the last-good archive.
- Import is idempotent by stable Salla review identity.
- Nested related review text is normalized only when it is the public review content for the source record; private fields are discarded before Laravel receives the snapshot.
- A dry run reports safe counts, rating distribution, invalid/duplicate counts, and contains no customer PII.
- Apply runs transactionally and verifies source count after persistence.
- After homepage/full-page/browser verification, the recurring review workflow is disabled and the configured public endpoint is no longer required for customer rendering.

## Public UI

- Reuse the existing honest review section and `/reviews` pages.
- Show all imported published ratings in stable newest-first pagination; homepage shows a representative compact slice.
- Preserve the WP-refined black/gold equal-card treatment, readable text, star semantics, keyboard/focus behavior, RTL/LTR, and honest empty state.
- Never label imported rows verified. A future local review linked to genuine local order evidence may use that badge.

## Verification

- Tests cover more than 500 rows, deduplication, all rating values, PII rejection/projection, malformed archive rollback, idempotent rerun, source isolation, last-good retention, and zero HTTP during customer requests.
- A production-safe dry run records expected totals without raw records.
- Controlled apply proves database count, homepage cards, reviews pagination, no private fields in HTML/Inertia/logs, and no console/overflow issues.

