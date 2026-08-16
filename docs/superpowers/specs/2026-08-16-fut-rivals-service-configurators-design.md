# FUT Champions and Rivals Service Configurators Design

**Status:** In-chat design approved by Mohamed on 2026-08-16; written review pending

**Complexity:** Medium

## Outcome

Arab UT gains two complete bilingual service configurators at the existing `/fut-champions` and `/rivals` routes. They preserve the current WordPress-derived service hierarchy, Thmanyah typography, warm near-black/cream/gold identity, and the Laravel storefront's cart, checkout, currency, authentication, and order foundations.

Both services are manual fulfillment products for PlayStation and PC only. Xbox is not offered. Prices are server-authoritative and backed by versioned schedules that a future admin interface can edit without redesigning the storefront or cart contract. The present slice builds the public pages, price calculation, conditional fulfillment fields, private squad-image upload, cart/order persistence, and owner-only post-order viewing. It does not build the admin UI, automatic refunds, post-order credential editing, or a new n8n workflow.

## Approved product rules

### FUT Champions

The customer chooses one target rank. Prior FUT matches do not change eligibility or price.

| Target | Price |
| --- | ---: |
| Rank 1 | SAR 220 |
| Rank 2 | SAR 190 |
| Rank 3 | SAR 170 |
| Rank 4 | SAR 150 |
| Rank 5 | SAR 130 |
| Rank 6 | SAR 100 |

- Standard orders are completed within the currently active FUT competition.
- Urgent service adds SAR 40 and is described as 24–36 hours from receipt of correct account data.
- If Arab UT misses the urgent window for an Arab UT-controlled reason, Mohamed may manually refund the SAR 40 surcharge.
- If the requested rank is not reached, Mohamed may manually refund the difference between the requested and achieved rank.
- No refund or price adjustment is automatic.

### Division Rivals

The customer chooses a current division and a strictly higher target. Supported points, from lowest to highest, are Division 7, 6, 5, 4, 3, 2, 1, and Elite. The UI begins at Division 7 and does not add explanatory copy for unsupported lower divisions.

| Promotion step | Price |
| --- | ---: |
| Division 7 to 6 | SAR 110 |
| Division 6 to 5 | SAR 120 |
| Division 5 to 4 | SAR 130 |
| Division 4 to 3 | SAR 140 |
| Division 3 to 2 | SAR 150 |
| Division 2 to 1 | SAR 160 |
| Division 1 to Elite | SAR 170 |

The total is the sum of every promotion step between the selected start and target. For example, Division 5 to Elite costs SAR 750. Rivals has no urgent option. Public copy says fulfillment usually takes one to three days depending on pressure and the number of divisions, without presenting that range as a guaranteed deadline. If the target is not reached, Mohamed may manually refund the value of incomplete promotion steps.

## Guided customer flows

Both pages use the same purposeful configurator shell and interaction language as the existing Coins and SBC experiences while keeping their service-specific steps.

### FUT flow

1. Choose PlayStation or PC.
2. For PC only, choose EA app or Steam.
3. Choose Rank 1 through Rank 6.
4. Choose standard or urgent.
5. Enter the conditional account details.
6. Upload one squad screenshot.
7. Review a secret-free summary and add the configured service to the cart.

### Rivals flow

1. Choose PlayStation or PC.
2. For PC only, choose EA app or Steam.
3. Choose the current division.
4. Choose a strictly higher target division; invalid same/lower targets are unavailable.
5. Enter the conditional account details.
6. Upload one squad screenshot.
7. Review the route, cumulative price, and a secret-free readiness summary before adding to the cart.

Changing an upstream choice clears only dependent incompatible state. For example, changing PC to PlayStation clears the PC launcher and Steam fields; changing the Rivals current division clears an invalid target. The live price and summary always derive from one normalized configurator state.

## Conditional fulfillment data

Exactly one credential shape is accepted for each platform/launcher combination.

### PC through EA app

- EA email.
- EA password.
- Exactly three distinct EA backup codes, each eight ASCII digits.
- One squad screenshot.

### PC through Steam

- EA email.
- EA password.
- Exactly three distinct EA backup codes, each eight ASCII digits.
- Steam username.
- Steam password.
- No Steam backup or Steam Guard code is collected.
- One squad screenshot.

### PlayStation

- PlayStation email.
- PlayStation password.
- Exactly three distinct PlayStation backup codes.
- Each PlayStation code is exactly six ASCII alphanumeric characters and is normalized to uppercase.
- Exactly three distinct EA backup codes, each eight ASCII digits.
- EA email and EA password are not collected for this path.
- One squad screenshot.

The pages link to Mohamed's approved tutorials:

