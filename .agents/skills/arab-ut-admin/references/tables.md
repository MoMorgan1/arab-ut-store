# Tables

## Server contract

- One Request validator and Query object per table.
- Explicit allowlists for search, filters, sort keys, directions, page, and page
  size. Unknown keys fail validation.
- Database filtering/sorting/pagination covers the full result set.
- URL query state is durable; preserve it across detail/back navigation.
- Select only presented columns and eager-load only required safe relationships.
- Add indexes only for verified query shapes and test the migration lifecycle.

## React contract

- TanStack Table may control columns, visibility, selection, and sort/filter UI.
- Use manual server-side pagination/filtering/sorting; never apply those only to
  the loaded page.
- Stable public IDs are row keys.
- Selection is current-page scoped. No bulk financial/destructive action without
  its own approved transaction/idempotency design.
- Column visibility preferences may be local UI state; never store sensitive
  data or permissions in browser storage.

## Responsive/accessibility

- Desktop uses semantic table markup when the relationship is tabular.
- Mobile uses compact record summaries or an explicitly labeled horizontal
  scroll region; do not hide critical fields/actions.
- Sort controls expose direction in accessible names/state.
- Filters have labels, reset behavior, empty results, loading, and failure UI.
- Pagination announces current/total pages and provides 44px controls.
