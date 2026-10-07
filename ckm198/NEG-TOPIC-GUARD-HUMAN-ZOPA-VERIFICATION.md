# NEG-TOPIC-GUARD-HUMAN-ZOPA verification

Version: `0.3.23.341-dev.373-NEG-CUSTOM-SCENARIO-LAUNCH-FIX`

## Scope
- Human-language wording for the final agreement-space / alternative comparison.
- Server-side rejection of player messages that are unrelated to the active negotiation.
- Rejected messages are stopped before persistence, opponent generation and arbiter processing.
- Natural bargaining shorthand, greetings, contextual follow-ups and scenario paraphrases remain allowed.
- No DB/content migration.

## User-facing rejection
`Эта реплика не относится к текущим переговорам. Вернитесь к условиям сделки, интересам сторон или обсуждаемой ситуации.`

## Compatibility
Previously saved evaluation summaries containing the older methodological wording are humanized by the session UI at render time; no recalculation is required.
