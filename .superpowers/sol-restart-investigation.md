# Task type

READ-ONLY INVESTIGATION. Do NOT modify any file. Rank root-cause hypotheses
with concrete minimal code fixes. Be concise and decisive.

# Objective

Production-only defect: on the live store (Hostinger, latest deploy), after an
AI reply completes, tapping the "محادثة جديدة" (new conversation) button does
nothing for the site owner. Locally the identical sequence PASSES in
Playwright (tests/Browser/agent-restart.spec.ts, chromium): button enables,
click succeeds, fresh Arabic seed appears. Find why production behaves
differently for a long-lived real user, and explain a persistent visual
artifact: a thin rounded strip peeking from behind the panel directly below
the header's new-chat button (see chat-header tooltip positioning).

Verified server facts (do not re-question them):
- agent_turns: {"completed":19}, zero nonterminal today.
- No laravel ERROR entries newer than 2026-08-17.
- Deployed CSS bundle contains the .chat-restart-tooltip rules.
- Streaming/dots work for the owner; only restart is dead.
- Owner tested repeatedly today (rate limits may have tripped at times).

# Allowed paths

Read-only analysis of:
- resources/js/hooks/use-chat.ts (canRestart ~1217-1228; restartChat
  ~1230-1320; triggerAgentTurn ~428-660 incl. finally; startPollingTurn
  ~280-335; sendMessage/processQueue ~895-1065; clearAgentState ~220-235;
  adoptConversation ~709-730)
- resources/js/components/chat/chat-header.tsx (tooltip block)
- resources/js/components/chat/chat-widget.tsx (button wiring/disabled)
- resources/css/app.css (.chat-restart-tooltip rules ~11046+)
- app/Http/Controllers/Chat/ChatConversationController.php (restart ~111+)
- app/Actions/Chat/RestartChatConversation.php
- app/Providers/AppServiceProvider.php (chat-conversations / agent-turns
  rate limiters)

# Non-goals

No edits, no commands besides reading files, no backend redesign proposals,
no new features.

# Acceptance criteria

Return exactly:
1. Ranked root-cause hypotheses (max 4) for the dead button in production,
   each with: mechanism -> which canRestart input stays true/false -> the
   precise line(s) responsible -> one-paragraph minimal fix.
2. Root cause for the strip artifact below the button, with mechanism, and
   its minimal fix (CSS or markup).
3. Any place where a swallowed promise rejection or generation-guard silent
   return could strand UI state, even if not the current bug.

# Required checks

Pure reasoning over the listed files; no test execution required. State
confidence per hypothesis (high/medium/low).
