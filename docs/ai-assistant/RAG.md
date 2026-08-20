# Retrieval and grounding

**Lifecycle:** Planned
**Verified:** 2026-08-20

No retrieval schema, embedding model, vector store, indexer, or customer-facing
retrieval response is selected or implemented.

## Required principles

- Retrieve only from sources explicitly approved for customer support.
- Preserve source ownership, locale, visibility, and freshness metadata through
  ingestion and retrieval.
- Define who updates each source and how stale or withdrawn content is removed
  before relying on it.
- Customer answers based on retrieved material must cite the supporting source
  in a form the customer or support operator can inspect.
- Missing, conflicting, stale, or unauthorized evidence must produce a bounded
  fallback or human handoff, not a confident answer.

## Open questions

- Which Arab UT policies, catalog material, and support documents are approved
  sources?
- What freshness requirement applies to each source class?
- How are Arabic/English equivalents, conflicts, and source withdrawal handled?

## Entry criteria

Start retrieval implementation only after source owners, visibility rules,
freshness policy, citation UX, deletion process, bilingual evaluation set, and
acceptable failure behavior are approved. Storage technology is a later design
decision.
