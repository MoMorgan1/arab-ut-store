# Motion audit findings

Candidate findings from the 2026-09-13/14 read-only audit of all 185 component files (DeepSeek V4.1 Flash for 150, Gemini 3.8 Flash for 35). **Every line is a candidate read from source, not a browser-verified defect.** Line numbers are from the commits current at audit time and drift; the selector or element is the target. The plan that consumes this list is [`docs/decisions/2026-09-14-motion-system.md`](decisions/2026-09-14-motion-system.md).

Total: 577 findings.

## text-states-swap (96)

- `resources/js/components/account/account-order-row.tsx:106` — status pill on a live-polled order — **text-states-swap** — the status changes in place and is easy to miss.
- `resources/js/components/account/account-order-row.tsx:83` — "details"/"hide details" label swapping — **text-states-swap** — the toggle label flips instantly.
- `resources/js/components/admin/admin-attention-strip.tsx:49` — strip urgency surface — **text-states-swap** — danger/warning/neutral borders and tints snap the instant urgency changes.
- `resources/js/components/admin/admin-kpi-strip.tsx:140` — comparison badge label — **text-states-swap** — new/none/percentage copy replaces itself in place with no cross-fade.
- `resources/js/components/admin/admin-badge.tsx:33` — badge variant surface — **text-states-swap** — success/warning/danger colors hard-cut rather than easing between states.
- `resources/js/components/admin/admin-recent-orders.tsx:123` — order status badge swap — **text-states-swap** — a status change on reload snaps instead of crossfading.
- `resources/js/components/admin/categories/admin-categories-columns.tsx:194` — row visibility status badge — **text-states-swap** — the primary admin action flips the badge instantly, so the state change reads as a jump
- `resources/js/components/admin/categories/admin-categories-mobile-card.tsx:73` — mobile card status badge — **text-states-swap** — hide/restore flips the card badge with no transition
- `resources/js/components/admin/categories/admin-category-visibility-dialog.tsx:224` — confirm button swaps to spinner + in-progress label — **text-states-swap** — the processing label replaces the confirm label with no readable transition.
- `resources/js/components/admin/categories/admin-categories-toolbar.tsx:460` — filter Select trigger label — **text-states-swap** — changing the visibility/source filter swaps the trigger text with no transition.
- `resources/js/components/admin/coupons/admin-coupon-drawer.tsx:990` — Save button label in flight — **text-states-swap** — the POST gives no visible sense of progress before the drawer closes.
- `resources/js/components/admin/coupons/admin-coupon-drawer.tsx:495` — percent/fixed help line — **text-states-swap** — the help copy hard-cuts when the type toggles.
- `resources/js/components/admin/customers/admin-customer-contact-dialog.tsx:466` — Save button label and spinner — **text-states-swap** — the processing swap snaps with no cross-fade.
- `resources/js/components/admin/customers/admin-customer-wallet-adjust-dialog.tsx:402` — Adjust button label and spinner — **text-states-swap** — a money action gives abrupt processing feedback.
- `resources/js/components/admin/customers/admin-customer-status-dialog.tsx:289` — Confirm suspend/reactivate button label — **text-states-swap** — destructive confirm needs a smoother state cue.
- `resources/js/components/admin/customers/admin-customer-contact-dialog.tsx:422` — Phone help line swapping to error — **text-states-swap** — the same line changes content in place and jumps.
- `resources/js/components/admin/customers/admin-customers-pagination.tsx:64` — results summary swaps to selected text — **text-states-swap** — the "showing X" vs "N selected" text swaps abruptly.
- `resources/js/components/admin/faq/admin-faq-entry-dialog.tsx:398` — save button swaps to Spinner — **text-states-swap** — primary submit feedback cuts between label and spinner with no crossfade, so saving feels like a flicker.
- `resources/js/components/admin/faq/admin-faq-mobile-card.tsx:76` — visible/hidden status badge — **text-states-swap** — the status label swaps in place with no transition.
- `resources/js/components/admin/faq/admin-faq-entry-dialog.tsx:227` — dialog title between create and edit — **text-states-swap** — the reused dialog swaps its heading instantly when the entry changes.
- `resources/js/components/admin/faq/admin-faq-delete-dialog.tsx:132` — confirm button label to Spinner — **text-states-swap** — destructive confirm feedback snaps between states.
- `resources/js/components/admin/loyalty/admin-loyalty-tier-dialog.tsx:535` — save button swaps label to spinner — **text-states-swap** — the submit feedback pops in with no transition.
- `resources/js/components/admin/orders/admin-order-item-secret.tsx:269` — Copy/Copied button label — **text-states-swap** — pairs with the icon to confirm copy state
- `resources/js/components/admin/orders/admin-order-refund-control.tsx:398` — submit button spinner/label swap — **text-states-swap** — keeps the processing state from jumping the dialog footer
- `resources/js/components/admin/orders/admin-order-transition-controls.tsx:435` — cancel-confirm button label swap — **text-states-swap** — the processing label replaces the confirm label instantly, losing the action cue
- `resources/js/components/admin/orders/admin-order-item-secret.tsx:306` — retry button spinner/label swap — **text-states-swap** — communicates the retry is actually running
- `resources/js/components/admin/orders/admin-orders-columns.tsx:180` — order-status badge in table — **text-states-swap** — status is the column operators act on, so the flip should register.
- `resources/js/components/admin/orders/admin-orders-mobile-card.tsx:64` — order-status badge on phone card — **text-states-swap** — mobile operators must see the status change register.
- `resources/js/components/admin/orders/admin-orders-columns.tsx:234` — payment-status badge in table — **text-states-swap** — payment flips are the second key workflow signal.
- `resources/js/components/admin/orders/admin-orders-mobile-card.tsx:100` — payment-status badge on phone card — **text-states-swap** — same flip needs to read on mobile.
- `resources/js/components/admin/orders/admin-orders-pagination.tsx:64` — selected-rows count text — **text-states-swap** — footer selection feedback changes without transition.
- `resources/js/components/admin/pages/admin-page-editor-header.tsx:64` — "Saving"/"Save page" label — **text-states-swap** — state change reads clearly without a width jump.
- `resources/js/components/admin/pages/admin-page-editor-block-row.tsx:226` — block body swap (divider/heading/textarea) — **text-states-swap** — a type change visibly replaces the editor body.
- `resources/js/components/admin/products/admin-product-edit-dialog.tsx:524` — save button label-to-spinner swap — **text-states-swap** — the label flips instantly, so a slow save looks like nothing happened.
- `resources/js/components/admin/products/admin-product-visibility-dialog.tsx:206` — confirm label-to-processing swap — **text-states-swap** — the destructive action gives no in-flight cue beyond a static spinner.
- `resources/js/components/admin/products/admin-product-visibility-dialog.tsx:172` — reused dialog title swaps hide/restore — **text-states-swap** — the heading flips in place with no transition.
- `resources/js/components/admin/products/admin-variant-price-dialog.tsx:507` — save button label while processing — **text-states-swap** — "Save" flipping to "Saving" has no continuity.
- `resources/js/components/admin/products/admin-products-toolbar.tsx:533` — active filter chips row — **text-states-swap** — chips appear and vanish out of the toolbar abruptly.
- `resources/js/components/admin/products/admin-products-table.tsx:120` — empty state replacing table body — **text-states-swap** — rows disappear and the empty message snaps in.
- `resources/js/components/admin/reviews/admin-review-visibility-dialog.tsx:209` — confirm button swaps to spinner and processing label — **text-states-swap** — the busy state is easy to miss on a slow request.
- `resources/js/components/admin/reviews/admin-reviews-table.tsx:190` — empty-state row message — **text-states-swap** — rows and the no-reviews line swap in place with no cross-fade.
- `resources/js/components/admin/reviews/admin-reviews-table.tsx:220` — mobile empty-state card — **text-states-swap** — the mobile list jumps straight to the empty card.
- `resources/js/components/admin/settings/admin-service-pricing-section.tsx:1278` — live band preview text — **text-states-swap** — the `:from/:to/:step` sentence changes every keystroke unanimated.
- `resources/js/components/admin/settings/admin-team-section.tsx:523` — member status badge — **text-states-swap** — active/inactive swaps after reload with no change cue.
- `resources/js/components/chat/chat-handoff-banner.tsx:96` — banner swapping requested to active — **text-states-swap** — the agent joining looks static; only the copy silently changes
- `resources/js/components/chat/chat-cart-offer.tsx:637` — submit label swapping confirm to adding — **text-states-swap** — the label flips instantly while only the spinner moves
- `resources/js/components/chat/chat-cart-offer.tsx:422` — added text replacing the CTA — **text-states-swap** — the confirmation swaps in place without acknowledging the button it replaced
- `resources/js/components/chat/chat-handoff-banner.tsx:106` — banner title across states — **text-states-swap** — headline copy changes instantly between handoff states
- `resources/js/components/chat/chat-message-list.tsx:204` — load-older button label — **text-states-swap** — the label flips to the loading string in place with no swap.
- `resources/js/components/chat/chat-header.tsx:38` — restart tooltip label — **text-states-swap** — "New conversation" swaps to "Starting..." with no transition.
- `resources/js/components/chat/streamed-text.tsx:207` — caret removed when streaming ends — **text-states-swap** — the caret vanishes instantly at completion.
- `resources/js/components/configurator/coins/summary-step.tsx:124` — retry label reusing the add button — **text-states-swap** — add and retry look identical apart from the word, so the state change reads as no change.
- `resources/js/components/configurator/manual-services/fut-champions-configurator.tsx:368` — submit button idle-to-adding label — **text-states-swap** — the label swaps while the request runs with no feedback.
- `resources/js/components/configurator/manual-services/division-ladder.tsx:86` — from-to route summary line — **text-states-swap** — the route text rewrites in place instead of crossfading.
- `resources/js/components/configurator/manual-services/credentials-fields.tsx:96` — password field plain-text reveal — **text-states-swap** — bullets flip to text with no motion tying it to the eye toggle.
- `resources/js/components/configurator/manual-services/rank-picker.tsx:103` — urgent/standard ETA line — **text-states-swap** — the delivery-time promise flips instantly on the urgent toggle
- `resources/js/components/configurator/manual-services/rivals-configurator.tsx:616` — mode hint sentence — **text-states-swap** — the promotion/weekly explanation swaps in place with no transition
- `resources/js/components/configurator/manual-services/rivals-configurator.tsx:704` — order panel in-cart state — **text-states-swap** — the submit control snaps to “in cart” with no cross-fade
- `resources/js/components/configurator/manual-services/service-panel.tsx:125` — submit label swapping to "adding" — **text-states-swap** — the primary money action gives no feedback that the click registered.
- `resources/js/components/configurator/manual-services/service-slider.tsx:39` — selected value label changing — **text-states-swap** — the rank/label swaps abruptly on every notch.
- `resources/js/components/store/catalog/catalog-add-control.tsx:174` — add button label idle/loading/success — **text-states-swap** — the label hard-swaps at the exact moment the customer is watching the button.
- `resources/js/components/ui/select.tsx:22` — select value text swap — **text-states-swap** — choosing an option replaces the trigger label instantly with no swap cue
- `resources/js/pages/account/live-order.tsx:465` — pay button label flipping to loading — **text-states-swap** — the money moment gives no kinetic feedback that a payment is starting
- `resources/js/pages/account/live-order.tsx:443` — cancel-confirm button to "cancelling" — **text-states-swap** — the confirm action looks frozen mid-cancel
- `resources/js/pages/account/live-order.tsx:911` — copy button copy→copied label — **text-states-swap** — no visible confirmation the credential was copied
- `resources/js/pages/account/orders.tsx:231` — empty-state heading swapping to search — **text-states-swap** — the no-results message changes silently
- `resources/js/pages/account/profile.tsx:417` — phone row action label changes identity — **text-states-swap** — add/verify/change swapping to cancel is abrupt
- `resources/js/pages/admin/marketing/page-editor.tsx:404` — locale fields swap on tab change — **text-states-swap** — title/subtitle values change in place without a swap cue
- `resources/js/pages/admin/overview.tsx:41` — attention-strip severity change — **text-states-swap** — label and count swap urgency between danger/warning/ok with no transition.
- `resources/js/pages/admin/products/show.tsx:557` — Arabic description paragraph — **text-states-swap** — edited copy hard-swaps with no transitional state
- `resources/js/pages/admin/products/show.tsx:469` — storefront status badge text — **text-states-swap** — visible/hidden label flips abruptly after visibility changes
- `resources/js/pages/auth/login.tsx:369` — countdown switching to resend button — **text-states-swap** — the label-to-button swap is jarring at zero seconds.
- `resources/js/pages/auth/two-factor-challenge.tsx:32` — authenticator/recovery input field — **text-states-swap** — `key={fieldName}` hard-remounts the field with no crossfade between modes
- `resources/js/pages/auth/two-factor-challenge.tsx:66` — code/recovery mode toggle label — **text-states-swap** — the link text flips instantly, easy to miss the mode change
- `resources/js/pages/store/cart.tsx:766` — checkout button label — **text-states-swap** — the label flips checkout to loading with no transition next to the pay action.
- `resources/js/pages/store/cart.tsx:855` — dock action button label — **text-states-swap** — four different labels swap in the primary pay button with no motion.
- `resources/js/pages/store/cart.tsx:1097` — phone to code stage — **text-states-swap** — the verification form swaps wholesale right after "send code".
- `resources/js/pages/store/category.tsx:243` — empty search result — **text-states-swap** — the empty message replaces the grid with no swap.
- `resources/js/pages/store/cart.tsx:997` — undo bar message — **text-states-swap** — removed, duplicate and failed messages swap in place with no transition.
- `resources/js/components/admin/faq/admin-faq-table.tsx:120` — visibility status badge text label — **text-states-swap** — status label cuts instantly between visible and hidden states without smooth transition feedback
- `resources/js/components/admin/products/admin-variant-revert-dialog.tsx:225` — confirm revert action button label — **text-states-swap** — prevents jarring text snap when initiating variant revert
- `resources/js/components/store/cart-added-notice.tsx:315` — item name and price label — **text-states-swap** — product label and price jump abruptly when consecutive items are added
- `resources/js/components/ui/badge.tsx:41` — dynamic text label inside the badge — **text-states-swap** — status wording swaps instantly without cross-dissolve during order status transitions
- `resources/js/pages/admin/confirm-2fa.tsx:126` — verification code input field label — **text-states-swap** — smooths the sudden text replacement when toggling between verification modes
- `resources/js/pages/admin/confirm-2fa.tsx:189` — alternate authentication mode link button label — **text-states-swap** — prevents jarring text jump when alternating between code types
- `resources/js/pages/admin/customers/index.tsx:182` — failed query retry trigger button — **text-states-swap** — confirms retry submission before loading resolves
- `resources/js/pages/admin/customers/show.tsx:208` — customer header status badge label text — **text-states-swap** — prevents harsh text snapping during account status switches
- `resources/js/pages/admin/customers/show.tsx:316` — account status section badge label text — **text-states-swap** — smooths status label swap when toggling account access
- `resources/js/pages/admin/customers/show.tsx:328` — suspend and reactivate account action button — **text-states-swap** — clarifies the action inversion after status changes take effect
- `resources/js/pages/admin/customers/show.tsx:198` — primary customer display name heading text — **text-states-swap** — smoothly updates customer name after contact information edit
- `resources/js/pages/admin/customers/show.tsx:406` — customer identity section email address text — **text-states-swap** — confirms email modifications without abrupt content jumping
- `resources/js/pages/admin/customers/show.tsx:423` — customer identity section phone number text — **text-states-swap** — softens transitions when adding or modifying customer phone numbers
- `resources/js/pages/admin/marketing/coupons/show.tsx:282` — toggle action button state label text — **text-states-swap** — button text swaps instantly between actions without a crossfade
- `resources/js/pages/admin/marketing/coupons/show.tsx:708` — duplicate dialog submit button label text — **text-states-swap** — button wording updates instantly without cross-fading into pending status
- `resources/js/pages/admin/marketing/coupons/show.tsx:235` — coupon header status badge pill container — **text-states-swap** — badge variant and label change rigidly upon status mutation
- `resources/js/pages/admin/settings.tsx:125` — admin logout button action label text — **text-states-swap** — provides explicit text confirmation during logout network transit

