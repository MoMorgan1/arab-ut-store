# Chat conversation title

**Date:** 2026-09-15. **Decided by:** Mohamed.

## Decision

Every AI conversation gets a title after the assistant's first reply. The title
is produced by a second, short model call rather than parsed out of the reply
itself, and it is shown in two places: the chat header while the thread is open
and the "continue your conversation" / previous-conversations rows on the
widget's home view. The admin conversations list and the support-ticket
subject keep their current behaviour.

## Why a separate call

The first reply streams to the customer word by word. A title carried inside
that reply would have to be buffered out of the stream (a first-line header
delays the first visible words; a trailing marker needs look-ahead buffering)
and would put a formatting rule into the customer-facing prompt, which is
under a 16-case evaluation gate. A second call needs no prompt change to
`support-v9`, cannot alter the reply, and costs a few hundred tokens per new
conversation.

## Shape

- `GenerateConversationSubject` runs inside the same request after the
  `response.completed` frame has been flushed. It never fails the turn.
- Prompt `subject-v1`: three to six words, the customer's language (mixed
  becomes Arabic), no quotes, no punctuation, no personal data.
- `SubjectText::clean` keeps one line, strips quotes and a label, and rejects
  anything over 80 characters; a rejected title leaves the column empty and the
  widget falls back to the first customer message.
- The widget learns the title from a new `conversation.subject` stream event.
- `AI_ASSISTANT_SUBJECT_ENABLED=false` turns the call off.
