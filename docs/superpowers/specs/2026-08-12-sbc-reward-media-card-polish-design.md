# SBC Reward Media and Card Polish Design

**Status:** Approved by Mohamed on 2026-08-12

**Complexity:** Medium

## Outcome

Every SBC product uses the reward player art when EasySBC identifies a player reward. Challenges whose reward is not a player continue to use the existing challenge image. The storefront listing becomes materially closer to the owned WordPress reference: compact equal cards, image-first hierarchy, no description block inflating card height, restrained premium hover motion, and full reduced-motion support.

## Media decision

- Inspect `rewards` for a reward whose type is `player` and whose `rewardImgURL` is an approved `https://assets.easysbc.io/` asset.
- Use that player image as the single catalog media item.
- When no valid player reward exists, use the challenge `imageURL` exactly as today.
- Never substitute pack art or Player Pick art for a non-player challenge.
- Reject malformed declared media at the workflow validation boundary; do not publish an unvalidated URL.
- Laravel continues mirroring approved media and retaining the last-good local asset on refresh failure.

## Card decision

- Remove the long product description from SBC listing cards; keep it on the product detail page.
- Keep equal geometry, a contained reward image stage, a two-line title budget, compact platform/price controls, and one Add to Cart link.
- Replace the fixed 31rem minimum with content-driven compact sizing while preserving equal heights within the grid.
- Hover/focus uses a small lift, restrained gold border/glow, and a gentle image drift/scale. It must not crop player art or trigger layout shift.
- Pointer-hover effects apply only where hover is available. Keyboard focus receives the same visual hierarchy without relying on motion.
- Reduced motion removes translations/scaling and leaves the static focus/border treatment.
- Arabic and English must remain usable at 320, 390, 768, 1075, and 1440 CSS pixels without horizontal overflow.

## Verification

- Workflow tests prove player reward selection, non-player challenge fallback, invalid player-image rejection, and snapshot validation.
- React tests prove SBC descriptions are absent from listing cards but remain available on the product page.
- CSS/browser checks prove compact equal cards, contained images, hover/focus behavior, reduced motion, and responsive overflow safety.

