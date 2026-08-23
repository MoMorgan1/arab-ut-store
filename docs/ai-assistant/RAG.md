# Retrieval and grounding

**Lifecycle:** Implemented (lexical selection over a curated corpus)
**Verified:** 2026-08-23

The assistant is grounded in a curated, staff-authored corpus rather than an
indexed crawl. `resources/ai-assistant/knowledge/arab-ut.json` holds 45 bilingual
topics (Arabic authoritative) adapted from the legacy storefront's knowledge base
with the owner's decisions of 2026-08-23 applied. `SelectSupportKnowledge` picks
the few topics a customer's message is about and `BuildAgentModelRequest` injects
them into a `<store_knowledge>` block; `support-v3` instructs the model to answer
from that block, quote its facts exactly, and say plainly when the block does not
cover the question.

Selection is lexical, not vector-based: the corpus is tens of short topics, so an
embedding provider would add a dependency, a cost line, and a staleness story for
a corpus that fits in a prompt. Arabic is normalized (diacritics, alef/yaa/ta
marbuta variants, the glued definite article) because customers type the same
word several ways and mix Arabic and English in one sentence. A topic qualifies
only on a keyword or title hit — body-word overlap alone is noise — and
multi-word keywords must appear as a whole phrase, so a generic word such as
"كيف" cannot pull in unrelated topics.

`ai-assistant.knowledge_max_topics` bounds how many topics reach the prompt; 0
disables grounding without editing the prompt. No embedding model, vector store,
or indexer is selected or implemented, and prices, stock, and order state are
never answered from the corpus.

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

## Owner decisions (2026-08-23)

- Approved source: the curated knowledge file only. Catalog, pricing, and order
  state stay out of it and remain live-data concerns.
- Arabic is authoritative. Where the Arabic and English sides of a topic
  disagree, the model follows the Arabic and the discrepancy is fixed in the
  content, not in code.
- The retired `arab` coupon and the ageing order/customer counts were dropped
  rather than published; installments are described as a manual arrangement and
  player sniping as not yet orderable.
- Support phone numbers are not published through the assistant: escalation
  stays in the chat.

## Open questions

- Who owns each topic's freshness, and how is a withdrawn topic reviewed?
- When the corpus outgrows the prompt budget, does selection move to an index?

## Entry criteria

Start retrieval implementation only after source owners, visibility rules,
freshness policy, citation UX, deletion process, bilingual evaluation set, and
acceptable failure behavior are approved. Storage technology is a later design
decision.
