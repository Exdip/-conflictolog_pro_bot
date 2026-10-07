# NEG-DIFFICULTY verification

Build: `0.3.23.303-dev.335-NEG-DIFFICULTY`
NEG DB schema: `1.8.0`
NEG content: `1.4.0`

## Contract

Difficulty is a session-pinned strategy policy, not a scoring multiplier and not a rule override.
The four levels are `soft`, `medium`, `hard`, `expert`. Hard and expert must remain professional and
may not reveal hidden limits or violate server-side hard constraints. PHP remains authoritative for
items, facts, agreement state and evaluation.

## Storage

- `ckm_neg_sessions.difficulty` defaults to `medium` for old rows.
- `ckm_neg_assignments.difficulty` defaults to `medium` for old rows.
- No new NEG tables and no content migration are required.
