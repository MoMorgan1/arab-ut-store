# Motion Migration Inventory

Inventory of all `var(--store-ease-out)` call sites across the codebase.
This tracks call sites to be migrated to the new motion scale tokens (`--motion-ease` or utility classes).

- Total call sites: 114
- Areas: store (74), auth (7), account (3), manual (30), chat (0)

## Store (74 call sites)

Storefront shell, hero, navigation, cart, catalog, cards, and reviews.

- `resources/css/app.css:1312` — transition: background-color
- `resources/css/app.css:1419` — transition: border-color
- `resources/css/app.css:1420` — transition: background
- `resources/css/app.css:1421` — transition: transform
- `resources/css/app.css:1496` — transition: color
- `resources/css/app.css:1508` — transition: transform
- `resources/css/app.css:1568` — transition: filter
- `resources/css/app.css:1569` — transition: box-shadow
- `resources/css/app.css:1628` — animation: store-hero-sweep
- `resources/css/app.css:1644` — animation: store-hero-glow
- `resources/css/app.css:1803` — transition: transform
- `resources/css/app.css:1806` — transition: box-shadow
- `resources/css/app.css:1857` — animation: store-stat-reveal
- `resources/css/app.css:1949` — animation: store-section-reveal
- `resources/css/app.css:2141` — animation: coins-step-in
- `resources/css/app.css:2273` — transition: transform
- `resources/css/app.css:2648` — transition: box-shadow
- `resources/css/app.css:2649` — transition: transform
- `resources/css/app.css:2682` — transition: box-shadow
- `resources/css/app.css:2683` — transition: transform
- `resources/css/app.css:3592` — animation: store-cart-sheet-ring
- `resources/css/app.css:3710` — animation: store-cart-sheet-art
- `resources/css/app.css:3840` — animation: store-cart-icon-nudge
- `resources/css/app.css:3844` — animation: store-cart-badge-bump
- `resources/css/app.css:3854` — animation: store-cart-badge-ring
- `resources/css/app.css:4188` — transition: border-color
- `resources/css/app.css:4189` — transition: background-color
- `resources/css/app.css:4190` — transition: transform
- `resources/css/app.css:4393` — transition: opacity
- `resources/css/app.css:4395` — transition: max-height
- `resources/css/app.css:4820` — transition: transform
- `resources/css/app.css:4862` — animation: store-cart-credentials-reveal
- `resources/css/app.css:5901` — transition: transform
- `resources/css/app.css:5902` — transition: border-color
- `resources/css/app.css:5903` — transition: background
- `resources/css/app.css:5904` — transition: color
- `resources/css/app.css:5946` — transition: transform
- `resources/css/app.css:5947` — transition: color
- `resources/css/app.css:5977` — transition: transform
- `resources/css/app.css:5978` — transition: border-color
- `resources/css/app.css:5979` — transition: background
- `resources/css/app.css:5980` — transition: color
- `resources/css/app.css:6055` — transition: all
- `resources/css/app.css:6078` — transition: color
- `resources/css/app.css:6160` — transition: color
- `resources/css/app.css:6378` — transition: background-color
- `resources/css/app.css:6379` — transition: transform
- `resources/css/app.css:10533` — animation: store-catalog-added
- `resources/css/app.css:10701` — transition: border-color
- `resources/css/app.css:10702` — transition: transform
- `resources/css/app.css:10703` — transition: box-shadow
- `resources/css/app.css:10704` — transition: filter
- `resources/css/app.css:10705` — animation: store-sbc-card-reveal
- `resources/css/app.css:10778` — transition: opacity
- `resources/css/app.css:10779` — transition: transform
- `resources/css/app.css:10780` — transition: filter
- `resources/css/app.css:10918` — transition: transform
- `resources/css/app.css:10919` — transition: filter
- `resources/css/app.css:10961` — transition: transform
- `resources/css/app.css:11970` — transition: transform
- `resources/css/app.css:12177` — transition: border-color
- `resources/css/app.css:12178` — transition: background-color
- `resources/css/app.css:12206` — transition: width
- `resources/css/app.css:12207` — transition: background-color
- `resources/css/app.css:12233` — transition: transform
- `resources/css/app.css:12236` — animation: store-review-reveal
- `resources/css/app.css:12384` — transition: transform
- `resources/css/app.css:12422` — transition: background-color
- `resources/css/app.css:12423` — transition: border-color
- `resources/css/app.css:12496` — transition: background-color
- `resources/css/app.css:12497` — transition: border-color
- `resources/css/app.css:12498` — transition: color
- `resources/css/app.css:15522` — transition: opacity
- `resources/css/app.css:15523` — transition: transform

## Auth (7 call sites)

Authentication shell, tabs, OAuth actions, and form submit buttons.

- `resources/css/app.css:812` — transition: all
- `resources/css/app.css:993` — transition: border-color
- `resources/css/app.css:994` — transition: background-color
- `resources/css/app.css:995` — transition: box-shadow
- `resources/css/app.css:1062` — transition: background-color
- `resources/css/app.css:1063` — transition: box-shadow
- `resources/css/app.css:1064` — transition: transform

## Account (3 call sites)

Customer account dashboard, fulfillment toggle, and loyalty progress.

- `resources/css/app.css:7172` — transition: transform
- `resources/css/app.css:13577` — transition: transform
- `resources/css/app.css:13794` — transition: inline-size

## Manual (30 call sites)

Manual service configuration, tabs, segmented controls, sliders, and pricing cards.

- `resources/css/app.css:8309` — transition: border-color
- `resources/css/app.css:8310` — transition: background-color
- `resources/css/app.css:8311` — transition: color
- `resources/css/app.css:8379` — animation: manual-section-in
- `resources/css/app.css:8512` — transition: border-color
- `resources/css/app.css:8513` — transition: background-color
- `resources/css/app.css:8514` — transition: color
- `resources/css/app.css:8515` — transition: box-shadow
- `resources/css/app.css:8556` — animation: manual-fade-slide
- `resources/css/app.css:8715` — transition: box-shadow
- `resources/css/app.css:8716` — transition: transform
- `resources/css/app.css:8729` — transition: box-shadow
- `resources/css/app.css:8730` — transition: transform
- `resources/css/app.css:8767` — transition: color
- `resources/css/app.css:8768` — transition: background-color
- `resources/css/app.css:8788` — transition: background-color
- `resources/css/app.css:8789` — transition: transform
- `resources/css/app.css:8790` — transition: box-shadow
- `resources/css/app.css:8882` — transition: border-color
- `resources/css/app.css:8883` — transition: background-color
- `resources/css/app.css:9040` — transition: border-color
- `resources/css/app.css:9041` — transition: background-color
- `resources/css/app.css:9042` — transition: color
- `resources/css/app.css:9071` — animation: manual-fade-slide
- `resources/css/app.css:9162` — transition: color
- `resources/css/app.css:9441` — animation: manual-price-pop
- `resources/css/app.css:9574` — transition: transform
- `resources/css/app.css:9735` — transition: border-color
- `resources/css/app.css:9736` — transition: background-color
- `resources/css/app.css:9737` — transition: transform

## Chat (0 call sites)

Chat widget surfaces and controls (currently uses `--chat-ease-spring`).

*No call sites found in this area.*

## Exit Criterion

`--store-ease-out` is deleted in the PR that empties this list.
