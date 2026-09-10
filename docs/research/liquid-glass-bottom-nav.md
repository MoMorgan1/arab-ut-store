# Liquid Glass for a web bottom navigation

Research date: 2026-09-10. Primary = Apple, MDN, WebKit, W3C, vendor repos; secondary = third-party write-ups and component READMEs.

## Summary

- **Liquid Glass is lensing, not frosting.** Earlier Apple materials "scattered light"; this one "dynamically bends, shapes, and concentrates light in real time" ([WWDC25 219](https://developer.apple.com/videos/play/wwdc2025/219/)). A bigger `blur()` moves away from the material, not toward it.
- **Apple ships two variants**: `regular` (blurs, adjusts luminosity, for text-heavy bars) and `clear` (needs a 35% dimming layer over bright content). Bars are a distinct functional layer, never the content layer, and the effect should be used sparingly ([HIG Materials](https://developer.apple.com/design/human-interface-guidelines/materials)).
- **Real edge refraction is not shippable.** `backdrop-filter: url(#svgFilter)` is Chromium-only; Safari's bug is open and its test case currently **crash-loops the GPU process** on WebKit trunk ([WebKit 245510](https://bugs.webkit.org/show_bug.cgi?id=245510)).
- **Don't rely on `prefers-reduced-transparency`**: Chromium 119+ only; Safari and Firefox have not shipped it ([Web Features Explorer](https://web-platform-dx.github.io/web-features-explorer/features/prefers-reduced-transparency/)).
- **Blur cost scales with radius, not surface count**: `blur(200px)` stalled first paint ~15s on Safari 26 while 8px and 64px stayed fast ([WebKit 316769](https://bugs.webkit.org/show_bug.cgi?id=316769)); Chromium recompiles a shader per animated radius ([csswg #13997](https://lists.w3.org/Archives/Public/public-css-archive/2026Jun/0011.html)). 34px is safe; never animate it.
- **Translucent text over scrolling content cannot be pre-verified for WCAG AA** — the criterion measures the actual adjacent colours, which move. Fix with a scroll-state opacity step plus a testable opaque fallback ([WCAG 1.4.3](https://www.w3.org/WAI/WCAG22/Understanding/contrast-minimum.html)).

## What Apple's Liquid Glass actually is

Apple calls it "a new digital meta-material that dynamically bends and shapes light," whose "primary way … [of] visually defin[ing] itself is through something called Lensing," providing separation and layering "while letting content shine through underneath it." Objects **do not fade**: they "materialize in and out by gradually modulating the light bending and lensing" ([WWDC25 219](https://developer.apple.com/videos/play/wwdc2025/219/)).

The HIG supplies the rules ([HIG Materials](https://developer.apple.com/design/human-interface-guidelines/materials)): it "forms a distinct functional layer for controls and navigation elements — like tab bars and sidebars — that floats above the content layer"; "**Don't use Liquid Glass in the content layer**"; "**use Liquid Glass effects sparingly**… overusing this material in multiple custom controls can provide a subpar user experience." The `regular` variant "blurs and adjusts the luminosity of background content to maintain legibility" — its job is contrast, not decoration — and `clear` is "highly translucent," wanting "a dark dimming layer of 35% opacity" over bright backgrounds. Vibrancy, not raw colour, keeps labels legible.

**Shape** is governed by *concentricity*: "fixed shapes have a constant corner radius. Capsules use a radius that's half the height of the container. And concentric shapes calculate their radius by subtracting padding from the parent's" ([WWDC25 356](https://developer.apple.com/videos/play/wwdc2025/356/)). **Adaptivity** covers light/dark, overlap, focus, and accessibility settings that "reduce transparency or motion" ([Adopting Liquid Glass](https://developer.apple.com/documentation/technologyoverviews/adopting-liquid-glass)). iOS tab bars "float above content at the bottom of the screen… on a Liquid Glass background that allows content beneath to peek through" ([HIG Tab bars](https://developer.apple.com/design/human-interface-guidelines/tab-bars)).

**Rim lighting has no published quantitative spec.** The "bright hairlines top and bottom, dark flanks" reading comes from pixel-sampling macOS Control Center and is secondary ([sohumsuthar/liquid-glass](https://github.com/sohumsuthar/liquid-glass)) — a useful approximation, not a spec.## The behaviours, and what is achievable on the web

| Behaviour | Web technique | Support / caveats |
| --- | --- | --- |
| Blur + saturation/brightness of what's behind | `backdrop-filter: blur() saturate() brightness()` (+ `-webkit-` prefix) | Baseline Sept 2024 ([MDN](https://developer.mozilla.org/en-US/docs/Web/CSS/backdrop-filter)). Cost, not support, is the constraint. |
| Edge refraction / lensing | `backdrop-filter: url(#filter)` with `feDisplacementMap` | **Chromium only.** Safari ignores it (open [WebKit 245510](https://bugs.webkit.org/show_bug.cgi?id=245510)), Firefox too ([html-in-canvas.dev](https://html-in-canvas.dev/liquid-glass-effect/), [liquid-glass-react](https://github.com/rdev/liquid-glass-react)); WebKit trunk's test case currently crash-loops the GPU process. `feDisplacementMap` is Baseline 2015 only for `filter` ([MDN](https://developer.mozilla.org/en-US/docs/Web/SVG/Reference/Element/feDisplacementMap)). |
| Progressive softening at the bar's top edge | `mask-image: linear-gradient(...)` | Baseline Dec 2023 ([MDN](https://developer.mozilla.org/en-US/docs/Web/CSS/mask-image)). Real progressive *blur* (`filter-mask`) is an unshipped CSSWG proposal ([#13288](https://lists.w3.org/Archives/Public/public-css-archive/2026Jan/0015.html)). |
| Specular rim / highlights | `inset` `box-shadow`, `border` + `background-clip: padding-box`, `::before`/`::after` gradients, `border-image` | Universal and cheap. Hairlines must be ≥1 physical px or they vanish and break WCAG 1.4.11. |
| Concentric shape | `border-radius: calc(var(--parent-radius) - var(--inset))` | Universal. `corner-shape: squircle` is Chromium 139+ only ([sohumsuthar](https://github.com/sohumsuthar/liquid-glass)). |
| Light/dark adaptivity | `color-scheme` + `light-dark()` on tint and highlights | Baseline May 2024 ([MDN](https://developer.mozilla.org/en-US/docs/Web/CSS/Reference/Values/color_value/light-dark)). **Backdrop luminance is unreadable from CSS/JS** — sample the tier background instead. |
| Touch/pointer highlight under the finger | `:active`, `:focus-visible`, `transition: transform/opacity`, sheen moved by `transform` | No support issue. Avoid `pointermove` JS writing `background`/`box-shadow`; that repaints every frame. |
| Reduced transparency | `@media (prefers-reduced-transparency: reduce)` | **Chromium 119+ only**; Firefox none; Safari "Not supported", position cites privacy ([WFE](https://web-platform-dx.github.io/web-features-explorer/features/prefers-reduced-transparency/), [MDN](https://developer.mozilla.org/en-US/docs/Web/CSS/Reference/At-rules/@media/prefers-reduced-transparency)). Never the only fallback. |
| Increased contrast / reduced motion | `@media (prefers-contrast: more)`, `@media (prefers-reduced-motion: reduce)` | Both Baseline ([MDN](https://developer.mozilla.org/en-US/docs/Web/CSS/Reference/At-rules/@media/prefers-contrast)). Override duration rather than deleting the state change. |
| Solid fallback without `backdrop-filter` | `@supports not (backdrop-filter: blur(1px))` → opaque brand surface | Works everywhere, but Safari's handling of this test is reported as unreliable — secondary and unverified here ([CSDN Q&A](https://ask.csdn.net/questions/9420848)). Verify on a real iOS device. |
| Dynamic lensing, aberration, morphing | WebGL/WebGPU over a captured DOM texture (`drawElementImage()`, `layoutsubtree`) | Flagged in Chrome Canary / Brave Chromium 147+; no Firefox or WebKit implementation ([html-in-canvas.dev](https://html-in-canvas.dev/docs/browser-support/)). Not shippable. |

## Reference implementations worth copying

1. **`rdev/liquid-glass-react`** — most-copied React take: `<feImage>` + `<feDisplacementMap>` through `backdrop-filter: url()`, mouse-tracked elasticity, `displacementScale` 70, `saturation` 140. Its own warning matters most: *"Safari and Firefox only partially support the effect (displacement will not be visible)"* ([README](https://github.com/rdev/liquid-glass-react)).
2. **`@sohumsuthar/liquid-glass`** — best-documented attempt, calibrated against macOS 26 Control Center: four layers (effect / tint / shine / content), inset-shadow Fresnel bezel, reduce-transparency parity, `(hover: none)` mobile gates. Its **measured** caveats are the honest core: three-pass dispersion stalled Chrome's compositor at 5 elements (1 was fine); capping blur at 2px saved ~90% versus 28px; adaptive backdrop-luminance sensing "has no general web equivalent." It notes WebKit has `backdrop-filter: url()` patches in flight — matching the open PRs, so treat that as in development, not available ([README](https://github.com/sohumsuthar/liquid-glass)).
3. **`nikdelvin/liquid-glass`** — pure CSS/SVG: the displacement texture is base64-inlined because external `feImage` URLs "silently fail" from zero-size SVGs; explicit Safari fallback to plain glassmorphism. Its support table (Chrome 76+/Firefox 103+ "full") contradicts the WebKit bug and the other implementations — prefer the WebKit source ([README](https://github.com/nikdelvin/liquid-glass)).
4. **`html-in-canvas.dev`** — clearest statement of the trade: a CSS recipe (`blur(18px) saturate(1.6)`, 1px translucent border, inset top highlight, `0 8px 32px` shadow) that it correctly calls *"frosted glass, not liquid glass — there's no lensing"* ([article](https://html-in-canvas.dev/liquid-glass-effect/)).
5. **Magic UI `progressive-blur`** — shipped registry component using gradient-masked blur for scroll edges ([registry entry](https://registry.directory/magicuidesign/magicui/progressive-blur)); source not readable, so the implementation is unverified.

## Performance and accessibility constraints

- **Cost model.** A WebKit commit titled *"Backdrop-filter forces compositing on the root element and uses extra memory"* states the spec "creates a separate compositing layer, separate from the base background color of the view" ([WebKit 7ffc7f9](https://github.com/WebKit/WebKit/commit/7ffc7f9fd02ae05936e368c7a7c2d6774ff1f6a9)). A fixed full-width bar pays that for its whole area whenever content moves beneath it.
- **Radius dominates, animation is worst.** See Summary; both are measured, not inferred.
- **Vendor guidance is thin.** No Apple, Chrome, or MDN page publishes a numeric `backdrop-filter` budget; SEO-tier performance blog posts are not relied on here.
- **Contrast.** WCAG 2.2 AA needs 4.5:1 for body text, 3:1 for large text (≈18.5px regular / 24px) ([1.4.3](https://www.w3.org/WAI/WCAG22/Understanding/contrast-minimum.html)) and 3:1 for UI components and focus indicators ([1.4.11](https://www.w3.org/WAI/WCAG22/Understanding/non-text-contrast.html)). The documented failures are **F83** (text over a background image) and **F24** (text colour with no determinable background colour) — precisely the situation of a translucent bar over scrolling product cards. What holds: an opacity step once content is underneath — Apple's scroll edge effect, which "obscures content that scrolls beneath them" ([Adopting Liquid Glass](https://developer.apple.com/documentation/technologyoverviews/adopting-liquid-glass)) — plus a fully opaque variant behind `prefers-contrast: more` and the no-`backdrop-filter` fallback, which is what you audit against.

## Recommended approach for a warm-black/gold RTL storefront

Ship six techniques. All are widely supported; none need Chromium-only features.

1. **Restrained, warm backdrop filter.** `backdrop-filter: blur(24px) saturate(140%) brightness(0.86)`. Drop 34px → ~24px to cut the sampled area, drop saturation from 190% (it amplifies whatever cold colour sits behind), and put `brightness()` *below* 1 because `regular` "adjusts the luminosity" rather than lifting it. Warmth comes from the tint, never the filter.
2. **Warm-black tinted scrim with a gradient mask.** `background: linear-gradient(to bottom, rgb(20 16 12 / .72), rgb(12 9 7 / .86))` plus `mask-image: linear-gradient(to bottom, transparent, #000 14px)`, so content dissolves rather than meeting a hard edge — Apple's scroll edge effect done with `mask-image` instead of a second blurred surface. Cheapest stand-in for lensing, and it kills the generic-glassmorphism rectangle.
3. **Two-hairline specular rim in brand gold.** `box-shadow: inset 0 1px 0 rgb(212 175 106 / .16), inset 0 -1px 0 rgb(212 175 106 / .07)` and `border-top: 1px solid rgb(212 175 106 / .12)`. Keep the top hairline brighter than the bottom: that asymmetry reads as a convex slab. Replace the large drop shadow with `0 -8px 24px rgb(0 0 0 / .35)` — a big diffuse shadow is the second-strongest tell of generic glassmorphism.
4. **Concentric geometry.** `border-radius: calc(var(--nav-surface-radius) - var(--nav-inset))`, `999px` for pill items, named radius/padding tokens so a later `corner-shape: squircle` is one line.
5. **Compositor-only touch and state feedback.** `transition: transform 200ms, opacity 200ms, box-shadow 200ms`, `:active { transform: scale(.96); opacity: .92 }`, and an `::after` sheen driven only by `opacity`/`transform`. No `pointermove` handlers writing paint properties. This is Apple's `Glass.interactive(_:)` reaction to touch and pointer ([Applying Liquid Glass to custom views](https://developer.apple.com/documentation/swiftui/applying-liquid-glass-to-custom-views)) without the gel/morph animation, which a web bar cannot do safely anyway.
6. **A four-way accessibility net.** `@supports not (backdrop-filter: blur(1px))` → opaque `#0E0A08`; `prefers-contrast: more` → that surface plus a 1px gold border; `prefers-reduced-motion: reduce` → `transition-duration: 0.01ms`; `prefers-reduced-transparency: reduce` → opaque surface (a bonus, since it is Chromium-only). Add `color-scheme: light dark` with `light-dark()` tints if a light theme ships.

**Skip, and why:**

- **SVG `feDisplacementMap` refraction** — Chromium-only, silently ignored in Safari, and WebKit's own test case currently crash-loops the GPU process: a regression on most mobile traffic for an effect users cannot name.
- **Chromatic aberration** — three extra filter passes, measured to stall Chrome's compositor at five concurrent elements, and a blue/red fringe is the wrong colour story for warm gold.
- **JS pointer-tracking material** (`liquid-glass-react`-style) — per-frame DOM writes, and its README concedes no displacement on Safari/Firefox.
- **A second stacked `backdrop-filter` for a soft top fade** — doubles composite cost for no gain over one `mask-image`.
- **`@supports (backdrop-filter: …)` as the only gate, and animating the blur radius** — the first is unreliable in Safari (secondary, verify on device); the second recompiles a shader per radius in Chromium.

Not verified here: the Safari/WebKit release that will ship `backdrop-filter: url()` (patches open, not landed), whether the GPU-process crash affects released Safari or only trunk, the Safari `@supports` claim, Magic UI's source, and any vendor numeric frame budget for `backdrop-filter`.

## Sources

**Primary — Apple**

- [HIG: Materials](https://developer.apple.com/design/human-interface-guidelines/materials) · [HIG: Tab bars](https://developer.apple.com/design/human-interface-guidelines/tab-bars)
- [Adopting Liquid Glass](https://developer.apple.com/documentation/technologyoverviews/adopting-liquid-glass) · [WWDC25 219](https://developer.apple.com/videos/play/wwdc2025/219/) · [WWDC25 356](https://developer.apple.com/videos/play/wwdc2025/356/)
- [Applying Liquid Glass to custom views](https://developer.apple.com/documentation/swiftui/applying-liquid-glass-to-custom-views) · [`scrollEdgeEffectStyle(_:for:)`](https://developer.apple.com/documentation/swiftui/view/scrolledgeeffectstyle(_:for:))

**Primary — web platform, engines, standards**

- [MDN: `backdrop-filter`](https://developer.mozilla.org/en-US/docs/Web/CSS/backdrop-filter) · [`mask-image`](https://developer.mozilla.org/en-US/docs/Web/CSS/mask-image) · [`feDisplacementMap`](https://developer.mozilla.org/en-US/docs/Web/SVG/Reference/Element/feDisplacementMap) · [`light-dark()`](https://developer.mozilla.org/en-US/docs/Web/CSS/Reference/Values/color_value/light-dark) · [`prefers-reduced-transparency`](https://developer.mozilla.org/en-US/docs/Web/CSS/Reference/At-rules/@media/prefers-reduced-transparency) · [`prefers-contrast`](https://developer.mozilla.org/en-US/docs/Web/CSS/Reference/At-rules/@media/prefers-contrast)
- [Web Features Explorer: `prefers-reduced-transparency`](https://web-platform-dx.github.io/web-features-explorer/features/prefers-reduced-transparency/)
- [WebKit bug 245510](https://bugs.webkit.org/show_bug.cgi?id=245510) · [WebKit bug 316769](https://bugs.webkit.org/show_bug.cgi?id=316769) · [WebKit commit 7ffc7f9](https://github.com/WebKit/WebKit/commit/7ffc7f9fd02ae05936e368c7a7c2d6774ff1f6a9)
- [csswg-drafts #13997](https://lists.w3.org/Archives/Public/public-css-archive/2026Jun/0011.html) · [csswg-drafts #13288](https://lists.w3.org/Archives/Public/public-css-archive/2026Jan/0015.html)
- [WCAG 2.2 SC 1.4.3](https://www.w3.org/WAI/WCAG22/Understanding/contrast-minimum.html) · [SC 1.4.11](https://www.w3.org/WAI/WCAG22/Understanding/non-text-contrast.html)
- [WICG HTML-in-Canvas support](https://html-in-canvas.dev/docs/browser-support/)

**Secondary — implementations and libraries**

- [html-in-canvas.dev: Liquid Glass in CSS and WebGL](https://html-in-canvas.dev/liquid-glass-effect/) · [rdev/liquid-glass-react](https://github.com/rdev/liquid-glass-react) (+ [npm metadata](https://registry.npmjs.org/liquid-glass-react))
- [sohumsuthar/liquid-glass](https://github.com/sohumsuthar/liquid-glass) · [nikdelvin/liquid-glass](https://github.com/nikdelvin/liquid-glass) · [eirasmx/webglass](https://github.com/eirasmx/webglass) · [Magic UI `progressive-blur`](https://registry.directory/magicuidesign/magicui/progressive-blur)
- [CSDN Q&A on Safari `@supports`](https://ask.csdn.net/questions/9420848) (unverified, low-trust)
