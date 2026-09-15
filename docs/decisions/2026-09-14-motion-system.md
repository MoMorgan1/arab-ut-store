# Arab UT — Motion System Spec v2

Owner: Mohamed. Drafted by Claude, adversarially reviewed by `gpt-5.6-sol` (read-only, via Codex), revised here.
v1 verdict from that review was **"No — not safe to execute as written."** This version answers every BLOCKER and SHOULD-FIX it raised.

---

## 0. Corrections carried in from the review

Recorded because they change the plan, not as bookkeeping:

| v1 claim | Corrected |
| --- | --- |
| Admin KPIs change silently → need `number-pop-in` | **Wrong.** They are server props that change through explicit date-range navigation, and a loading indicator already exists (`pages/admin/overview.tsx:124`, `:140`). The admin half of WP5 is **cut**. |
| `app.css` is 16,107 lines / 53 keyframes / 32 reduce blocks | Live working tree: **16,058 / 49 / 28**. Committed baseline: 15,796 / 49 / 25. The file is being edited right now on `feat/glass-controls` (+333/−71 uncommitted). |
| `--store-ease-out` has 115 call sites | **114 `var()` call sites** plus 1 declaration. |
| WP2 normalises every stagger | **Contradicted the chat deferral.** Chat's 50ms stagger (`app.css:15240`) is now explicitly out of scope. |
| Findings are work items | **They are candidates.** Several are already animated — `manual-price-pop` (`app.css:9441`, `:9856`) is a confirmed false positive. Every WP verifies before it edits. |
| Ranking by customer risk | That ranking was inferred from source with no browser session. Sequencing now leads with what is verifiable, not with what was guessed to be urgent. |

**Coverage:** 150 of 185 component files audited by DeepSeek; the remaining 35 by Gemini 3.8 Flash. 450+ candidate findings. No finding has been confirmed in a browser yet.

---

## 1. Prerequisite before any WP touches `app.css`

`feat/glass-controls` has uncommitted `app.css` work in flight. **WP0 is safe to start now** (it touches `tokens.css` only, which that branch does not). **WP1 and WP2 must wait for `feat/glass-controls` to land**, or they will conflict inside the same regions.

Every branch starts from an up-to-date `main` per `CLAUDE.md`.

---

## 2. Constraints — non-negotiable

| Rule | Consequence |
| --- | --- |
| Branch first; never push or merge `main` | Owner merges every PR |
| `npm run ci:check` + `composer test` green | Agent runs them; orchestrator re-runs them |
| 390px is the primary viewport | Verify there **first**, then 320 / 768 / 1440 |
| Arabic RTL and English LTR are equal | Directional motion is an implementation rule, not just a check — see §4 |
| Reduced motion | Stronger posture than v1 — see §4 |
| Extend the established language | House easing only; no motion library |
| Worker results are claims | Re-verified locally |

**Owner decision, already given:** WP0–WP3 proceed as refinement with no `/design` canvas. **Any WP that adds new visible motion to the purchase path takes a canvas first** — that is WP4, WP5, WP9, WP10 on storefront and configurator surfaces.

---

## 3. WP0 — the motion scale

Add to `resources/js/styles/tokens.css` (imported at `app.css:5`):

```css
:root {
    /* Motion scale. Duration is chosen by USAGE, not by feel. */
    --motion-stagger: 40ms;
    --motion-micro: 80ms;
    --motion-quick: 150ms;    /* hover/focus colour, text swap, close of a small surface */
    --motion-fast: 250ms;     /* icon swap, dropdown/modal open, tab slide, page slide */
    --motion-medium: 350ms;   /* panel close, toast close */
    --motion-slow: 400ms;     /* panel open, skeleton reveal, input clear */
    --motion-emphasis: 500ms; /* badge appear, text reveal, success check */

    --motion-ease: cubic-bezier(0.16, 1, 0.3, 1);
    --motion-ease-in-out: ease-in-out;
    --motion-ease-linear: linear;
    --motion-ease-spring: cubic-bezier(0.34, 1.56, 0.64, 1); /* == existing --chat-ease-spring */
}
```

