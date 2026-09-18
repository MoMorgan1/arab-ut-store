# customer-notify v1

The sending half of `Customer Notifier v2`, and nothing else.

The store decides whether a message is owed, which message it is, and what it
says. This workflow verifies the request, refuses a repeat, and calls Whapi.
Everything the old workflow did for itself — substring-matching Salla status
names, `$getWorkflowStaticData` de-duplication that did not survive a restart,
a Supabase log — is gone: the store's canonical status, `notification_deliveries`
and a durable idempotency key replace all three.

The contract this implements is [`docs/api/n8n-customer-notification-v1.md`](../../../docs/api/n8n-customer-notification-v1.md).
Read it before changing anything here; it is the agreement, this is one side of it.

## The path

```
Webhook → Config → Verify Request → Already Sent? → Send or Skip
                                                      ├─ true  → Respond Already Sent
                                                      └─ false → Send WhatsApp → Record Result → Respond
```

- **Verify Request** checks the HMAC over the raw bytes, the five-minute
  window, the event type and schema, and that the request carries a sendable
  number, a non-empty body and an idempotency key. Every failure throws.
- **Already Sent?** looks the key up in this instance's memory.
- **Send WhatsApp** posts to `{WHAPI_BASE_URL}/messages/text` and never errors.
- **Record Result** reads the status code, remembers the key only on success,
  and answers `acknowledged` true or false.

## Failure model

Two kinds of failure, deliberately routed apart.

**An incident throws.** A rejected signature, a malformed request, a Config
node nobody filled in: the run fails, the Error Workflow sends the Telegram
alert, and the store — seeing no acknowledgement — retries on its own backoff.

**A customer who cannot be reached does not.** A dead number, a Whapi outage, a
timeout: `Send WhatsApp` runs with `onError: continueRegularOutput`, and
`Record Result` answers `acknowledged: false`. The store's delivery row goes
back to `queued` on a 1, 2, 4 … up to 60-minute backoff, and after ten attempts
it is retired and surfaces on the admin queue-health panel. Nobody is paged for
a wrong phone number, and nobody is paged ten times for the same one.

## De-duplication, and why there are two of them

The store's is the real one: one delivery row per transition, a unique index on
its idempotency key, and the row marked `sent` before the event is marked
processed.

The memory in `Already Sent?` covers the one case the store cannot see — it
sent, Whapi accepted, and the answer never arrived, so the store retries a
message that already went out. That retry carries the same key and is answered
without a second Whapi call.

It is bounded at 2,000 keys, newest first, and it does not survive an n8n
restart. That is acceptable *here* and was not acceptable in `Customer Notifier
v2`, because there it was the only guard; here it is the second one.

## After importing

1. Import `workflow.json` and `error-workflow.json`.
2. Paste the four values into the **Config** node:

   | Field | Value |
   | --- | --- |
   | `N8N_CUSTOMER_NOTIFY_KEY` | the same string as `N8N_CUSTOMER_NOTIFY_KEY` on the store |
   | `N8N_CUSTOMER_NOTIFY_SECRET` | the same string as `N8N_CUSTOMER_NOTIFY_SECRET` on the store, 32+ characters |
   | `WHAPI_TOKEN` | the Whapi channel token |
   | `WHAPI_BASE_URL` | ships filled in as `https://gate.whapi.cloud` |

   A field still reading `CONFIGURE_…` makes `Verify Request` refuse the run.
3. Attach the Telegram credential in the error workflow and replace
   `CONFIGURE_OPS_TELEGRAM_CHAT_ID` with the ops chat.
4. Set **Workflow Settings → Error Workflow** to *customer-notify - Failure
   Alert*. The export cannot carry this: it is an id belonging to the instance.
5. Confirm **Workflow Settings → save execution data** is off in every mode.
   The request carries the customer's name, phone number and whole message; the
   export says `none`, but the settings screen can override what an export says,
   so look.
6. Activate, then set the three `N8N_CUSTOMER_NOTIFY_*` values on the store.
   Until the store has them its publisher claims nothing, so the order here is
   free: activate first and nothing is missed.

## Editing

`workflow.json` and `error-workflow.json` are **built**, never hand-edited. The
node bodies live in `nodes/*.js`; `npm run build` assembles them and
`npm test` fails if the JSON is stale.

```bash
npm run build   # regenerate both JSON files
npm test        # staleness check, then the node tests
```
