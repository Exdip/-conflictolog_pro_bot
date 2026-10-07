# CHGK pause + host takeover — 0.3.23.69

- Adds durable MySQL-backed pause fields to the games table.
- Pause freezes the current discussion/final-answer timer and survives F5.
- Resume restores a fresh server deadline from the saved remaining seconds.
- AI -> human takeover is allowed without resetting question/score.
- Human -> AI is allowed only in safe CHGK states: waiting, discussion, or after answer reveal with arbitration complete.
- Participant answer POST is rejected with `game_paused` while paused.
- AI deadline refresh is disabled while paused.
- DB version: 0.3.14.4.