**Easing stays the house curve** `cubic-bezier(0.16, 1, 0.3, 1)`. The review tested this and defended it: 114 call sites use it and chat adopts it too (`app.css:15178`). The catalog contributes a *duration taxonomy*, not code whose feel depends on its own curve.

**The spring token reuses `--chat-ease-spring`'s exact value** rather than introducing transitions.dev's `cubic-bezier(0.34, 1.36, 0.64, 1)`. v1's version added a fourth curve to a codebase that already had one for this purpose.

**Aliasing and exit criterion (answers C2).** In the same PR:

```css
.store-shell { --store-ease-out: var(--motion-ease); }
```

so both names resolve to one value immediately. WP0 also produces `docs/motion-migration.md` listing all 114 call sites. `--store-ease-out` is deleted in the PR that empties that list — not before, and the list is the exit criterion.

**Tailwind bridge (answers a SHOULD-FIX).** Components use Tailwind duration utilities, not raw CSS. WP0 defines the mapping in the same PR — either `@theme` entries exposing `duration-quick`/`duration-fast`/… or a documented `duration-[var(--motion-quick)]` convention. **The agent picks one and documents it; it does not leave this to WP3.**

Acceptance: tokens exist, both names resolve identically, migration inventory written, Tailwind bridge decided and documented, `ci:check` green, **zero visual change anywhere**.

---

## 4. Rules every later WP obeys

**Reduced motion — opt in, do not bolt on.** New motion is written under `@media (prefers-reduced-motion: no-preference)` so the default state is "no motion", the pattern chat already uses (`app.css:14982`, `:15192`). JS-driven motion reads `matchMedia('(prefers-reduced-motion: reduce)')` and skips the animation. **A suppressed animation must still leave the element in its final state** — never mid-transition, never invisible. The 28 existing `prefers-reduced-motion` blocks stay; extending them is not sufficient on its own.

**RTL — a build rule, not a test.** Any directional motion (slide, page transition, panel entry, stagger direction) uses a logical direction variable or mirrored selectors, the way chat already does (`app.css:15253`, `:15289`). "Verify in both directions" is the check, not the implementation.

**Replay without forced reflow.** For replayable motion (shake, number pop-in, success check) use the **Web Animations API** (`el.animate(...)`, `getAnimations().forEach(a => a.cancel())`) instead of `void el.offsetWidth`. For SVG stroke drawing use `pathLength="1"` and a `stroke-dasharray: 1` fraction rather than a measured `getTotalLength()`. Still no motion library.

**Verify before editing.** Each finding is a candidate. If the moment already animates acceptably, the agent drops it and records the drop in the PR. `manual-price-pop` is the worked example.

**Existing systems to integrate with, not duplicate:** `tw-animate-css` (`app.css:3`), the global reveal system (`app.css:15522`), and `resources/js/styles/order-tracking.css` (6 keyframes). An agent that reinvents any of these has failed the package.

---

## 5. File ownership — the anti-conflict map (answers the BLOCKERs)

Only one package may edit a region of `app.css` at a time. Known collision zones from the review:

| Region | Lines (live tree) | Owner |
| --- | --- | --- |
| manual services | 8309–8428 | WP2 only |
| SBC cards | 10701–10810 | WP2 only |
| chat | 14957–15242 | **nobody — deferred** |
| shared keyframes / reduce blocks | 12608–12791, 14968–15539 | WP0 declares, later WPs append at the end of their own section |

**New primitives do not live in `app.css`.** Each primitive (shake, number pop-in, text swap, icon swap) gets its own file under `resources/js/styles/motion/` imported from `app.css`, so packages never contend for insertion points in the monolith. WP0 creates the directory and the import.

**Line numbers are re-extracted at the start of every package**, never carried over from this document — the file moves.

---

## 6. Work packages

Each ships with: exact target list (regenerated at start), the primitive's file, and a verification note per finding.

