# CKM Quiz Pro 0.3.9 — «Битва знатоков»

Standalone adds the existing shared `chgk` runtime next to `classic_quiz` without modifying Quiz Core 87.2.

## User flow

1. Organizer selects «Битва знатоков» in the game constructor.
2. Questions are text questions with a reference answer and optional accepted variants.
3. A room is created for 2–10 teams.
4. Opening a team link joins and marks that team ready. No captain role is required (`team_device`).
5. When all active teams are ready, the shared CHGK AI-host lobby opens the first question.
6. Each team can lock exactly one final text answer. The first final submission is immutable.
7. The question closes when all teams answer or when the server deadline expires.
8. Until CKM Cloud AI is connected, `standalone-chgk.php` performs a strict normalized reference/accepted-variant match and records the verdict through the shared ScoreEvent ledger.
9. The standalone autopilot opens the next question or finishes the game with shared-place tie handling.

## Deliberate DEV limitation

There is no semantic AI arbitration in the client ZIP. MIND/SMKM prompts, methodology logic and provider credentials remain server-side concerns for CKM Cloud. The local judge is only a deterministic reference matcher.

## Compatibility

- No database schema change.
- No modification of `core-source` or `core-manifest.json`.
- CKM Game Package v1 can run `classic_quiz` and `chgk` locally.
- Existing Classic Quiz behavior remains intact.
