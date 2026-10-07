# Games Hub user flow — 0.3.23.48

This build hardens the public Games Hub → organizer → room path.

## Changes

- `?ckm_format=...` is now sufficient server-side; JavaScript is no longer required to preserve the runtime choice.
- Safe server defaults mirror Games Hub 1.7 format profiles when no JSON payload is present.
- The organizer launch form preserves `ckm_format` and `ckm_runtime_config_json` across POST.
- Sales, Business Negotiations and Express Round now select the matching `negotiationMode` template instead of the first generic `negotiation_duel` quiz.
- The seven-format smoke test now checks catalog-runtime → exact published template routing before creating its temporary room.

No new MySQL tables are created.
