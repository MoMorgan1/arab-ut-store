# WordPress Hero and Coins Configurator Parity

**Status:** Approved from Mohamed's 2026-08-09 browser feedback  
**Scope:** Homepage hero and the existing Coins platform, delivery, and amount flow  
**Complexity:** Medium
**Implementation status:** Verified on 2026-08-09 through the full automated gate and the Arabic/English browser matrix.

## Outcome

The new Laravel/React storefront will retain its server-authoritative pricing and accessible state management while reproducing the approved WordPress homepage and Coins configurator faithfully. WordPress parity comes first; Frontend Design, UI/UX Pro, and Impeccable are then used to improve accessibility, responsive behavior, hierarchy, and finish without inventing a different visual identity.

This revision does not add account credentials, cart, checkout, or payment steps. Those surfaces will be added only when their working backend flows exist.

## Considered approaches

1. **WordPress-faithful live-step parity — selected.** Reproduce the WordPress hero, platform, delivery, and amount interactions while keeping only the steps that work in the MVP. This gives Mohamed the design he approved without introducing dead checkout controls.
2. **Literal five-step WordPress clone.** Copy credentials and payment steps too. Rejected because those flows are not implemented and would create misleading or nonfunctional controls.
3. **Add only a native range input to the current amount screen.** Rejected because it would not match the WordPress experience Mohamed asked to preserve.

## Required design workflow

1. Inspect the matching live WordPress state and the exported theme/plugin implementation before changing the React/CSS equivalent.
2. Match the verified WordPress structure, order, proportions, official assets, Thmanyah typography, warm black/gold surfaces, selected states, and responsive behavior for the working MVP steps.
3. Do not add WordPress controls for credentials, checkout, or payment until those flows work. Literal parity applies to the implemented platform, delivery, and amount flow, not to dead future controls.
4. After parity, run Frontend Design, UI/UX Pro, and the relevant Impeccable passes. Improvements may fix contrast, spacing, focus, touch geometry, overflow, and hierarchy but may not replace the approved WordPress design with a generic alternative.
5. Any consequential departure from the WordPress reference requires Mohamed's approval.

Generic design-system suggestions are advisory. The verified WordPress reference overrides generated palettes, fonts, layouts, and effects when they conflict.

## Hero design

The hero continues using the exact WordPress crest and responsive premium backgrounds already copied into the app.

### Arabic copy

- Eyebrow/slogan: `كل اللي تحتاجه في FC 27، بمكان واحد`
- Main title: `كوينز فيفا 27`
- Gold line: `بأفضل الأسعار`
- Supporting line: `نوصل كوينز فيفا 27 لحسابك بسرعة وأمان — مع ضمان كامل أو استرجاع.`
- Primary anchor: `اختر كوينزك`, linked to `#coins`

### English copy

- Eyebrow/slogan: `Everything you need for FC 27, all in one place.`
- Main title: `FIFA 27 Coins`
- Gold line: `At the best prices`
- Supporting line: `Fast, secure FIFA 27 Coins delivery to your account — backed by our guarantee or a refund.`
- Primary anchor: `Choose your Coins`, linked to `#coins`

The hero restores four proof items in the same WordPress order, with thin separators and no invented icons. The first two values are calculated from the supplied exports; the final two remain the approved fixed marketing values because the export cannot prove them:

| Value | Arabic label | English label |
| --- | --- | --- |
| `+8,877` | `عميل خدمناهم` | `Customers served` |
| `+29,161` | `طلب مكتمل` | `Completed orders` |
| `+30 مليار` / `30B+` | `كوينز تم توصيلها` | `Coins delivered` |
| `99.9%` | `نسبة الأمان` | `Security rate` |

`8,877` is the number of distinct normalized customer mobile numbers attached to at least one completed order. `29,161` is the count of unique orders whose source status is one of the five audited completed statuses. The raw customer export contains 13,081 source customer IDs, while the order export contains 34,211 unique orders and 36,210 item rows. The selected Coins amount is absent from most dynamic `COIN_PS` and `COIN_PC` rows, so total Coins delivered cannot be recomputed reliably.

The proof strip remains a compact single row where practical and becomes a balanced two-column grid only if required to prevent overflow at narrow widths.

## Typography

- Body and controls: self-hosted `Thmanyah Sans`.
- Display headings: self-hosted `Thmanyah Serif Display`.
- Register the WordPress weights 300, 400, 500, 700, and 900 from the supplied export.
- Remove the storefront body's effective `Instrument Sans` override.
- Hero and section headings use Serif Display; stats, inputs, buttons, and configurator copy use Sans.
- Fonts remain local with `font-display: swap`; no third-party font request is introduced.

## Configurator design

The public platform contract remains exactly two choices: one combined `PS / Xbox` card using both original logos, and one `PC` card using the original PC asset.

