# CHGK TIMER CONSTRUCTOR

Version: `0.3.23.58-dev.90-CHGK-TIMER-CONSTRUCTOR`

- Constructor exposes separate discussion and final-answer timers for `chgk`.
- Discussion presets: 60, 90, 120, 180, 300 seconds plus custom value (60-300).
- Final-answer presets: 15, 30, 60, 90, 120, 180, 300 seconds plus custom value (10-300).
- Existing games read current values from `format_settings_json`; legacy games fall back to the quiz discussion time and 30 seconds for final answer.
- Saving stores `discussionSeconds` and `finalAnswerSeconds` independently.
- `seconds_per_question` remains the compatibility mirror for the discussion timer.
- Games Hub adapter accepts optional `final_answer_seconds`.