## number-pop-in (80)

- `resources/js/components/account/account-metric.tsx:43` — wallet balance and open-order value — **number-pop-in** — the figure swaps in place after a top-up or order with no emphasis, so the change is easy to miss.
- `resources/js/components/account/order-review-card.tsx:146` — rating value updating on star click — **number-pop-in** — the chosen number changes with no emphasis.
- `resources/js/components/admin/admin-attention-strip.tsx:100` — attention count badge — **number-pop-in** — the headline count jumps as orders start needing attention, so the change reads as a silent refresh.
- `resources/js/components/admin/admin-kpi-strip.tsx:66` — captured revenue figure — **number-pop-in** — the primary money metric swaps without emphasis on refresh.
- `resources/js/components/admin/admin-kpi-strip.tsx:83` — total orders figure — **number-pop-in** — order volume updates land with no visual signal.
- `resources/js/components/admin/admin-kpi-strip.tsx:122` — new customers figure — **number-pop-in** — growth metric changes invisibly between loads.
- `resources/js/components/admin/admin-kpi-strip.tsx:100` — orders in flight figure — **number-pop-in** — live workload count should pop when it moves.
- `resources/js/components/admin/admin-queue-health.tsx:83` — failed jobs count — **number-pop-in** — a rising failure count should pull the eye on reload.
- `resources/js/components/admin/admin-queue-health.tsx:117` — stalled jobs count — **number-pop-in** — stalled work increasing should read as a change, not a silent repaint.
- `resources/js/components/admin/admin-queue-health.tsx:144` — failed events count — **number-pop-in** — the same for failed webhook/failed-event totals.
- `resources/js/components/admin/categories/admin-categories-columns.tsx:159` — visible products count — **number-pop-in** — restoring a category changes this count and the update is invisible
- `resources/js/components/admin/categories/admin-categories-mobile-card.tsx:101` — mobile visible products count — **number-pop-in** — the mobile equivalent updates silently
- `resources/js/components/admin/categories/admin-categories-pagination.tsx:111` — current page number — **number-pop-in** — page changes update the label in place with no motion
- `resources/js/components/admin/categories/admin-categories-pagination.tsx:65` — selected rows counter — **number-pop-in** — selection count changes silently as boxes are ticked
- `resources/js/components/admin/coupons/admin-coupons-columns.tsx:337` — used-count figure — **number-pop-in** — reload updates the number with no emphasis.
- `resources/js/components/admin/customers/admin-customer-wallet-adjust-dialog.tsx:321` — Live halalah conversion help text — **number-pop-in** — the number recomputes on each keystroke with zero emphasis.
- `resources/js/components/admin/customers/admin-customers-columns.tsx:208` — Wallet balance figure after adjustment — **number-pop-in** — the updated balance never calls attention to itself.
- `resources/js/components/admin/customers/admin-customers-pagination.tsx:130` — current page number updates — **number-pop-in** — the page counter hard-cuts on navigation instead of reading as a change.
- `resources/js/components/admin/faq/admin-faq-entry-dialog.tsx:319` — answer character counter number — **number-pop-in** — the counter changes every keystroke with no emphasis.
- `resources/js/components/admin/loyalty/admin-loyalty-tier-dialog.tsx:476` — live basis-point helper number — **number-pop-in** — the recomputed value changes silently as the admin types.
- `resources/js/components/admin/orders/admin-order-refund-control.tsx:341` — live reason character counter — **number-pop-in** — a large refund reason is easy to overrun without a visible count changing
- `resources/js/components/admin/orders/admin-orders-pagination.tsx:140` — current page number readout — **number-pop-in** — confirms the page actually advanced after navigation.
- `resources/js/components/admin/orders/admin-orders-columns.tsx:253` — order total amount cell — **number-pop-in** — refunds update the total in place with no highlight.
- `resources/js/components/admin/orders/admin-orders-mobile-card.tsx:77` — mobile order total amount — **number-pop-in** — refunded totals change silently on the phone card.
- `resources/js/components/admin/orders/admin-orders-pagination.tsx:71` — showing-range results text — **number-pop-in** — the visible range jumps between pages with no cue.
- `resources/js/components/admin/pages/admin-page-editor-block-row.tsx:73` — block index badge on reorder — **number-pop-in** — the number updates after move up/down.
- `resources/js/components/admin/products/admin-products-mobile-card.tsx:119` — sort-order number updates — **number-pop-in** — an edited value changes with no acknowledgment.
- `resources/js/components/admin/products/admin-products-columns.tsx:226` — sort-order number updates — **number-pop-in** — reordering value changes silently in the cell.
- `resources/js/components/admin/products/admin-products-columns.tsx:216` — variants-count number — **number-pop-in** — the count updates without feedback when a variant is added.
- `resources/js/components/admin/products/admin-products-pagination.tsx:108` — page number counter in pagination — **number-pop-in** — the page value updates without acknowledging the change.
- `resources/js/components/admin/products/admin-products-pagination.tsx:63` — selected rows summary text — **number-pop-in** — the selection count changes silently.
- `resources/js/components/admin/reviews/admin-reviews-pagination.tsx:95` — page counter text updating in place — **number-pop-in** — the changed page number is easy to lose track of.
- `resources/js/components/admin/settings/admin-service-pricing-section.tsx:1449` — live rank price preview — **number-pop-in** — the formatted SAR value updates with no acknowledgement.
- `resources/js/components/admin/settings/admin-security-section.tsx:749` — trusted device count — **number-pop-in** — the count resetting to zero after forget is invisible.
- `resources/js/components/chat/chat-composer.tsx:105` — character counter appearing — **number-pop-in** — crossing 3500 characters reveals the count abruptly
- `resources/js/components/chat/chat-launcher.tsx:191` — unread count on launcher badge — **number-pop-in** — a new count replaces the old with no pop, so arrivals go unnoticed.
- `resources/js/components/configurator/manual-services/division-ladder.tsx:88` — division-steps count badge — **number-pop-in** — the steps count changes with no numeric emphasis.
- `resources/js/components/configurator/manual-services/division-ladder.tsx:74` — target slider snapped to Elite — **number-pop-in** — the target division and price jump without emphasis.
- `resources/js/components/configurator/manual-services/service-slider.tsx:46` — price updating with the slider — **number-pop-in** — the keyed remount has no keyframe, so the new price snaps.
- `resources/js/components/configurator/manual-services/service-panel.tsx:180` — panel total updating — **number-pop-in** — the pop only runs on mount, later totals snap.
- `resources/js/components/configurator/manual-services/service-panel.tsx:247` — phone dock total updating — **number-pop-in** — the dock mirrors the panel total but never pops.
- `resources/js/components/store/catalog/sbc-product-configurator.tsx:579` — price on completion-count change — **number-pop-in** — the decision number is replaced without emphasis as the slider moves.
- `resources/js/components/store/reviews-section.tsx:277` — average rating number — **number-pop-in** — the headline score appears statically with no emphasis as the section reveals
- `resources/js/components/store/hero-stats.tsx:77` — hero stat value — **number-pop-in** — the count settles flat on its final value instead of landing
- `resources/js/pages/account/orders.tsx:208` — filter count badge — **number-pop-in** — counts switch with no emphasis
- `resources/js/pages/account/profile.tsx:589` — resend countdown number — **number-pop-in** — the ticking number is easy to miss
- `resources/js/pages/account/overview.tsx:262` — loyalty percent figure — **number-pop-in** — the percentage lands with no emphasis
- `resources/js/pages/account/overview.tsx:162` — wallet metric value — **number-pop-in** — the money value loads flat on first paint
- `resources/js/pages/account/overview.tsx:185` — open-orders metric count — **number-pop-in** — the count loads flat on first paint
- `resources/js/pages/account/overview.tsx:117` — resend countdown number — **number-pop-in** — the countdown ticks as static text
- `resources/js/pages/admin/marketing/loyalty.tsx:155` — total-customers KPI number — **number-pop-in** — value changes on reload without emphasis
- `resources/js/pages/admin/marketing/page-editor.tsx:454` — block-count in heading — **number-pop-in** — count changes as blocks add/remove but is easy to miss
- `resources/js/pages/admin/marketing/loyalty.tsx:136` — cashback KPI amount — **number-pop-in** — updated figure swaps silently
- `resources/js/pages/admin/marketing/loyalty.tsx:173` — per-tier customer-count badges — **number-pop-in** — counts change after reload with no cue
- `resources/js/pages/admin/orders/show.tsx:462` — order grand-total figure — **number-pop-in** — makes the money changed by a refund unmistakable
- `resources/js/pages/admin/overview.tsx:61` — KPI totals on range switch — **number-pop-in** — figures swap instantly with no emphasis, easy to miss the period changed.
- `resources/js/pages/admin/products/show.tsx:679` — variant effective price figure — **number-pop-in** — the money value the admin came to change updates silently after an override write
- `resources/js/pages/admin/products/show.tsx:732` — price version counter — **number-pop-in** — the version bump is the only confirmation the write advanced
- `resources/js/pages/admin/products/show.tsx:600` — variants count label — **number-pop-in** — the count changes in place after variant mutations
- `resources/js/pages/auth/login.tsx:358` — resend countdown seconds — **number-pop-in** — the ticking number changes with no emphasis.
- `resources/js/pages/auth/verify-email.tsx:67` — countdown seconds number — **number-pop-in** — ticks silently each second, so the wait reads as frozen
- `resources/js/pages/store/cart.tsx:652` — checkout summary payable total — **number-pop-in** — the figure the customer is about to pay snaps when wallet or coupon changes it.
- `resources/js/pages/store/cart.tsx:826` — dock sticky amount — **number-pop-in** — the primary pay figure updates with no feedback on the phone viewport.
- `resources/js/pages/store/cart.tsx:692` — repricing old and new totals — **number-pop-in** — the two changed values swap instantly, easy to miss.
- `resources/js/pages/store/catalog-product.tsx:150` — variant price value — **number-pop-in** — changing the option re-prices the page instantly.
- `resources/js/pages/store/category.tsx:346` — catalog card price — **number-pop-in** — switching platform re-prices the card with no transition.
- `resources/js/components/account/wallet-ledger.tsx:64` — transaction credit or debit delta amount — **number-pop-in** — draws immediate focus to the balance change amount
- `resources/js/components/account/wallet-ledger.tsx:87` — resulting wallet balance after transaction record — **number-pop-in** — reinforces running balance calculation after each transaction
- `resources/js/components/admin/admin-unread-badge.tsx:144` — unread support count numeric label — **number-pop-in** — counter updates abruptly without scale or vertical pop when tickets increment
- `resources/js/components/admin/faq/admin-faq-table.tsx:96` — FAQ position order sequence number — **number-pop-in** — numeric sequence updates abruptly when reordering entries instead of confirming rank change
- `resources/js/components/one-time-code-field.tsx:167` — individual OTP cell digit on entry — **number-pop-in** — reassures mobile shoppers that each keypad tap registered properly
- `resources/js/components/store/cart-added-notice.tsx:282` — cart item count and total subline — **number-pop-in** — shoppers receive no visual indicator when coin count increments on repeat adds
- `resources/js/components/ui/badge.tsx:41` — numeric count label inside the badge — **number-pop-in** — counter updates jump instantly without pop feedback during cart or alert quantity changes
- `resources/js/pages/admin/customers/index.tsx:218` — customer table pagination summary footer — **number-pop-in** — draws attention to updated result counts on page change
- `resources/js/pages/admin/customers/show.tsx:700` — customer wallet summary balance value display — **number-pop-in** — gives immediate tactile confirmation when wallet funds change
- `resources/js/pages/admin/customers/show.tsx:672` — total wallet entries count metric display — **number-pop-in** — signals ledger count increment following a balance adjustment
- `resources/js/pages/admin/marketing/coupons/show.tsx:340` — redemptions count primary performance metric figure — **number-pop-in** — key performance count renders statically without numeric entrance emphasis
- `resources/js/pages/admin/marketing/coupons/show.tsx:378` — unique customers performance metric display figure — **number-pop-in** — customer count loads statically instead of popping into view
- `resources/js/pages/admin/marketing/coupons/show.tsx:397` — attributed revenue total performance metric figure — **number-pop-in** — monetary total appears without count-up animation celebrating campaign revenue
- `resources/js/pages/admin/marketing/coupons/show.tsx:419` — total discount given performance metric figure — **number-pop-in** — discount tally appears without numeric pop-in to convey promotional impact