- PS/Xbox continues to the delivery step.
- PC skips delivery and continues directly to amount.
- Only implemented steps are shown in the progress rail.
- The progress rail follows the WordPress dot-and-line treatment: labels align with their dots, connector lines sit behind the dots without crossing text, the current step uses solid gold, and completed steps may navigate backward only.
- Existing focus movement, RTL/LTR behavior, keyboard access, announcements, and back navigation remain intact.
- The visible `Selected: ...` / `المحدد: ...` strip is removed. Selection changes remain available through a visually hidden live region for assistive technology.

### Delivery step

- Preserve the WordPress card order, proportions, surfaces, selection mark, and responsive stacking.
- Normal delivery shows its lower-budget positioning, approximately 150 minutes per million, and 2M maximum.
- Fast delivery shows its recommended positioning, approximately 45 minutes per million, and 20M maximum.
- The step helper uses concise Gulf Arabic rather than repeating limits already visible in the cards.
- Primary and back actions retain the WordPress hierarchy and at least 44px touch targets.

### Amount selector order

The amount step follows the deployed WordPress order:

1. Step title and the exact Arabic helper `اكتب الكمية اللي تبيها.`; English receives the equivalent concise instruction.
2. Editable formatted amount display with the localized Coins unit.
3. Five quick chips above the range track: `50K`, `100K`, `500K`, `1M`, `5M`; chips above the active maximum are omitted.
4. Accessible range input with a gold filled track and WordPress-style thumb.
5. Endpoint labels below the track: `50K` and the active maximum (`2M` or `20M`).
6. Adjustment controls: `-1M`, `-500K`, `-100K`, `-50K`, `+50K`, `+100K`, `+500K`, `+1M`.
7. Live server-authoritative total, arranged as the highest-priority result after the amount controls with a stable tabular price and clear loading, validation, and unavailable states.
8. Back navigation only. The visible restart action is removed.

### Quantity rules

- Minimum: `50,000`.
- Runtime increment: `10,000`.
- Initial value: `50,000`.
- PS/Xbox normal maximum: `2,000,000`.
- PS/Xbox fast maximum: `20,000,000`.
- PC maximum: `2,000,000`.
- Input, quick chips, range, and adjustment controls always share one quantity state.
- Changes clamp to the selected maximum and snap to the 10K increment.
- Holding or clicking a control cannot create a value outside the active bounds.
- Dragging the slider updates the display immediately while retaining the existing debounced request, abort, and stale-response protection.

The quote endpoint, integer SAR calculation, availability checks, and fail-closed response parsing do not change.

## Responsive and accessible behavior

- Slider and buttons keep at least a 44px touch target.
- Five quick chips stay in one row when space permits and never overflow the viewport.
- Platform choices remain side by side on mobile.
- The range remains numerically and visually left-to-right in both locales, matching the deployed WordPress control; the surrounding labels and controls still follow the page direction.
- The range input has an explicit localized accessible name and exposes min, max, step, and current value.
- Amount entry remains usable by keyboard and mobile numeric input.
- `prefers-reduced-motion` is respected.

## Component boundaries

- `StoreHome` renders the hero copy and proof strip.
- `CoinsConfigurator` remains the owner of the selected platform, delivery, quantity, navigation, and quote lifecycle.
- `AmountStep` renders the amount display, quick chips, range, adjustment controls, and quote panel.
- `ProgressRail` owns backward-only completed-step navigation and never exposes future steps as interactive.
- Formatting helpers create compact K/M labels and localized full quantities.
- Locale files remain the only source of customer-facing Arabic and English copy.
- Server-provided limits remain authoritative; the UI does not hardcode a competing pricing contract.

## Failure handling

- Invalid typed input does not trigger a quote.
- Blur or commit normalizes a valid quantity and restores the last valid bounded value for unusable input.
- Unavailable or mismatched quote responses keep the existing localized fail-closed state.
- Changing platform or delivery clamps the quantity before the next quote.

## Verification contract

Automated tests will first fail and then cover:

- exact Arabic/English hero copy and the four proof items;
- exact Thmanyah storefront font contract;
- exact two-platform behavior;
- no visible selection-announcement strip while screen-reader announcements remain available;
- exact WordPress progress hierarchy and backward-only completed-step navigation;
- delivery-card WordPress parity, selected states, timing, caps, and responsive layout;
- all five quick chips and their DOM order above the range;
- slider min, max, step, value, and dynamic maximum;
- synchronization between typed input, chips, range, and adjustment controls;
- clamping, snapping, keyboard input, and PC delivery skipping;
- unchanged debounced quote, abort, stale-response, and fail-closed behavior;
- no dead cart, checkout, credential, or payment controls;
- concise amount helper copy, no visible restart action, and a stable high-priority total layout.

Browser verification covers Arabic and English at 320, 390, 768, and 1440 pixels, including font loading, proof-strip wrapping, the WordPress left-to-right range direction, touch geometry, focus rings, and horizontal overflow.

## Inputs and dependencies

No new account, service, API, or library is required. The supplied WordPress export is the authority for assets, fonts, CSS proportions, and deployed slider behavior. The live Laravel quote endpoint remains the authority for price and limits.
