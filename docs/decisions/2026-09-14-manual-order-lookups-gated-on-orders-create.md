# The manual-order drawer's lookups are gated on `orders.create`, not on the catalogue

Date: 2026-09-14
Status: follows necessarily from the owner decision of 2026-09-13 that support staff may create
manual orders, gifts included. Recorded because it widens what support can read, and the widening
is not visible from the permission list.
Related: `docs/decisions/2026-09-12-order-tracking-in-store-design.md`.

## Decision

The three read endpoints the drawer calls — `GET /admin/api/orders/new/{options,customers,price}` —
are authorised with `orders.create`. They are **not** gated on `customers.view` or `catalog.view`,
which `AdminAccess::STAFF` does not grant.

## Why this is surprising

A support agent holds exactly six abilities, and neither `customers.view` nor `catalog.view` is
among them. So a reader of `AdminPermission` would reasonably conclude support cannot search
customers or read catalogue prices. Through this drawer, they can.

## Why it had to be this way

The owner granted support `orders.create` on 2026-09-13, against a recommendation of admin-only.
An order needs a customer, and a customer must already exist — a new one is created on the
customers screen. A support agent who can create an order but cannot find the person to create it
for holds a permission that does nothing. The same argument applies to the price: the owner asked
for the figure to be suggested from the catalogue and overridable, which cannot happen behind a
catalogue permission support does not hold.

The alternative — granting support `customers.view` and `catalog.view` outright — was rejected
because it is far wider. `customers.view` opens the customers screen with money spent, loyalty
tier, status history and last-order dates; `catalog.view` opens the catalogue management screens.

## What limits it

- `SearchManualOrderCustomers` returns six fields: handle, name, email, phone, order count, active.
  No money, no tier, no history. It caps at eight rows and refuses a query under two characters,
  so it cannot be used to page the customer table.
- It returns customers only. `CreateManualOrder:70` refuses an order written against a staff,
  admin or service account, so the picker never offers one.
- `ManualOrderOptions` returns names, platforms and prices — what a picker needs — and nothing the
  catalogue screens exist to manage.
- `ManualOrderPriceController` reserves nothing and claims no price version. It is a suggestion;
  the figure that reaches the order is whatever the staff member leaves in the field, and the
  difference is recorded on the order.
- All three sit behind `throttle:staff-reads`, 120 a minute keyed on the staff member.
- Every creation still writes a staff audit row naming who did it. That, not the permission, is
  the control the owner chose.

## Two things the approved canvas got wrong, corrected here

The canvas (published 2026-09-13) offered a second delivery choice, **"Send it to the supplier"**,
described as "runs the same fulfillment path as a paid order". No such path exists: the store has
never written to a supplier, and dispatch is slices F1–F3. Shipping that copy would have promised a
capability the button does not have. The choice is now **"Not placed yet"**, which is what the
backend actually does — the order is created with no job, and someone places it and adds the
reference afterwards.

The canvas also drew an editable **Amount** on the payment group. `CreateManualOrder` refuses a
payment whose amount does not equal the items, so a second editable number could only ever
disagree with the first. The amount is now derived from the items and displayed, not typed.