## error-state-shake (68)

- `resources/js/components/account/order-review-card.tsx:176` — rating validation error text — **error-state-shake** — an invalid submit only prints a line.
- `resources/js/components/admin/categories/admin-category-visibility-dialog.tsx:198` — visibility failure alert — **error-state-shake** — a failed hide/restore snaps in silently and is easy to miss.
- `resources/js/components/admin/coupons/admin-coupon-drawer.tsx:365` — inline coupon-code error — **error-state-shake** — the 422 reason lands silently under the field.
- `resources/js/components/admin/customers/admin-customer-contact-dialog.tsx:300` — First/last/email validation errors — **error-state-shake** — errors appear with no failure signal.
- `resources/js/components/admin/customers/admin-customer-status-dialog.tsx:263` — Reason-required validation error — **error-state-shake** — a blocked submit lands silently.
- `resources/js/components/admin/customers/admin-customer-wallet-adjust-dialog.tsx:308` — Amount validation error — **error-state-shake** — an invalid amount appears without a cue.
- `resources/js/components/admin/customers/admin-customer-wallet-adjust-dialog.tsx:365` — Reason validation error — **error-state-shake** — an over/under-length reason appears unnoted.
- `resources/js/components/admin/customers/admin-customer-contact-dialog.tsx:440` — General failure message — **error-state-shake** — server failure at the footer goes unnoticed.
- `resources/js/components/admin/faq/admin-faq-entry-dialog.tsx:264` — inline error under Arabic question — **error-state-shake** — invalid input appears with no motion cue pulling the eye to the field.
- `resources/js/components/admin/faq/admin-faq-entry-dialog.tsx:300` — inline error under English question — **error-state-shake** — validation feedback lands abruptly on the mirrored field.
- `resources/js/components/admin/faq/admin-faq-delete-dialog.tsx:110` — destructive Alert on failed delete — **error-state-shake** — a failed destructive call surfaces with no attention cue.
- `resources/js/components/admin/faq/admin-faq-entry-dialog.tsx:232` — general server error line — **error-state-shake** — the only failure message appears with no motion.
- `resources/js/components/admin/loyalty/admin-loyalty-tier-dialog.tsx:324` — inline error under Arabic name — **error-state-shake** — an invalid field fails silently with an abrupt text insert.
- `resources/js/components/admin/loyalty/admin-loyalty-tier-dialog.tsx:412` — threshold error under spend input — **error-state-shake** — rank-one and range failures appear with no failure cue.
- `resources/js/components/admin/loyalty/admin-loyalty-tier-dialog.tsx:509` — form-level failure message — **error-state-shake** — a server error surfaces unannounced above the footer.
- `resources/js/components/admin/orders/admin-order-refund-control.tsx:364` — reason-required validation message — **error-state-shake** — draws the eye to why the refund submit is blocked
- `resources/js/components/admin/orders/admin-order-item-secret.tsx:287` — reveal-failure alert replacing the spinner — **error-state-shake** — a failed credential load is as important as the load itself
- `resources/js/components/admin/orders/admin-order-refund-control.tsx:304` — dialog error alert above the reason field — **error-state-shake** — provider/rate-limit failures appear without drawing attention
- `resources/js/components/admin/pages/admin-page-editor-block-row.tsx:262` — inline block error — **error-state-shake** — flags the invalid block when validation fails.
- `resources/js/components/admin/products/admin-product-edit-dialog.tsx:331` — required-field error message appears — **error-state-shake** — errors pop in with no cue showing which field failed.
- `resources/js/components/admin/products/admin-product-edit-dialog.tsx:293` — general error banner appears — **error-state-shake** — a failed save flashes past without drawing the admin's eye.
- `resources/js/components/admin/products/admin-product-visibility-dialog.tsx:178` — destructive error alert appears — **error-state-shake** — a failed hide/restore is easy to miss before retrying.
- `resources/js/components/admin/products/admin-variant-price-dialog.tsx:382` — invalid price error alert — **error-state-shake** — a rejected override gives no physical rejection cue.
- `resources/js/components/admin/products/admin-variant-price-dialog.tsx:402` — tier table validation error — **error-state-shake** — a bad tier total otherwise returns with no jolt.
- `resources/js/components/admin/reviews/admin-review-visibility-dialog.tsx:184` — destructive error alert appearing — **error-state-shake** — a failed visibility update needs a stronger failure cue than a silent inline block.
- `resources/js/components/admin/settings/admin-security-section.tsx:577` — wrong one-time-code error — **error-state-shake** — the most common failure just jumps in under the field with no jolt.
- `resources/js/components/admin/settings/admin-service-pricing-section.tsx:999` — edit dialog general error — **error-state-shake** — save failures surface as a bare line.
- `resources/js/components/admin/settings/admin-service-pricing-section.tsx:1048` — minimum validation error — **error-state-shake** — field errors arrive with no cue tying them to the input.
- `resources/js/components/admin/settings/admin-team-section.tsx:824` — role dialog error — **error-state-shake** — failed role change reads as inert text.
- `resources/js/components/admin/settings/admin-team-section.tsx:891` — status dialog error — **error-state-shake** — failed de/reactivation reads as inert text.
- `resources/js/components/admin/settings/admin-team-section.tsx:1012` — grant-member error — **error-state-shake** — rejected invites give no failure beat.
- `resources/js/components/chat/chat-cart-offer.tsx:614` — validation error message — **error-state-shake** — a failed submit otherwise reads as no response at all
- `resources/js/components/chat/chat-message-list.tsx:434` — failed-send status line — **error-state-shake** — a failed message appears with no jolt telling the customer it did not send.
- `resources/js/components/configurator/coins/summary-step.tsx:91` — submit error paragraph appearing — **error-state-shake** — a failed add gives no motion cue, so the alert is easy to miss.
- `resources/js/components/configurator/manual-services/field-error.tsx:13` — inline validation error message — **error-state-shake** — invalid credentials and codes appear silently under the field, easy to miss on phone.
- `resources/js/components/configurator/manual-services/fut-champions-configurator.tsx:463` — add-to-cart failure alert — **error-state-shake** — a failed add surfaces as a static line beside the button.
- `resources/js/components/configurator/manual-services/fut-champions-configurator.tsx:578` — PC-launcher required error — **error-state-shake** — failing to pick a store shows a silent line under the segment.
- `resources/js/components/configurator/manual-services/rivals-configurator.tsx:709` — order panel error state — **error-state-shake** — a rejected submit is not signalled at the trigger
- `resources/js/components/configurator/manual-services/rank-picker.tsx:176` — matches-played validation error — **error-state-shake** — an invalid match count appears without alerting motion
- `resources/js/components/configurator/manual-services/rivals-configurator.tsx:572` — PC launcher validation error — **error-state-shake** — the missing-store error appears statically after submit
- `resources/js/components/configurator/manual-services/service-panel.tsx:221` — add-to-cart error alert appearing — **error-state-shake** — a failed add reads as nothing happening.
- `resources/js/components/store/catalog/sbc-product-configurator.tsx:637` — EA email validation error — **error-state-shake** — the inline error appears silently although focus is yanked there.
- `resources/js/components/store/catalog/catalog-add-control.tsx:180` — failed-add alert under the button — **error-state-shake** — a server failure on the primary CTA is easy to miss with no entrance.
- `resources/js/components/ui/input.tsx:11` — invalid field on submit — **error-state-shake** — a rejected field only recolors, which is easy to miss on a phone.
- `resources/js/pages/account/live-order.tsx:485` — payment failure alert text — **error-state-shake** — a failed payment lands as a silent inserted line
- `resources/js/pages/admin/marketing/page-editor.tsx:342` — save-error alert — **error-state-shake** — a failed save draws no attention
- `resources/js/pages/admin/marketing/faq.tsx:234` — action-failed error alert — **error-state-shake** — failures read as static text
- `resources/js/pages/admin/products/show.tsx:362` — conflict/error feedback alert — **error-state-shake** — conflicts and failures currently surface with no attention cue
- `resources/js/pages/admin/products/index.tsx:180` — failed-query error alert — **error-state-shake** — a failed filter load drops in with no failure cue before the retry button
- `resources/js/pages/admin/reviews/index.tsx:139` — visibility conflict alert — **error-state-shake** — a 409 stale-state conflict is the one message an admin must not miss.
- `resources/js/pages/auth/login.tsx:409` — phone verification error message — **error-state-shake** — an invalid code appears with no failure feedback.
- `resources/js/pages/auth/register.tsx:177` — password validation error — **error-state-shake** — rejection text appears flat after submit.
- `resources/js/pages/auth/confirm-password.tsx:47` — password validation error — **error-state-shake** — a failed confirmation has no visible cue.
- `resources/js/pages/auth/forgot-password.tsx:60` — email validation error — **error-state-shake** — a bad address surfaces as plain static text.
- `resources/js/pages/auth/reset-password.tsx:53` — email validation error text — **error-state-shake** — errors appear as static red text with no cue that the submit failed
- `resources/js/pages/auth/two-factor-challenge.tsx:51` — one-time-code error message — **error-state-shake** — a rejected 2FA code just materialises as red text
- `resources/js/pages/store/cart.tsx:778` — checkout failure message — **error-state-shake** — a failed payment surfaces with no attention cue.
- `resources/js/pages/store/cart.tsx:1591` — line removal error — **error-state-shake** — a failed removal shows text with no cue.
- `resources/js/pages/store/cart.tsx:1135` — phone verification error — **error-state-shake** — a rejected code appears with no shake.
- `resources/js/components/admin/products/admin-variant-revert-dialog.tsx:200` — destructive error feedback alert container — **error-state-shake** — draws immediate attention to rejection without jarring layout jumps
- `resources/js/components/input-error.tsx:10` — inline form validation error message — **error-state-shake** — draws immediate user attention to the invalid field
- `resources/js/components/one-time-code-field.tsx:163` — OTP input cells on validation failure — **error-state-shake** — gives immediate tactile feedback that the entered verification code was rejected
- `resources/js/components/phone-number-field.tsx:74` — phone number input on validation error — **error-state-shake** — draws immediate physical attention to invalid input
- `resources/js/components/ui/alert.tsx:12` — destructive validation error alert container — **error-state-shake** — directs mobile attention immediately to submission failures
- `resources/js/components/ui/badge.tsx:8` — validation error state on invalid badge — **error-state-shake** — lacks horizontal shake feedback when flagged with an invalid aria state
- `resources/js/components/ui/button.tsx:8` — action button with invalid validation state — **error-state-shake** — provides immediate tactile feedback when an attempted action fails form validation
- `resources/js/pages/admin/confirm-2fa.tsx:172` — two-factor code validation error message element — **error-state-shake** — immediate visual shake cues incorrect or expired codes on mobile without relying solely on color
- `resources/js/pages/admin/customers/show.tsx:273` — feedback alert conflict error warning icon — **error-state-shake** — alerts staff immediately when a concurrent update conflict occurs