- EA backup codes: `https://youtube.com/shorts/hNIW1ps_t3k?si=i9MR5izDKRhpRNjo`
- PlayStation backup codes: `https://youtu.be/fCAKsusuHR8?si=cYzL6fwszL4ExwPK`

The customer may reveal password fields locally while entering them. Summaries, URLs, Inertia props, analytics, normal logs, validation logs, and public cart configuration never contain passwords, backup codes, account emails, or private file paths.

## Pricing architecture

### Options considered

1. Represent every possible selection as a catalog variant. This works for six FUT ranks but creates dozens of Rivals start/target combinations and duplicates cross-platform pricing.
2. Hard-code the prices in PHP configuration until the admin page exists. This is quick but creates a second migration when prices become editable and weakens cart price history.
3. Use one versioned manual price schedule per service. This keeps the pricing normalized, supports atomic edits later, and lets checkout reject stale cart quotes. This is the approved approach.

A dedicated server-side pricing schedule stores:

- service type;
- active/available state;
- monotonically increasing price version;
- normalized rank prices or promotion-step prices;
- FUT urgent surcharge;
- timestamps.

PHP value objects parse and validate each schedule. FUT calculates target price plus the optional surcharge. Rivals walks the ordered division ladder and sums the selected edges. The add-to-cart action snapshots the public selection, total, schedule version, and quote timestamp. Checkout re-reads and recalculates the current schedule under a lock; a changed version or total fails closed and asks the customer to review the updated price.

The future admin page will edit the same schedules and availability flags. No admin controller, route, or interface is part of this slice.

## Cart and checkout integration

The two configurators use dedicated request, action, fingerprint, and endpoint boundaries instead of forcing their shapes through the generic catalog cart endpoint.

Public cart configuration contains only fulfillment-safe selections:

- service type;
- platform;
- PC launcher when applicable;
- FUT target rank and urgent flag, or Rivals start/target divisions;
- calculated schedule version and quote timestamp.

The cart item quantity is one. The price is the complete configured service price. An idempotency fingerprint covers normalized public configuration, normalized secret data, and the squad-upload identity so retries cannot silently change the order.

Checkout extends the existing `PlaceOrder` service-aware validation to require a valid encrypted secret and private squad image for FUT and Rivals, reprice against the current schedule, copy the safe configuration into the immutable order item, copy the encrypted payload into `OrderItemSecret`, and transfer the private attachment from cart ownership to order ownership inside the checkout transaction.

Existing Paylink behavior, payment minimums, receipt creation, order statuses, and the secret-free `order.paid` integration event remain unchanged. This slice makes no n8n workflow or payload change.

## Secret and image persistence

Account data extends the existing `CartItemSecret` and `OrderItemSecret` encrypted-array boundary. Service-specific parsing prevents one credential shape from being interpreted as another. Masked summaries expose only safe facts such as credential readiness, launcher, code counts, and screenshot presence.

The squad screenshot is not embedded in the encrypted JSON or stored on a public disk. A focused private fulfillment-attachment record owns:

- cart item or order item ownership, never both at once;
- a server-generated storage path;
- allowlisted MIME type;
- byte size;
- content hash;
- attachment kind (`squad_image`);
- timestamps.

Only JPEG, PNG, and WebP images up to 5 MB are accepted. The backend validates extension, MIME type, and decodability, generates the stored name, and ignores the supplied filename. Cart deletion removes an unconverted private upload. Checkout transfers attachment ownership without exposing its path. Order/account deletion follows the existing order-retention boundary.

This follows OWASP's allowlist and size-limit guidance for uploads and the existing Laravel encrypted-cast pattern. No password, backup code, or image contents are written to audit logs.

## Post-order customer access

The authenticated order owner can view the selected service configuration, squad screenshot, and complete account data after order creation. The normal order-page Inertia response continues to contain no secret values or private paths. Explicit owner-scoped endpoints return the secret payload or a short-lived private image response only after authorization.

- Responses use `Cache-Control: no-store`.
- The secret endpoint records a `SecretAccessLog` row with the customer, purpose, IP address, and timestamp.
- The page uses an explicit reveal action rather than serializing secrets on initial render.
- The customer may view but not update account data after checkout.
- Another customer cannot infer whether an order item, secret, or attachment exists.

A future admin UI may reuse separate staff-authorized endpoints. It is not built here. Until that UI exists, no public or staff listing is added merely to expose secrets.

## Copy and policy decisions

Arabic is the default and English is complete from launch. The product pages retain Mohamed's commercial intent while tightening the hierarchy and wording:

- do not sign in while Arab UT is playing;
- invalid account data restarts the stated urgent timing from receipt of correct data;
- customer-caused disconnections or password changes may affect the result;
- Arab UT does not sell, discard, or intentionally modify squad players;
- change account passwords after service completion;
- rank/division shortfalls are handled manually by Mohamed through a partial refund decision.

There is no additional mandatory platform-risk acknowledgement. Existing store terms and checkout requirements remain unchanged.

## WordPress-first presentation

Implementation first inspects and reproduces the available WordPress service links, service logos, product hierarchy, responsive behavior, and copy intent. The pages use official Arab UT assets, exact local Thmanyah fonts, warm near-black/cream/gold tokens, Arabic-first RTL behavior, and the shared header/footer/cart patterns already present in Laravel.

After parity, the implementation may refine spacing, hierarchy, field clarity, focus behavior, responsive layout, and error states without changing the approved brand identity or introducing a generic SaaS form. Any consequential visual or interaction deviation returns to Mohamed for approval.

## Error handling

- Missing or inactive pricing schedules make the service unavailable rather than guessing a price.
- Unsupported platforms, launchers, ranks, division routes, credential shapes, files, and unknown request fields return localized validation errors.
- Invalid files are removed if persistence fails.
- Idempotent add-to-cart retries return the original result; a changed payload with the same key conflicts.
- Stale prices fail checkout and preserve the cart for review.
- Secret and attachment endpoints are owner scoped, no-store, throttled, and return indistinguishable not-found responses across ownership boundaries.
- Upload, encryption, or database failures do not leave a purchasable partial cart item.

## Verification

### Backend

- Price-schedule parsing, FUT surcharge calculation, every Rivals route total, invalid routes, inactive schedules, and version changes.
- Platform/launcher-specific validation, unknown-field rejection, exact EA and PlayStation code formats, normalization, and distinctness.
- File extension/MIME/content/size checks, private storage, cleanup, and ownership transfer.
- Encrypted-at-rest credential payloads, safe public configuration, secret-free ordinary props/logs, and access logs.
- Owner isolation for cart and order secret/image reads, no post-order updates, no-store responses, and throttling.
- Idempotency, concurrency, stale-price checkout rejection, immutable order snapshots, and unchanged secret-free n8n event contract.

### Frontend

- Conditional platform and launcher steps.
- FUT rank and urgent pricing.
- Rivals target filtering and cumulative pricing.
- Conditional credential fields, tutorial links, password reveal, image validation, focus/error associations, submission locking, and secret-free summaries.
- Cart descriptions and post-order owner reveal flows.
- Arabic/English translation parity.

### Browser acceptance

Arabic RTL and English LTR are verified at 320 px, 390 px, 768 px, and 1440 px. Acceptance also requires keyboard completion, visible focus, 44 px touch targets, reduced-motion support, no horizontal overflow, correct private-image behavior, and no browser-console errors.

## Required access and release boundary

No new SaaS account, runtime dependency, payment credential, or n8n change is required. Production release later needs the existing Hostinger deployment path and a writable private storage location. No password, API key, or production secret is requested in chat.

The feature is not released merely because the pages render. Release requires focused and full automated gates, production build, migration lifecycle verification, bilingual browser acceptance, and a controlled low-value checkout rehearsal.

The present slice has no operator-facing order view because both the admin interface and the new n8n handling are explicitly deferred. The service schedules therefore remain unavailable for real production purchases by default even though the pages, APIs, cart, checkout, and owner order view are complete and testable. A later approved operator-access slice must provide either the admin order UI or the n8n delivery path before Mohamed enables live FUT or Rivals ordering. This prevents the storefront from accepting a paid manual-service order that Mohamed cannot retrieve and fulfill.

## Deferred work

- Admin pricing and availability UI.
- Automatic urgent or result-based refunds.
- Customer credential changes after checkout.
- Xbox support.
- Automatic FUT scheduling or deadline calculation.
- Guaranteed Rivals completion deadlines.
- Any new n8n payload or workflow.

## References

- Laravel 13 documentation: <https://laravel.com/docs/13.x/documentation>
- Laravel 13 filesystem temporary URLs: <https://api.laravel.com/docs/13.x/Illuminate/Filesystem/FilesystemAdapter.html>
- OWASP Secrets Management Cheat Sheet: <https://cheatsheetseries.owasp.org/cheatsheets/Secrets_Management_Cheat_Sheet.html>
- OWASP File Upload Cheat Sheet: <https://cheatsheetseries.owasp.org/cheatsheets/File_Upload_Cheat_Sheet.html>
- n8n execution data settings: <https://github.com/n8n-io/n8n-docs/blob/main/docs/deploy/host-n8n/configure-n8n/basic-configuration/use-environment-variables/executions.md>