**WP1 — hover/focus timing.** 18 sites at 140/150/160/180ms for the same interaction. All → `var(--motion-quick) var(--motion-ease)`. Includes `app.css:13052`, which hardcodes the curve literally. Excludes transform hovers and keyframes. *CSS only.*

**WP2 — stagger.** Normalise to multiples of `--motion-stagger`. Scope: manual services (8309–8428), SBC cards (10701–10810). **Excludes chat (15240).** Hero stats' 90ms step: argue keep-or-normalise in the PR.

**WP3 — open/close asymmetry.** Every paired surface names both directions. `ui/dialog.tsx:61` (one 200ms for both) is the main offender; `ui/sheet.tsx:61` (500 open / 300 close) is the reference. Uses the Tailwind bridge WP0 defined.

**WP4 — error-state shake.** Primitive in `motion/shake.css` + a hook using WAPI. Applied to `configurator/manual-services/` and `pages/auth/`. `.is-error` and `.is-shaking` stay orthogonal. Acceptance: invalid submit shakes the field, moves focus to it, and is silent under reduced motion. *Canvas required.*

**WP5 — number pop-in.** Scope: `configurator/coins/quote-panel.tsx` and `pages/store/cart.tsx` totals. **Admin is cut.** Keyed by digit *value and position*, not array index, so unchanged digits do not animate. Must not break localized separators, currency symbols, bidi order, or screen-reader output — the figure keeps a single accessible reading. *Canvas required.*

**WP6 — text states swap.** One primitive, applied by class. Largest count, so verification (drop the false positives) matters more here than anywhere else.

**WP7 — icon swap.** One primitive, cross-fade with blur.

**WP8 — panel reveal.** Check the Radix wrapper first; most of these should collapse into WP3. Whatever remains is genuinely custom.

**WP9 — skeleton reveal.** `catalog-skeleton-grid.tsx` already pulses; add only the cross-fade to loaded content. *Canvas required.*

**WP10 — success check.** `pathLength="1"`, not `getTotalLength()`. *Canvas required.*

**WP11 — long tail.** *Not executable as written and not dispatched yet.* Before it becomes a package it needs its own target list and a primitive API per item. It is scoped **after** WP6/WP7 exist, since most of the tail reduces to those two primitives.

**Chat — deferred.** 33 findings, own 13-keyframe system, own easings, already does `no-preference` opt-in and RTL handling properly. Separate review later. No package touches it.

---

## 7. PR grouping (answers C7)

Five PRs, not twelve:

1. **WP0** — tokens, alias, migration inventory, Tailwind bridge, `motion/` directory.
2. **WP1 + WP2 + WP3** — normalisation. One PR, one reviewer pass, no internal conflicts.
3. **WP4 + WP6 + WP7** — primitives with no canvas dependency (WP4 needs its canvas first).
4. **WP5 + WP9 + WP10** — purchase-path motion, after canvas approval.
5. **WP8** — whatever survives the Radix check.

WP11 gets scoped after PR 3 lands.

---

## 8. Agent protocol

- `agy --model gemini-3.8-flash-high`, one package per agent, one branch per **PR group**.
- Reads `CLAUDE.md`, `AGENTS.md`, `.impeccable.md` first. Touches only its declared files. Never reformats unrelated CSS, never renames an existing variable, never weakens a reduce block, never adds a dependency.
- Runs `npm run lint:check`, `types:check`, `test`, `format:check` before handing back.
- **Hands back a diff. Never commits, never pushes, never touches `main`.**
- Orchestrator re-runs every gate.

## 9. Verification per PR

1. `ci:check` + `composer test` green (re-run locally).
2. **390px, touch emulation, Arabic RTL** — first.
3. 320 / 768 / 1440, English LTR.
4. `prefers-reduced-motion: reduce`: no new motion runs, and every element still lands in its final state.
5. Keyboard focus visible and ordered; 44px targets intact.
6. Console clean; Playwright green.
7. Each dropped finding is listed with the reason.

## 10. Non-goals

No motion library. No chat changes this phase. No admin number motion. No copy changes. No new colours. No new easing curves beyond §3. No `--store-ease-out` deletion until its inventory is empty. No input font-size changes.