## panel-reveal (56)

- `resources/js/components/account/account-order-row.tsx:117` — attention "pay/continue" action appearing — **panel-reveal** — the row's call to action enters with no reveal.
- `resources/js/components/account/account-section-error.tsx:15` — section error panel appearing — **panel-reveal** — a failed section pops in abruptly.
- `resources/js/components/admin/admin-queue-health.tsx:64` — unhealthy queue danger banner — **panel-reveal** — a silent mailer/queue failure must register the moment it appears.
- `resources/js/components/admin/admin-queue-health.tsx:34` — unmonitored queue warning banner — **panel-reveal** — the one blind-spot warning should register on appearance.
- `resources/js/components/admin/categories/admin-categories-table.tsx:57` — loading overlay over results — **panel-reveal** — every page, sort, filter and per-page navigation flashes this surface in with no fade
- `resources/js/components/admin/categories/admin-categories-toolbar.tsx:391` — active filter chip row — **panel-reveal** — the whole chip strip appears abruptly as filters are applied.
- `resources/js/components/admin/categories/admin-categories-toolbar.tsx:412` — chip remove button — **panel-reveal** — removing one filter makes the chip vanish and the row reflow instantly.
- `resources/js/components/admin/coupons/admin-coupon-drawer.tsx:641` — category picker panel on scope change — **panel-reveal** — appears instantly and shoves the form content down.
- `resources/js/components/admin/coupons/admin-coupon-drawer.tsx:683` — product picker panel on scope change — **panel-reveal** — same abrupt layout jump on scope switch.
- `resources/js/components/admin/customers/admin-customers-table.tsx:55` — navigation loading overlay appears — **panel-reveal** — it snaps in over the table with no crossfade, so refresh feels like a flash.
- `resources/js/components/admin/customers/admin-customers-table.tsx:128` — filtered-empty state appears — **panel-reveal** — the empty-state card pops in with no transition when a filter matches nothing.
- `resources/js/components/admin/customers/admin-customers-table.tsx:168` — mobile empty state appears — **panel-reveal** — same abrupt pop on the phone viewport.
- `resources/js/components/admin/customers/admin-customers-toolbar.tsx:400` — active filter chips row appears — **panel-reveal** — the whole chip strip appears at once with no entry motion.
- `resources/js/components/admin/faq/admin-faq-mobile-card.tsx:50` — empty state after last delete — **panel-reveal** — the list is replaced by the empty card instantly.
- `resources/js/components/admin/orders/admin-order-history.tsx:127` — audit-context section appearing — **panel-reveal** — the section snaps in after a reload with no sign it was added
- `resources/js/components/admin/orders/admin-orders-table.tsx:128` — desktop empty-orders state — **panel-reveal** — empty state pops in when filters return nothing.
- `resources/js/components/admin/orders/admin-orders-table.tsx:157` — mobile empty-orders state — **panel-reveal** — same hard pop on the phone layout.
- `resources/js/components/admin/products/admin-products-table.tsx:54` — table loading overlay over rows — **panel-reveal** — it hard-cuts in on every page or filter navigation.
- `resources/js/components/admin/reviews/admin-reviews-table.tsx:53` — table loading overlay — **panel-reveal** — every filter/nav hard-cuts the overlay in, so the refresh reads as a flicker.
- `resources/js/components/admin/reviews/admin-reviews-toolbar.tsx:156` — reset-filters button appearing — **panel-reveal** — the control pops into the flex row and shifts the whole filter strip.
- `resources/js/components/admin/settings/admin-security-section.tsx:271` — blocking failure alert — **panel-reveal** — session-expired/forbidden failures pop in with no entrance.
- `resources/js/components/admin/settings/admin-security-section.tsx:658` — recovery-codes reveal — **panel-reveal** — one-time codes that must be saved appear with no emphasis.
- `resources/js/components/admin/settings/admin-service-pricing-section.tsx:495` — action result banner — **panel-reveal** — price/status changes confirm via a strip that appears silently.
- `resources/js/components/admin/settings/admin-team-section.tsx:379` — action result banner — **panel-reveal** — grant/role/status results appear silently.
- `resources/js/components/admin/settings/admin-service-pricing-section.tsx:1010` — service-specific form swap — **panel-reveal** — coins/fut/rivals fields replace each other with no transition.
- `resources/js/components/chat/chat-cart-offer.tsx:446` — expanded credentials form replacing CTA — **panel-reveal** — the purchase panel hard-cuts in and shoves the transcript on the main conversion path
- `resources/js/components/chat/chat-cart-offer.tsx:379` — offer card mounting in thread — **panel-reveal** — the card slams into the transcript with no entry motion
- `resources/js/components/chat/chat-launcher.tsx:224` — greeting bubble dismissal — **panel-reveal** — the 3s bubble unmounts abruptly on dismiss instead of retracting.
- `resources/js/components/chat/chat-message-list.tsx:196` — older-messages loader appearing — **panel-reveal** — the loader row pops in and out above the transcript unanimated.
- `resources/js/components/chat/chat-widget.tsx:720` — read-only notice appearing — **panel-reveal** — the notice mounts above the transcript with no entrance.
- `resources/js/components/chat/chat-widget.tsx:745` — handoff banner appearing — **panel-reveal** — agent-mode status pops in unannounced.
- `resources/js/components/configurator/coins/summary-step.tsx:129` — in-cart note with open-cart link appearing — **panel-reveal** — the follow-up action pops in abruptly under the actions row.
- `resources/js/components/configurator/manual-services/credentials-fields.tsx:45` — platform credential field set — **panel-reveal** — switching PlayStation/PC replaces the whole credential grid instantly.
- `resources/js/components/configurator/manual-services/credentials-fields.tsx:267` — Steam username/password block — **panel-reveal** — Steam fields pop in and out when the launcher segment changes.
- `resources/js/components/store/catalog/catalog-add-control.tsx:157` — idle control swapping to in-cart control — **panel-reveal** — the whole add control changes layout shape instantly after success.
- `resources/js/components/store/catalog/sbc-product-configurator.tsx:495` — editing-replace note on load — **panel-reveal** — the "you are replacing" note is dropped in with no signal when editing a cart line.
- `resources/js/components/store/service-rail.tsx:37` — service rail cards — **panel-reveal** — cards reveal with the section but are not staggered, unlike the review cards beside them
- `resources/js/components/store/service-rail.tsx:68` — rail arrow controls — **panel-reveal** — the controls pop in after the overflow measure with no fade or rise
- `resources/js/components/ui/select.tsx:146` — scroll-up affordance — **panel-reveal** — overflow chevron appears abruptly as the list scrolls
- `resources/js/pages/admin/marketing/page-editor.tsx:501` — "Add block" appends a row — **panel-reveal** — the new block appears instantly and authors lose where it landed
- `resources/js/pages/admin/marketing/faq.tsx:227` — visibility-conflict alert — **panel-reveal** — a concurrent-edit warning appears from nowhere
- `resources/js/pages/admin/orders/show.tsx:525` — refunds block after a refund — **panel-reveal** — surfaces the new refund without a scroll hunt
- `resources/js/pages/admin/orders/index.tsx:173` — query-failure alert appearing — **panel-reveal** — draws the eye to the failed load instead of hard-cutting in
- `resources/js/pages/admin/orders/show.tsx:475` — payments block appearing — **panel-reveal** — introduces the payment record gently instead of popping
- `resources/js/pages/admin/overview.tsx:33` — queue-health banner mount — **panel-reveal** — danger banner cuts in and out with no entrance or exit.
- `resources/js/pages/auth/login.tsx:308` — phone field advancing to OTP — **panel-reveal** — the code step pops in with no transition after sending.
- `resources/js/pages/store/cart.tsx:681` — repricing confirmation block — **panel-reveal** — a changed-price warning appears abruptly at the highest-stakes moment.
- `resources/js/pages/store/home.tsx:217` — coins unavailable panel — **panel-reveal** — the configurator versus sold-out swap is abrupt.
- `resources/js/components/store/cart-added-notice.tsx:244` — touch swipe gesture dismiss interaction — **panel-reveal** — notice remains completely rigid during drag rather than following the finger
- `resources/js/components/store/store-information-page.tsx:63` — account safety and compliance notice banner — **panel-reveal** — emphasizes vital FIFA coin safety guidelines as shoppers read policies
- `resources/js/pages/admin/customers/index.tsx:178` — destructive query failure alert banner — **panel-reveal** — prevents jarring layout shift when customer query fails
- `resources/js/pages/admin/customers/show.tsx:771` — newly prepended wallet ledger transaction row — **panel-reveal** — highlights the newly recorded credit or debit entry
- `resources/js/pages/admin/marketing/coupons/show.tsx:432` — released redemptions warning callout container box — **panel-reveal** — informational notice appears without smooth sliding or height expansion
- `resources/js/pages/admin/settings.tsx:139` — admin team management section panel wrapper — **panel-reveal** — prevents jarring layout shift when team settings mount
- `resources/js/pages/admin/settings.tsx:149` — admin service pricing section panel wrapper — **panel-reveal** — smooths entrance of conditional pricing controls upon authorization
- `resources/js/pages/admin/settings.tsx:83` — admin identity summary card container surface — **panel-reveal** — eliminates harsh visual pop on dark background load

