# CKM Quiz Pro 0.3.12.2 — Intellectual Battle action/state fix

Fixes the case where 100/200/300 reacted visually but the participant saw no transition.

- `jeopardy_action` now returns authoritative state after every successful action.
- participant UI renders that state immediately rather than waiting for the polling loop.
- repeated clicks are guarded while the server action is in flight.
- a room left with `jeopardy_selected_question_id` but no open question by 0.3.12/0.3.12.1 can recover by retrying the same selected cell.
- shared Quiz Core files are unchanged.
