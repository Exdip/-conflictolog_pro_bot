# CHGK pause/recovery smoke hardening — 0.3.23.70

- Adds `game_paused` directly to the CHGK final-answer validation path, so the engine itself rejects writes while paused.
- Extends the existing CHGK E2E smoke test with a deterministic discussion pause at ~73 seconds remaining.
- Rebuilds participant state twice while paused to verify F5-safe restoration (`questionDeadlineUnix=0`, paused phase and remaining time preserved).
- Verifies AI -> human takeover does not reset the question or pause state.
- Verifies resume creates a fresh server deadline from the saved remaining time rather than resetting the full timer.
- Repeats pause/resume inside `final_answer_open` and verifies a final answer cannot be submitted while paused.
- Verifies human -> AI succeeds in the safe discussion phase when AI Host is configured, or returns `ai_host_unavailable` without changing state when it is not configured.
- No database migration is required beyond schema version 0.3.14.4 introduced in 0.3.23.69.