## icon-swap (51)

- `resources/js/components/account/app-icon.tsx:147` — chevron used for expand/collapse — **icon-swap** — the glyph only re-renders, never rotates.
- `resources/js/components/admin/admin-attention-strip.tsx:62` — urgent/warning/resolved icon — **icon-swap** — the strip's status icon hard-cuts between three states instead of morphing.
- `resources/js/components/admin/admin-kpi-strip.tsx:164` — up/down comparison arrow — **icon-swap** — the direction glyph swaps instantly when the trend reverses.
- `resources/js/components/admin/admin-badge.tsx:38` — badge status icon — **icon-swap** — status glyph switches abruptly on every order state change.
- `resources/js/components/admin/categories/admin-categories-columns.tsx:281` — hide/restore action button — **icon-swap** — Eye flips to EyeOff and the label swaps instantly in one slot
- `resources/js/components/admin/categories/admin-categories-mobile-card.tsx:147` — mobile hide/restore button — **icon-swap** — same instant icon and label swap
- `resources/js/components/admin/categories/admin-categories-columns.tsx:89` — sort direction indicator — **icon-swap** — ArrowUpDown becomes ArrowUp or ArrowDown with no crossfade
- `resources/js/components/admin/coupons/admin-coupons-columns.tsx:486` — pause/resume row action icon — **icon-swap** — the core activation action swaps Pause/Play with no cue.
- `resources/js/components/admin/coupons/admin-coupons-mobile-card.tsx:293` — pause/resume card action icon — **icon-swap** — same swap, unanimated on the primary viewport.
- `resources/js/components/admin/coupons/admin-coupons-columns.tsx:118` — sort-direction icon — **icon-swap** — the arrow snaps between three states in one slot.
- `resources/js/components/admin/customers/admin-customers-columns.tsx:85` — Sort direction arrow slot — **icon-swap** — the three-way arrow snaps hard when sorting.
- `resources/js/components/admin/customers/admin-customers-columns.tsx:174` — Customer status badge swap — **icon-swap** — suspend/reactivate flips icon and text without transition.
- `resources/js/components/admin/customers/admin-customers-mobile-card.tsx:62` — status badge icon — **icon-swap** — a suspended/active change swaps the icon and tint with no transition.
- `resources/js/components/admin/faq/admin-faq-mobile-card.tsx:154` — visibility Eye/EyeOff action icon — **icon-swap** — toggling hide/show flips the icon instantly, so the state change is easy to miss on phone.
- `resources/js/components/admin/orders/admin-order-item-secret.tsx:264` — Copy/Check button icon — **icon-swap** — confirms the credential reached the clipboard without the operator re-reading
- `resources/js/components/admin/orders/admin-orders-columns.tsx:84` — sort-direction arrow indicator — **icon-swap** — the active sort arrow snaps with no morph between states.
- `resources/js/components/admin/pages/admin-page-editor-header.tsx:58` — save button Save↔spinner slot — **icon-swap** — reassures the admin the save is actually in flight.
- `resources/js/components/admin/products/admin-products-columns.tsx:89` — sort-direction icon in header slot — **icon-swap** — the arrow changes instantly, making direction hard to read.
- `resources/js/components/admin/products/admin-products-columns.tsx:200` — visibility badge Eye/EyeOff icon — **icon-swap** — toggling visibility leaves the row looking unchanged.
- `resources/js/components/admin/products/admin-products-columns.tsx:167` — authority badge Bot/UserCheck icon — **icon-swap** — switching authority leaves a visually identical row.
- `resources/js/components/admin/products/admin-products-mobile-card.tsx:66` — authority badge icon in card header — **icon-swap** — the two badge icons swap with no motion.
- `resources/js/components/admin/reviews/admin-reviews-row-parts.tsx:23` — storefront visibility badge icon swap — **icon-swap** — the main hide/show action gives no confirmation cue when the row flips to hidden.
- `resources/js/components/admin/reviews/admin-reviews-row-parts.tsx:84` — review action button flips icon and label — **icon-swap** — the Eye/EyeOff toggle changes abruptly with no transition.
- `resources/js/components/admin/reviews/admin-reviews-toolbar.tsx:91` — search button icon while navigating — **icon-swap** — the button only dims, so it never signals that the query is running.
- `resources/js/components/chat/chat-handoff-banner.tsx:103` — avatar slot Clock to initial — **icon-swap** — two glyphs hard-cut inside one circle
- `resources/js/components/chat/chat-header.tsx:103` — mute speaker icon — **icon-swap** — Volume2 and VolumeX hard-cut in one slot with no cross-fade.
- `resources/js/components/chat/chat-header.tsx:63` — back chevron hover nudge — **icon-swap** — the `group-hover` nudge never fires because the button is not a `group`.
- `resources/js/components/chat/chat-home.tsx:141` — mobile close chevron vs X — **icon-swap** — the close trigger swaps glyphs instantly with no cross-fade.
- `resources/js/components/configurator/manual-services/credentials-fields.tsx:150` — password show/hide eye icon — **icon-swap** — the Eye/EyeOff slot flips with no crossfade (same at 253 and 365).
- `resources/js/components/store/catalog/sbc-product-configurator.tsx:696` — password reveal Eye/EyeOff icon — **icon-swap** — two icons share one slot and currently cut with no transition in a security field.
- `resources/js/components/ui/dropdown-menu.tsx:218` — submenu chevron direction — **icon-swap** — the trailing arrow never rotates, so Arabic RTL users cannot tell which way the nested menu opens.
- `resources/js/components/ui/select.tsx:124` — item check indicator — **icon-swap** — the check snaps into the indicator slot with no transition
- `resources/js/components/ui/select.tsx:45` — trigger chevron — **icon-swap** — chevron never rotates/flips to signal the open state
- `resources/js/pages/admin/marketing/loyalty.tsx:277` — tier active/inactive badge — **icon-swap** — check/x icon flips between states with no transition
- `resources/js/pages/admin/orders/show.tsx:86` — order header status badge — **icon-swap** — confirms a transition action landed without a full reload
- `resources/js/pages/admin/orders/show.tsx:260` — line-item status badge swap — **icon-swap** — ties the item state to the order change above it
- `resources/js/pages/admin/overview.tsx:140` — spinner inserted beside range label — **icon-swap** — spinner pops into the flow and shifts the label instead of occupying a fixed icon slot.
- `resources/js/pages/admin/products/show.tsx:321` — hide/restore visibility button — **icon-swap** — Eye and EyeOff swap instantly with no crossfade or rotation
- `resources/js/pages/admin/products/show.tsx:264` — visibility status badge icon — **icon-swap** — badge icon flips between Eye and EyeOff on toggle
- `resources/js/pages/auth/register.tsx:199` — password rule check/bullet glyphs — **icon-swap** — ✓/• flip instantly as each rule is met.
- `resources/js/pages/auth/login.tsx:235` — password visibility eye toggle — **icon-swap** — Eye/EyeOff swap instantly in the same slot.
- `resources/js/pages/auth/confirm-password.tsx:30` — password visibility toggle — **icon-swap** — same instant icon swap with no motion.
- `resources/js/pages/auth/reset-password.tsx:65` — password visibility eye toggle — **icon-swap** — Eye/EyeOff swaps instantly, so the tap feels unacknowledged
- `resources/js/components/admin/faq/admin-faq-table.tsx:181` — FAQ store visibility toggle icon slot — **icon-swap** — switching visibility snaps abruptly instead of morphing between eye states to acknowledge the change
- `resources/js/components/password-input.tsx:34` — password visibility toggle button icon — **icon-swap** — smooths the abrupt switch between revealed and masked states
- `resources/js/components/store/cart-added-notice.tsx:263` — status ring icon glyph slot — **icon-swap** — glyph snaps instantly between checkmark and duplicate warning icon
- `resources/js/components/ui/badge.tsx:8` — embedded leading icon inside the badge — **icon-swap** — status icon changes jump abruptly instead of smoothly morphing or cross-fading
- `resources/js/pages/admin/customers/show.tsx:201` — customer header status badge checkmark icon — **icon-swap** — conveys suspension or reactivation state changes gracefully
- `resources/js/pages/admin/customers/show.tsx:308` — account status section badge indicator icon — **icon-swap** — visualizes the active or suspended state transition clearly
- `resources/js/pages/admin/marketing/coupons/show.tsx:278` — coupon pause and resume status icon — **icon-swap** — glyph switches abruptly between active states without rotational crossfade
- `resources/js/pages/admin/settings.tsx:121` — admin session sign out icon slot — **icon-swap** — signals active submission state while session invalidation request executes

## skeleton-reveal (33)

