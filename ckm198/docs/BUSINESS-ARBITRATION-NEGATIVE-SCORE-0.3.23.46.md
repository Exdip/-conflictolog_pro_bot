# CKM Quiz Pro 0.3.23.46 — Business arbitration + negative score

This build completes the Games Hub runtime path in the existing Quiz Pro engine.

## Changes

- Games Hub arbitration aliases are normalized: `ai` -> AI, `host` -> human, `ai_or_host` -> hybrid.
- The normalized judge mode is frozen into `judge_mode_snapshot` when a room is created.
- `Управленческая игра "Ваш выбор"` and `Переговорный поединок` respect the room judge mode:
  - `human`: answers stay pending for the host;
  - `hybrid`: AI is attempted first, and on provider failure the answer stays pending for human fallback;
  - `ai`: AI is attempted first, with the deterministic local rubric as an offline fallback.
- `Интеллектуальный батл` now applies the question nominal as a penalty after a rejected answer when `negativeScoreEnabled` is enabled. The same rule applies to ordinary buzzer questions and `Секретная передача`.
- Games Hub settings for Jeopardy, `Управленческая игра "Ваш выбор"` and all three negotiation modes are now copied into the room snapshots, including timers and mode-specific flags.
- No database migration and no new MySQL tables.

## Runtime invariants

- Existing rooms without `negativeScoreEnabled` keep the old behaviour (no penalty).
- Penalties use the existing mechanics ledger and idempotency keys, so a repeated resolve request cannot charge the same wrong answer twice.
- Sales / business negotiations / express round still use the single `negotiation_duel` runtime and shared history.
