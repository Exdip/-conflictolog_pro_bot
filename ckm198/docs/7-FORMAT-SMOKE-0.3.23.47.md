# 0.3.23.47 — 7 Format End-to-End Smoke

Adds **Игровая платформа → Диагностика 7 форматов** (administrator only).

The smoke test checks all public Games Hub runtimes:

- classic_quiz_v1
- chgk_v1
- jeopardy_v1
- decision_price_v1
- sales_v1
- business_negotiation_v1
- express_round_v1

For every case it creates a temporary `test_mode=1` room using the existing shared demo content, checks runtime mapping and frozen room/format snapshots, then removes the temporary game rows.

Additional live checks:

- CHGK: 90-second server runtime and roulette mode.
- Jeopardy: negative score enabled, a real wrong-answer penalty, and idempotency (same penalty request does not debit twice).
- Decision Price: host arbitration is normalized to `human`.
- Negotiation Duel: sales/business/express modes and their mode-specific settings are frozen into the existing runtime.

No new MySQL tables are introduced.