- `resources/js/components/admin/admin-attention-strip.tsx:139` — empty-state vs oldest-order row — **skeleton-reveal** — clearing the queue swaps the whole block with no reveal.
- `resources/js/components/admin/admin-recent-orders.tsx:62` — empty state to orders table — **skeleton-reveal** — the zero-orders placeholder swaps to real rows in one frame.
- `resources/js/components/admin/admin-revenue-chart.tsx:130` — empty state to revenue chart — **skeleton-reveal** — the chart pops in with no resolve from its placeholder.
- `resources/js/components/admin/coupons/admin-coupons-table.tsx:51` — navigating loading overlay — **skeleton-reveal** — an opaque panel snaps over the table instead of revealing placeholders.
- `resources/js/components/admin/coupons/admin-coupons-table.tsx:105` — filtered empty-state swap — **skeleton-reveal** — rows vanish to a blank cell with no transition.
- `resources/js/components/admin/customers/admin-customers-table.tsx:101` — table rows render after navigation — **skeleton-reveal** — rows appear fully formed with no reveal, so filtered results feel frozen.
- `resources/js/components/admin/orders/admin-order-item-secret.tsx:218` — spinner line swapping to revealed credentials card — **skeleton-reveal** — the most consequential content on the order screen appears with no reveal
- `resources/js/components/admin/orders/admin-orders-table.tsx:54` — navigation-loading overlay over table — **skeleton-reveal** — every sort/filter/page hard-cuts to the overlay instead of resolving in.
- `resources/js/components/admin/reviews/admin-reviews-table.tsx:88` — review rows after navigation — **skeleton-reveal** — rows pop in with no reveal, so fresh data lands abruptly.
- `resources/js/components/admin/settings/admin-security-section.tsx:526` — QR placeholder swapping to code — **skeleton-reveal** — the scan step snaps from spinner to image.
- `resources/js/components/chat/chat-service-cards.tsx:96` — live price label after fetch — **skeleton-reveal** — the deciding number currently appears with no transition once prices resolve.
- `resources/js/components/chat/chat-shelf.tsx:89` — shelf card price after fetch — **skeleton-reveal** — same abrupt price arrival inside the swipeable shelf.
- `resources/js/components/configurator/manual-services/fut-champions-configurator.tsx:174` — credentials prefilled from saved line — **skeleton-reveal** — empty fields fill after the fetch with no loading cue.
- `resources/js/components/configurator/manual-services/rivals-configurator.tsx:651` — credentials fields prefill — **skeleton-reveal** — edit-mode values appear only after the async fetch with no placeholder
- `resources/js/components/configurator/manual-services/squad-upload.tsx:71` — squad image preview appearing — **skeleton-reveal** — the uploaded file pops in with no reveal.
- `resources/js/components/configurator/manual-services/service-panel.tsx:140` — panel media lazy-loading — **skeleton-reveal** — the image area is blank then flashes in.
- `resources/js/components/store/catalog/catalog-skeleton-grid.tsx:11` — skeleton grid giving way to real cards — **skeleton-reveal** — filtered results materialize with no crossfade over the shimmer.
- `resources/js/components/ui/spinner.tsx:7` — loading spinner mount/unmount — **skeleton-reveal** — it pops in and out with no fade, so loading→content swaps flash.
- `resources/js/components/ui/table.tsx:63` — rows after sort/filter/data refresh — **skeleton-reveal** — rows swap in with no transition, so refreshed data jumps into place.
- `resources/js/pages/admin/marketing/faq.tsx:110` — entries list after reload — **skeleton-reveal** — table content is replaced with no placeholder
- `resources/js/pages/admin/marketing/loyalty.tsx:81` — tiers/kpis reload — **skeleton-reveal** — refetched rows and numbers repaint abruptly
- `resources/js/pages/admin/marketing/pages.tsx:66` — policy-pages list rows — **skeleton-reveal** — list paints in with no placeholder
- `resources/js/pages/admin/orders/index.tsx:208` — orders table during filter navigation — **skeleton-reveal** — keeps the grid from snapping between filter results
- `resources/js/pages/admin/overview.tsx:68` — revenue chart on range switch — **skeleton-reveal** — chart holds stale bars then swaps with no loading placeholder, so the reload reads as a stall.
- `resources/js/pages/admin/overview.tsx:76` — recent-orders rows on range switch — **skeleton-reveal** — list silently replaces itself with no loading affordance.
- `resources/js/components/account/wallet-ledger.tsx:42` — wallet transaction history ordered list container — **skeleton-reveal** — eliminates jarring layout flash when transactions load
- `resources/js/components/admin/faq/admin-faq-table.tsx:60` — empty state table replacement card — **skeleton-reveal** — empty state swaps abruptly without smooth progressive reveal when FAQ entries populate
- `resources/js/components/store/store-footer.tsx:160` — lazy loaded payment provider logo — **skeleton-reveal** — softens visual pop-in as payment provider emblems load above the bottom fold
- `resources/js/components/store/store-footer.tsx:94` — lazy loaded brand footer logo — **skeleton-reveal** — eliminates harsh asset flash when scrolling past the main brand crest
- `resources/js/components/store/store-information-page.tsx:195` — dynamic policy article prose block container — **skeleton-reveal** — eliminates abrupt layout jumping when policy pages load
- `resources/js/pages/admin/customers/index.tsx:207` — customer management table content section — **skeleton-reveal** — softens sudden content swaps when filtering or paginating
- `resources/js/pages/admin/marketing/coupons/show.tsx:460` — redemptions over time chart data display — **skeleton-reveal** — empty state to chart transition is unbuffered by shimmer placeholder
- `resources/js/pages/admin/more.tsx:50` — navigation tile groups container surface — **skeleton-reveal** — mounts abruptly on initial visit without placeholder cross-fade or skeleton reveal

## success-check (31)

- `resources/js/components/account/order-review-card.tsx:30` — review form becoming submitted card — **success-check** — the submission's payoff swaps silently with no confirmation.
- `resources/js/components/admin/categories/admin-category-visibility-dialog.tsx:172` — success closes the modal with no confirmation beat — **success-check** — the operator gets no moment of confirmation before the dialog vanishes.
- `resources/js/components/admin/coupons/admin-coupon-drawer.tsx:317` — save result Alert banner — **success-check** — a saved or failed coupon state appears instantly with no confirmation beat.
- `resources/js/components/admin/loyalty/admin-loyalty-tier-dialog.tsx:246` — successful update closing the dialog — **success-check** — the save completes and vanishes with no confirmation.
- `resources/js/components/admin/orders/admin-order-refund-control.tsx:238` — refund success/error feedback alert — **success-check** — money movement needs an unmistakable confirmation moment
- `resources/js/components/admin/orders/admin-order-transition-controls.tsx:254` — status-updated feedback alert — **success-check** — operator must be sure the transition landed before touching the order again
- `resources/js/components/admin/products/admin-variant-price-dialog.tsx:333` — dialog closing on successful override — **success-check** — success is only inferred from the dialog vanishing.
- `resources/js/components/chat/chat-cart-offer.tsx:411` — success row after add-to-cart — **success-check** — the confirmation appears as a static block, so customers hesitate whether the order landed
- `resources/js/components/chat/chat-handoff-banner.tsx:45` — resolved ticket check icon — **success-check** — resolution deserves a confirmation beat instead of an instant glyph
- `resources/js/components/configurator/coins/summary-step.tsx:105` — primary Add button swapping to in-cart control — **success-check** — the add-to-cart win currently snaps instead of confirming the purchase moment.
- `resources/js/components/configurator/manual-services/fut-champions-configurator.tsx:377` — add-to-cart success state — **success-check** — success is only a label flip, with no confirmation beat.
- `resources/js/components/configurator/manual-services/rivals-configurator.tsx:699` — order panel success state — **success-check** — add-to-cart confirmation lands with no completion beat
- `resources/js/components/configurator/manual-services/service-panel.tsx:190` — submit button entering success — **success-check** — the ✓ is injected via `::before` instantly, weakening purchase confirmation.
- `resources/js/pages/account/profile.tsx:832` — password-changed message — **success-check** — a security change confirms with plain text only
- `resources/js/pages/account/profile.tsx:974` — reset-link-sent message — **success-check** — transient success lacks an entry motion
- `resources/js/pages/account/overview.tsx:104` — verification-link-sent status — **success-check** — the confirmation appears without a completing beat
- `resources/js/pages/admin/marketing/page-editor.tsx:332` — save-success alert — **success-check** — confirmation just pops in with no acknowledgement beat
- `resources/js/pages/admin/marketing/faq.tsx:216` — create/edit success alert — **success-check** — save feedback appears with no reinforcing motion
- `resources/js/pages/admin/marketing/loyalty.tsx:107` — tier-updated success banner — **success-check** — the only success moment on the page is inert
- `resources/js/pages/admin/products/show.tsx:361` — post-save feedback alert — **success-check** — a successful edit/override/revert deserves a clear confirmation beat
- `resources/js/pages/admin/reviews/index.tsx:128` — visibility success alert — **success-check** — the confirmation that a review went live or hidden is the payoff of the action and currently just pops in.
- `resources/js/pages/auth/forgot-password.tsx:23` — reset-link sent confirmation — **success-check** — the status banner enters with no acknowledgement motion.
- `resources/js/pages/auth/verify-email.tsx:42` — resent-link confirmation status — **success-check** — the "sent" badge pops in with no confirmation beat, inviting repeat taps
- `resources/js/pages/store/cart.tsx:1244` — applied coupon status — **success-check** — applying a coupon gives no confirmation moment.
- `resources/js/pages/store/cart.tsx:1974` — credentials saved status — **success-check** — the save confirmation appears as bare text.
- `resources/js/components/admin/products/admin-variant-revert-dialog.tsx:159` — successful variant revert completion trigger — **success-check** — confirms price override reset before dialog unmounts
- `resources/js/components/one-time-code-field.tsx:50` — six-digit OTP field upon code completion — **success-check** — confirms code completion before auto-submitting the authentication request
- `resources/js/components/store/store-footer.tsx:181` — verified freelance license trust badge — **success-check** — confirms platform legitimacy and licensing to alleviate checkout anxiety
- `resources/js/pages/admin/confirm-2fa.tsx:207` — two-factor verification submit confirmation button — **success-check** — confirms valid verification before the Inertia dashboard navigation completes
- `resources/js/pages/admin/customers/show.tsx:271` — feedback alert success confirmation checkmark icon — **success-check** — confirms successful customer status or wallet modification clearly
- `resources/js/pages/admin/marketing/coupons/show.tsx:146` — toggle status confirmation success notice action — **success-check** — status confirmation lacks animated checkmark feedback before page reload

## checkbox-check (17)

- `resources/js/components/admin/categories/admin-categories-columns.tsx:114` — per-row selection checkbox — **checkbox-check** — the indicator is transition-none, so bulk-select gives no confirmation
- `resources/js/components/admin/categories/admin-categories-mobile-card.tsx:45` — mobile card selection checkbox — **checkbox-check** — same snap on the phone viewport admins actually use
- `resources/js/components/admin/categories/admin-categories-columns.tsx:126` — select-all header checkbox — **checkbox-check** — indeterminate to checked jumps, weakening the "all rows" gesture
- `resources/js/components/admin/coupons/admin-coupon-drawer.tsx:658` — target category checkbox — **checkbox-check** — the tick state flips with no check animation.
- `resources/js/components/admin/customers/admin-customers-columns.tsx:110` — Row selection checkbox — **checkbox-check** — selecting a row gives no visual acknowledgment.
- `resources/js/components/admin/customers/admin-customers-mobile-card.tsx:41` — row select checkbox — **checkbox-check** — the tick snaps because the primitive indicator sets `transition-none`.
- `resources/js/components/admin/loyalty/admin-loyalty-tier-dialog.tsx:488` — active-tier checkbox tick — **checkbox-check** — the selection snaps on with no affirmation.
- `resources/js/components/admin/orders/admin-orders-columns.tsx:113` — row-select checkbox checkmark — **checkbox-check** — bulk selection currently snaps with no confirmation.
- `resources/js/components/admin/orders/admin-orders-mobile-card.tsx:48` — mobile row-select checkbox — **checkbox-check** — same instant snap on the phone card.
- `resources/js/components/admin/orders/admin-orders-columns.tsx:130` — select-all header checkbox — **checkbox-check** — checked/indeterminate snap when selecting a whole page.
- `resources/js/components/admin/pages/admin-page-editor-block-row.tsx:171` — list-ordered checkbox — **checkbox-check** — confirms the ordered toggle was registered.
- `resources/js/components/admin/products/admin-products-toolbar.tsx:375` — column visibility checkbox item — **checkbox-check** — toggling a column shows an instant, unanimated tick.
- `resources/js/components/configurator/manual-services/selection-card.tsx:44` — platform radio mark filling on select — **checkbox-check** — choosing a platform gives no confirming tick.
- `resources/js/components/ui/checkbox.tsx:22` — checkmark indicator on select — **checkbox-check** — the tick snaps in under `transition-none`, so a completed selection feels unregistered.
- `resources/js/components/ui/dropdown-menu.tsx:100` — checkbox menu item tick — **checkbox-check** — toggled options appear instantly, giving no confirmation in multi-select menus.
- `resources/js/components/ui/dropdown-menu.tsx:136` — radio menu item dot — **checkbox-check** — the active dot swaps with no scale-in, hiding which option is selected.
- `resources/js/pages/auth/login.tsx:265` — remember-me checkbox check — **checkbox-check** — the indicator appears with `transition-none`, so ticking feels dead.

