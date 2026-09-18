# Switching automated fulfillment on

Two environment variables are the switch for the whole automated path, and a
third set is the switch for telling the customer about it. This is the order
they go on in, and how to tell that each one worked.

Nothing here is reversible by deploy: these are values on the server and in
n8n, not code. Taking them back out is also the rollback, and it is immediate.

## The gate, and why it was shut

`N8N_FULFILLMENT_KEY` and `N8N_FULFILLMENT_SECRET` were deliberately left unset.
While either is missing, `VerifyN8nFulfillmentSignature::handle()` answers 401
before the controller runs, so the placement endpoint is inert and no placement
can be recorded. That inertness is what made it safe to ship the endpoint ahead
of the things that observe what it records.

Its two preconditions:

- **D3** — the sweep (D3a), stall detection (D3b) and customer notification
  (D3c). All three are on `main`; D3c merged 2026-09-18 in
  [#213](https://github.com/MoMorgan1/arab-ut-store/pull/213).
- **B5's phase-progression half** — a later phase arriving on a finished job
  re-opens polling, so a challenge placed against a job whose coins phase
  completed is actually observed. Shipped 2026-09-14.

Both are met. The gate can open.

Set these two and the endpoint starts acknowledging placements immediately, so
do not set them while you still intend to test something else first.

## 1. The placement endpoint (the gate)

The key is an identity and may be anything readable; the secret must be at
least 32 characters and is compared against what n8n signs with. **Generate the
secret on the server** — it should not travel through a chat window, a ticket
or a password reset email on its way to being pasted.

On the Hostinger host, in the release directory:

```bash
php -r 'echo bin2hex(random_bytes(24)), PHP_EOL;'
```

Put that value, and a key of your choosing, into `.env`:

```
N8N_FULFILLMENT_KEY=fulfillment-publisher
N8N_FULFILLMENT_SECRET=<the 48 characters the command printed>
```

Then rebuild the config cache, or the running app keeps reading the old file:

```bash
php artisan config:cache
```

Paste the same two values into the **Config** node of `ship-coins` and of
`solve-challenge` in n8n — the fields are already named for them. The store
compares what n8n sends; a value that differs anywhere is a 401 the supplier
call has already been made behind.

**Check it.** Before: a request to the placement endpoint answers `401`. After:
the same unsigned request still answers `401` (the signature is checked too),
but a `ship-coins` run that places at a supplier now reports successfully
instead of failing at its last node. The honest test is one real placement,
watched end to end: `fulfillment_jobs` gains a `supplier_order_id`, and the
customer's order page shows the phase moving.

## 2. Customer notifications

Independent of the gate, and safe to do in either order. Three values, and the
store sends nothing at all while any of them is missing — no claim, no attempt,
no log line.

Import `automation/n8n/customer-notify-v1/workflow.json` first and follow that
package's README: it has its own four Config fields, its error workflow, and
the "save no execution data" check that matters because the request carries the
customer's name, number and whole message.

Then, in `.env`:

```
N8N_CUSTOMER_NOTIFY_URL=https://<your n8n host>/webhook/arabut-customer-notify-v1
N8N_CUSTOMER_NOTIFY_KEY=notify-publisher
N8N_CUSTOMER_NOTIFY_SECRET=<48 characters from the command above, a different one>
```

`N8N_CUSTOMER_NOTIFY_KEY` and `N8N_CUSTOMER_NOTIFY_SECRET` must match the
workflow's Config node exactly. The URL must be `https`; an `http` URL, a URL
with credentials in it, or a secret under 32 characters is treated as *not
configured* rather than as an error, so a typo here is silence, not an alarm.

```bash
php artisan config:cache
```

**Check it.** The publisher runs every minute. With the values set and a hold
queued, `notification_deliveries` moves a row from `queued` to `sent` within a
minute and the customer's phone rings. With them unset, the same row sits at
`queued` indefinitely and `php artisan orders:publish-customer-notifications`
prints "not configured; leaving the queue untouched".

A message that waited while its hold cleared is never sent: it is marked
`expired` and its event `processed`. That is the system working, not a failure.

## Rolling back

Remove the values from `.env` and run `php artisan config:cache`.

- Without the fulfillment pair, the placement endpoint answers 401 again. A
  `ship-coins` run that has already placed at a supplier will fail to report,
  and that placement exists at the supplier with nothing in the store pointing
  at it — so do this when nothing is in flight, or be ready to record the
  reference by hand. `docs/operations/fulfillment-recovery.md` is that
  procedure.
- Without the notify trio, the publisher goes quiet mid-queue. Queued rows
  wait; they are not lost, and they expire at send time if their hold cleared
  meanwhile.
