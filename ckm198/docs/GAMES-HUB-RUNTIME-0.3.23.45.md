# CKM Quiz Pro 0.3.23.45 — Games Hub runtime integration

This build connects the separate CKM Games Hub storefront/configurator to the existing Quiz Pro engine without creating a second gameplay runtime or new database tables.

Implemented in this step:

1. Runtime aliases from Games Hub are mapped to existing Quiz Pro format keys:
   - `classic_quiz_v1` -> `classic_quiz`
   - `chgk_v1` -> `chgk`
   - `jeopardy_v1` -> `jeopardy`
   - `decision_price_v1` -> `solution_price`
   - `sales_v1`, `business_negotiation_v1`, `express_round_v1` -> `negotiation_duel`
2. The organizer launcher auto-selects a published quiz of the requested format when opened with `?ckm_format=...`.
3. The organizer launch form is marked as a CKM constructor so Games Hub can inject `ckm_runtime_config_json`.
4. Runtime JSON is passed into `ckm_quiz_create_room()` and stored in the existing game snapshots.
5. Server-authoritative settings now affect gameplay:
   - CHGK `discussion_time_seconds` -> actual question deadline (`discussionSeconds`).
   - CHGK `roulette_enabled` -> actual `questionSelectionMode=roulette`.
   - Classic/Jeopardy `answer_time_seconds` -> room-level authoritative timer override.
   - `speed_bonus_enabled` -> the existing speed-bonus calculation.
6. Negotiation Hub aliases select the existing `negotiation_duel` runtime and set its mode (`sales`, `business`, `express`).

No schema migration and no new MySQL tables are introduced.