## texts-reveal (29)

- `resources/js/components/admin/admin-recent-orders.tsx:105` — recent orders table rows — **texts-reveal** — newly placed orders snap in with no stagger to catch the eye.
- `resources/js/components/admin/admin-queue-health.tsx:86` — latest failure detail row — **texts-reveal** — the newest failed job name enters abruptly under the count.
- `resources/js/components/admin/categories/admin-categories-table.tsx:131` — empty-results state — **texts-reveal** — rows swap to the empty message and reset button abruptly
- `resources/js/components/admin/categories/admin-categories-table.tsx:173` — mobile empty-results state — **texts-reveal** — the phone empty state pops in with no reveal
- `resources/js/components/admin/faq/admin-faq-header.tsx:21` — title + description entering — **texts-reveal** — stacked headline and subline appear with no entrance.
- `resources/js/components/admin/orders/admin-order-history.tsx:61` — newly appended status-history entry — **texts-reveal** — a fresh timeline step after a status change appears unannounced
- `resources/js/components/chat/chat-choice-chips.tsx:33` — choice chips first appearing — **texts-reveal** — answers pop in with no stagger, harder to scan on phone
- `resources/js/components/configurator/manual-services/fut-champions-configurator.tsx:500` — "editing existing line" notice — **texts-reveal** — the replace notice appears abruptly above the form.
- `resources/js/components/configurator/manual-services/rivals-configurator.tsx:494` — replacement editing note — **texts-reveal** — the “editing” note pops in when a cart line is reopened
- `resources/js/components/configurator/manual-services/squad-upload.tsx:38` — kept-image notice appearing — **texts-reveal** — the hint materializes abruptly after navigation.
- `resources/js/components/configurator/manual-services/service-panel.tsx:207` — open-cart link appearing in-cart — **texts-reveal** — the follow-up action pops in with no entry.
- `resources/js/components/configurator/manual-services/service-panel.tsx:216` — "added" status line appearing — **texts-reveal** — the confirmation text enters with no motion.
- `resources/js/pages/admin/orders/show.tsx:313` — item credential secret reveal — **texts-reveal** — signals the hidden secret actually decoded
- `resources/js/pages/admin/orders/show.tsx:96` — first status timestamp appearing — **texts-reveal** — shows the order lifecycle advancing
- `resources/js/pages/admin/reviews/index.tsx:119` — page title plus description header — **texts-reveal** — the stacked headline and subline arrive with zero entrance motion.
- `resources/js/pages/auth/verify-email.tsx:62` — resend countdown line — **texts-reveal** — appears with an abrupt layout shift under the disabled button
- `resources/js/components/account/wallet-ledger.tsx:61` — transaction type title and timestamp subline — **texts-reveal** — smooths visual entry of transaction details on mount
- `resources/js/components/admin/products/admin-variant-revert-dialog.tsx:189` — dialog header title and description — **texts-reveal** — guides eye sequentially into destructive confirmation context
- `resources/js/components/store/store-footer.tsx:188` — stacked copyright and disclaimer lines — **texts-reveal** — staggers legal disclaimers smoothly when customer scrolls to the terminal boundary
- `resources/js/components/store/store-information-page.tsx:177` — hero title and subtitle text stack — **texts-reveal** — establishes clear visual hierarchy on initial page entry
- `resources/js/components/store/store-information-page.tsx:215` — support callout title and subtitle stack — **texts-reveal** — draws attention to customer assistance after reading policies
- `resources/js/components/ui/alert.tsx:40` — stacked alert title and description text — **texts-reveal** — softens abrupt visual popping of feedback messages
- `resources/js/pages/admin/confirm-2fa.tsx:112` — heading title and description text stack — **texts-reveal** — establishes an elegant staggered entrance on mobile screen navigation
- `resources/js/pages/admin/customers/index.tsx:168` — customer page title description header — **texts-reveal** — establishes smooth hierarchy entrance on page arrival
- `resources/js/pages/admin/customers/show.tsx:276` — feedback alert title and description texts — **texts-reveal** — smooths the entrance of the feedback status details
- `resources/js/pages/admin/more.tsx:36` — header title and description text stack — **texts-reveal** — page entry lacks staggered hierarchy reveal causing the header to pop into view abruptly
- `resources/js/pages/admin/settings.tsx:31` — settings header title and description heading — **texts-reveal** — establishes clear visual reading hierarchy on initial mount
- `resources/js/pages/admin/settings.tsx:98` — admin identity profile name and role — **texts-reveal** — softens typography presentation when account summary appears
- `resources/js/pages/store/sitemap.tsx:47` — hero headline and eyebrow introductory text — **texts-reveal** — softens initial page load entrance and establishes hierarchy

## notification-badge (15)

- `resources/js/components/account/account-mobile-bottom-nav.tsx:113` — attention dot on a tab — **notification-badge** — the one "we need you" signal on the bar appears as a flat dot with no entrance.
- `resources/js/components/account/account-navigation.tsx:63` — attention dot beside a nav row — **notification-badge** — the same needs-you dot enters silently in the sidebar.
- `resources/js/components/account/account-navigation.tsx:69` — order/wallet count badge — **notification-badge** — a new count appears with no pop, so it reads as static decoration.
- `resources/js/components/account/account-order-row.tsx:57` — item-count badge on the artwork — **notification-badge** — the count appears with no pop.
- `resources/js/components/admin/admin-sidebar.tsx:145` — conversations unread count badge — **notification-badge** — new chat arrivals should pop the count into view.
- `resources/js/components/admin/categories/admin-categories-toolbar.tsx:264` — active-filter count badge on the Filters trigger — **notification-badge** — the count pops in with no appear cue when a filter is set.
- `resources/js/components/admin/customers/admin-customers-toolbar.tsx:250` — active filter count badge — **notification-badge** — the count chip appears with no pop, easy to miss the filters state.
- `resources/js/components/admin/products/admin-products-toolbar.tsx:341` — active filter count badge — **notification-badge** — the count appears and changes with no accent.
- `resources/js/components/chat/chat-launcher.tsx:180` — online presence dot — **notification-badge** — the dot unmounts instantly when the chat opens instead of easing out.
- `resources/js/components/store/catalog/sbc-catalog-card.tsx:137` — promotion badge on the card — **notification-badge** — a discount marker pops onto the card with no appear beat.
- `resources/js/pages/account/profile.tsx:396` — phone "unverified" badge appearing — **notification-badge** — the warning appears with no attention cue
- `resources/js/pages/account/profile.tsx:665` — email "unverified" badge appearing — **notification-badge** — same silent warning on the email row
- `resources/js/pages/admin/products/show.tsx:254` — admin-hidden warning badge — **notification-badge** — the badge pops into the header row with no appear transition
- `resources/js/components/admin/admin-unread-badge.tsx:140` — unread support count pill container — **notification-badge** — badge appears abruptly without entrance scaling when unread tickets arrive
- `resources/js/components/ui/badge.tsx:38` — dynamic status and notification badge pill — **notification-badge** — mounts abruptly without entrance scale or opacity transition to alert the user

## card-resize (21)

- `resources/js/components/admin/coupons/admin-coupon-drawer.tsx:509` — max-discount field for percent type — **card-resize** — the field pops in only for one discount type.
- `resources/js/components/admin/coupons/admin-coupons-mobile-card.tsx:216` — usage progress fill — **card-resize** — the bar snaps to its new width where the desktop table eases.
- `resources/js/components/admin/pages/admin-page-editor-block-row.tsx:107` — heading-level control reveal — **card-resize** — row grows when the type becomes heading.
- `resources/js/components/admin/pages/admin-page-editor-block-row.tsx:136` — notice-tone control reveal — **card-resize** — row grows when the type becomes notice.
- `resources/js/components/admin/pages/admin-page-editor-block-row.tsx:169` — ordered checkbox reveal — **card-resize** — row grows when the type becomes list.
- `resources/js/components/admin/settings/admin-service-pricing-section.tsx:1318` — add-band button grows form — **card-resize** — appending a tier row changes dialog height with no motion.
- `resources/js/components/chat/chat-composer.tsx:34` — textarea auto-grow height — **card-resize** — each wrapped line snaps the composer taller with no easing
- `resources/js/components/configurator/manual-services/rivals-configurator.tsx:630` — ladder-versus-weekly options block — **card-resize** — the options area jumps in height when the mode flips
- `resources/js/components/configurator/manual-services/service-slider.tsx:57` — track fill moving between notches — **card-resize** — the gold fill jumps rather than growing.
- `resources/js/components/store/reviews-section.tsx:317` — rating distribution bar fill — **card-resize** — bars render at final width, so the trust summary never fills in and reads as flat
- `resources/js/pages/account/overview.tsx:273` — loyalty progress bar inline width — **card-resize** — the bar snaps to value instead of filling
- `resources/js/pages/admin/marketing/page-editor.tsx:488` — remove block collapses a row — **card-resize** — list height jumps with no space-closing motion
- `resources/js/pages/admin/products/show.tsx:676` — override block inside price cell — **card-resize** — row height jumps a full line when the override price and struck-through base price appear
- `resources/js/components/admin/admin-unread-badge.tsx:142` — unread badge dynamic width container — **card-resize** — pill snaps width abruptly without smooth expansion across digit thresholds
- `resources/js/components/admin/faq/admin-faq-table.tsx:94` — table row list container element — **card-resize** — row removal abruptly jumps layout height without smooth collapse when deleting FAQs
- `resources/js/components/admin/products/admin-variant-revert-dialog.tsx:186` — modal dialog content surface container — **card-resize** — smooths container height when error banner appears or clears
- `resources/js/components/store/cart-added-notice.tsx:336` — footer cart and checkout action buttons — **card-resize** — button layout abruptly shifts between dual checkout actions and single button
- `resources/js/components/store/cart-added-notice.tsx:317` — attribute selection pills badge container — **card-resize** — card height abruptly jerks when items with pills replace plain items
- `resources/js/pages/admin/confirm-2fa.tsx:109` — authentication form card container surface — **card-resize** — absorbs vertical layout shifts when validation error alerts mount or clear
- `resources/js/pages/admin/marketing/coupons/show.tsx:347` — coupon redemption usage limit progress bar — **card-resize** — width snaps instantly on load rather than expanding smoothly to indicate quota consumption
- `resources/js/pages/admin/marketing/coupons/show.tsx:490` — daily redemption chart bar vertical fill — **card-resize** — bars snap rigidly into view rather than growing smoothly from the baseline

