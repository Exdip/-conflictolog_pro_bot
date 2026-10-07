# CHGK E2E smoke — 0.3.23.65

Admin page: **CKM Quiz Pro → Smoke: Битва знатоков**.

The smoke test runs the real server flow on temporary `test_mode=1` data:

1. verifies that a two-team CHGK room is rejected with `chgk_single_team_only`;
2. creates one team and passes lobby preflight;
3. verifies `discussion` and rejects an early final answer with `final_answer_not_open`;
4. opens `final_answer_open`, checks `final_answer_locked`;
5. alternates Experts/Game outcomes until 5:5 after ten questions;
6. awards the eleventh round to Experts and verifies automatic `game_finished` at 6:5;
7. verifies the required CHGK state events;
8. deletes the temporary game, teams, membership, answers, drafts, score events and events.

No production game is modified.
