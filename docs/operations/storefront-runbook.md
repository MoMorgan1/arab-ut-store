# Storefront operations runbook

## One-time Salla review archive

The storefront reads reviews only from the local `reviews` table. The one-time archive command consumes the already configured n8n review source and projects only the public rating, public text, public display name, public city, timestamp, and stable source identity. It never persists the Salla customer object, phone, email, avatar, raw order identity, street address, or raw response. The public storefront keeps the complete archive internally but renders only published four- and five-star reviews; its displayed average and count use the same public subset.

Salla's current feedback contract documents the imported review fields and the customer's public display `name` and `city` under [List Feedbacks](https://docs.salla.dev/5394279e0). The archive reads only those two allowlisted customer values and deliberately ignores the remaining nested customer object.

Run the production GitHub workflow `archive-salla-reviews` in this order:

1. Choose `dry-run`. Record only `count` and the 1–5 rating distribution. No rows are written.
2. Confirm the count is non-zero and every rating bucket is represented honestly when present in the source.
3. Choose `apply`. The complete validated set is written transactionally under source `salla-import`.
4. Run `apply` again. The count must be identical and no duplicate rows may be created.
5. Verify `/`, `/en`, `/reviews`, and `/en/reviews`; imported reviews must not display a verified-order badge.
6. Search the rendered Inertia payload and application logs for known test-only private sentinels. Do not print or export real customer values during verification.
7. Remove the recurring `reviews:refresh` schedule after the archive has been verified. Future reviews belong to the local post-order review system, not this historical importer.

The equivalent server commands are:

```bash
php artisan reviews:import-salla-archive --from-config
php artisan reviews:import-salla-archive --from-config --apply
```

The command output contains counts only. A malformed, unavailable, empty, or private-contact-only source fails without changing the last-good archive.