## tabs-sliding (9)

- `resources/js/components/account/account-mobile-bottom-nav.tsx:93` — active tab highlight — **tabs-sliding** — the lit tab jumps between the four fixed slots instead of gliding, so fast taps feel disconnected.
- `resources/js/components/admin/coupons/admin-coupon-drawer.tsx:430` — discount-type segmented control — **tabs-sliding** — selection is a flat color flip with no moving highlight.
- `resources/js/components/admin/customers/admin-customer-wallet-adjust-dialog.tsx:238` — Credit/debit direction buttons — **tabs-sliding** — no sliding highlight shows which way money moves.
- `resources/js/components/store/catalog/sbc-product-configurator.tsx:531` — platform radio selection highlight — **tabs-sliding** — the chosen platform highlight jumps between cells instead of sliding.
- `resources/js/pages/admin/marketing/page-editor.tsx:355` — locale AR/EN tab highlight — **tabs-sliding** — the active pill snaps with no moving highlight, so switching languages feels like a page jump
- `resources/js/pages/admin/overview.tsx:128` — active date-range button — **tabs-sliding** — filled highlight jumps between options instead of sliding.
- `resources/js/pages/auth/login.tsx:158` — email/phone login method tabs — **tabs-sliding** — the selected method should glide, not snap, on the primary auth control.
- `resources/js/pages/store/category.tsx:216` — filter buttons — **tabs-sliding** — switching a filter has no moving highlight to show the change.
- `resources/js/pages/admin/settings.tsx:40` — section navigation segmented control pill bar — **tabs-sliding** — maintains spatial continuity and clarifies active section location

## input-clear-dissolve (8)

- `resources/js/components/admin/categories/admin-categories-toolbar.tsx:191` — search clear X affordance — **input-clear-dissolve** — the clear button mounts and unmounts with no fade.
- `resources/js/components/admin/customers/admin-customers-toolbar.tsx:199` — search clear X button appears — **input-clear-dissolve** — the icon pops in instantly when text is typed, jarring against the input.
- `resources/js/components/admin/products/admin-products-toolbar.tsx:261` — clear-search button appearing in field — **input-clear-dissolve** — the clear affordance pops in with the first typed character.
- `resources/js/components/admin/reviews/admin-reviews-toolbar.tsx:94` — clear-search X affordance — **input-clear-dissolve** — the button and query vanish instantly instead of dissolving.
- `resources/js/components/configurator/manual-services/squad-upload.tsx:74` — remove-image clearing the upload — **input-clear-dissolve** — deleting the squad shot is instant and easy to mistrust.
- `resources/js/pages/account/orders.tsx:180` — search clear (X) button — **input-clear-dissolve** — clearing wipes the field with no transition
- `resources/js/pages/auth/reset-password.tsx:33` — password fields cleared on success — **input-clear-dissolve** — `resetOnSuccess` wipes both fields with no visible clear
- `resources/js/components/one-time-code-field.tsx:80` — cleared digit in active OTP cell — **input-clear-dissolve** — softens abrupt disappearance when correcting a misplaced verification digit

## accordion (12)

- `resources/js/components/account/account-order-row.tsx:94` — order-items list opening under "details" — **accordion** — the only tap-to-reveal per order snaps open with no height transition.
- `resources/js/components/admin/orders/admin-order-transition-controls.tsx:337` — hold reason/note field group reveal — **accordion** — conditional inputs pop in with no height transition and shove the form
- `resources/js/components/admin/settings/admin-security-section.tsx:698` — regenerate confirmation panel — **accordion** — expanding it shoves the page down abruptly.
- `resources/js/components/admin/settings/admin-security-section.tsx:765` — forget-devices confirmation panel — **accordion** — same abrupt height change when toggled.
- `resources/js/components/configurator/manual-services/rivals-configurator.tsx:534` — PC launcher block — **accordion** — it eases in but vanishes instantly when PlayStation is reselected
- `resources/js/components/store/faq-section.tsx:48` — FAQ answer paragraph — **accordion** — the core disclosure snaps open with no height/opacity transition while only the chevron rotates
- `resources/js/pages/store/cart.tsx:1269` — coupon field expansion — **accordion** — the coupon input pops open with no reveal.
- `resources/js/components/store/store-footer.tsx:125` — collapsible legal navigation links group — **accordion** — compacts legal policies on mobile viewports to reduce excessive vertical scrolling
- `resources/js/components/store/store-information-page.tsx:90` — policy document section heading blocks — **accordion** — collapses lengthy legal terms to simplify mobile navigation
- `resources/js/components/ui/alert.tsx:28` — dynamically mounted inline alert container — **accordion** — prevents jarring layout push when banners appear
- `resources/js/pages/admin/customers/index.tsx:196` — customer filters and search toolbar — **accordion** — smoothly accommodates dynamic height change when filter chips mount
- `resources/js/pages/admin/customers/show.tsx:263` — feedback alert message notification banner container — **accordion** — prevents abrupt vertical layout shift when action feedback appears

## shimmer-text (7)

- `resources/js/components/account/order-review-card.tsx:188` — submit button's "submitting" state — **shimmer-text** — the waiting state is plain text with no in-progress feedback.
- `resources/js/components/chat/chat-message-list.tsx:216` — opening-chat loading text — **shimmer-text** — the loader only pulses, so it reads as stuck rather than working.
- `resources/js/components/configurator/coins/summary-step.tsx:121` — pending button showing "adding" — **shimmer-text** — nothing signals the network call is working while the customer waits on phone.
- `resources/js/pages/account/live-order.tsx:762` — credentials-loading label — **shimmer-text** — the only progress cue while sensitive credentials load
- `resources/js/pages/admin/marketing/page-editor.tsx:322` — "Saving…" header state — **shimmer-text** — in-progress label should signal live work
- `resources/js/components/admin/products/admin-variant-revert-dialog.tsx:228` — reverting to automation status label — **shimmer-text** — signals ongoing background pricing automation restoration
- `resources/js/pages/admin/confirm-2fa.tsx:216` — submit button active verification loading copy — **shimmer-text** — provides continuous in-progress reassurance during high-latency network authentication requests

## learn-more-hover (9)

- `resources/js/components/admin/admin-attention-strip.tsx:148` — view-unresolved-orders link hover — **learn-more-hover** — arrow link brightens on hover with no transition, so the affordance feels dead.
- `resources/js/components/admin/admin-recent-orders.tsx:55` — "view all orders" arrow — **learn-more-hover** — the static arrow gives no hover feedback on a key drill-in.
- `resources/js/components/admin/admin-revenue-chart.tsx:98` — "view all orders" arrow — **learn-more-hover** — identical dead arrow on the chart header link.
- `resources/js/components/admin/products/admin-products-columns.tsx:252` — view-detail link hover — **learn-more-hover** — only a plain underline, no motion to signal the affordance.
- `resources/js/components/configurator/manual-services/manual-service-suggestions.tsx:53` — SBC “see all” arrow — **learn-more-hover** — the text CTA gives no hover cue while the card beside it does
- `resources/js/components/account/wallet-ledger.tsx:76` — related order details interactive destination link — **learn-more-hover** — communicates clickability and interactive target on pointer hover
- `resources/js/components/store/store-information-page.tsx:26` — inline legal article reference text link — **learn-more-hover** — provides smooth underline feedback on reference link hover
- `resources/js/components/ui/button.tsx:21` — interactive text action button link variant — **learn-more-hover** — smooths the text underline transition on hover instead of snapping abruptly
- `resources/js/pages/store/sitemap.tsx:65` — sitemap directory group navigation links — **learn-more-hover** — smooths underline color change on pointer hover instead of snapping

## thinking-states (4)

- `resources/js/components/admin/categories/admin-categories-toolbar.tsx:202` — search submit while navigating — **thinking-states** — only the controls disable, so the operator sees no progress cue on the button.
- `resources/js/components/chat/chat-header.tsx:120` — restart conversation icon — **thinking-states** — while restarting the icon only pulses, inviting repeat taps.
- `resources/js/components/chat/chat-message-list.tsx:494` — typing indicator to first token — **thinking-states** — the handoff from dots to streaming text is a bare unmount here.
- `resources/js/components/store/catalog/sbc-product-configurator.tsx:356` — submit button entering loading — **thinking-states** — the pending state on the configurator CTA has no in-progress motion after the click.

## page-side-by-side (7)

- `resources/js/components/admin/settings/admin-security-section.tsx:317` — MFA enrollment state swap — **page-side-by-side** — four setup layouts replace each other with no spatial cue, so users lose where they are in the flow.
- `resources/js/components/chat/chat-widget.tsx:775` — transcript remount on conversation switch — **page-side-by-side** — opening a past conversation hard-cuts the whole list with no directional motion.
- `resources/js/pages/auth/login.tsx:184` — email form swapping to phone — **page-side-by-side** — the entire panel swaps abruptly when the method changes.
- `resources/js/components/store/store-information-page.tsx:232` — store information page root article view — **page-side-by-side** — ensures fluid horizontal sliding between policy documentation views
- `resources/js/pages/admin/customers/show.tsx:517` — mobile recent order list row link — **page-side-by-side** — communicates spatial navigation from customer orders to order details
- `resources/js/pages/admin/customers/show.tsx:186` — back to customers list navigation link — **page-side-by-side** — establishes spatial hierarchy when returning to customer directory
- `resources/js/pages/admin/more.tsx:68` — admin navigation hub tile link — **page-side-by-side** — lacks lateral slide transition when navigating between hub list and detail management screens on mobile viewports

## toast (3)

- `resources/js/components/chat/chat-message-list.tsx:564` — new-messages scroll pill — **toast** — the pill vanishes the moment you reach the bottom, with no exit.
- `resources/js/pages/admin/reviews/index.tsx:146` — review load-failure alert — **toast** — a transient failure inserts a banner that shoves the toolbar and table down the page.
- `resources/js/pages/admin/marketing/coupons/show.tsx:311` — action feedback alert status message banner — **toast** — abrupt layout insertion displaces page content instead of floating smoothly

## toggle (1)

- `resources/js/pages/store/cart.tsx:616` — wallet toggle checkbox — **toggle** — the wallet switch flips with no toggle motion.

## menu-dropdown (2)

- `resources/js/components/store/store-preferences.tsx:115` — preferences dialog open/close — **menu-dropdown** — the panel is hard-mounted and unmounted by `isOpen` with no anchored grow/fade and no exit motion, so it pops in and vanishes from the gear trigger.
- `resources/js/components/phone-number-field.tsx:60` — country code selector picker dropdown menu — **menu-dropdown** — softens anchored list appearance during country selection

## card-tilt (3)

- `resources/js/components/store/service-rail.tsx:39` — service card surface — **card-tilt** — the card lifts on hover but its artwork never reacts to the pointer
- `resources/js/components/store/store-information-page.tsx:209` — customer assistance WhatsApp support callout card — **card-tilt** — provides tactile response to encourage customer engagement
- `resources/js/pages/admin/more.tsx:68` — interactive administration hub navigation card — **card-tilt** — static cards lack responsive 3D perspective feedback under pointer movement on larger displays

## banner-stacking (2)

- `resources/js/pages/admin/reviews/index.tsx:127` — feedback banner mount/dismiss — **banner-stacking** — both alert slots swap in and out instantly with no shared entrance or exit.
- `resources/js/components/store/cart-added-notice.tsx:198` — toast notice container on consecutive adds — **banner-stacking** — consecutive additions abruptly overwrite the notice rather than stacking toasts
