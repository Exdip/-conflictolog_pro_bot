# 0.3.13 — MAIN SITE ROOM FLOW

Сборка выравнивает standalone room-flow с основной платформой.

## Маршруты
- `/ckm/quiz-room.html` — участник
- `/ckm/quiz-host.html` — ведущий
- `/ckm/quiz-scoreboard.html` — табло

Маршруты обрабатываются раньше `redirect_canonical` и managed frontdoor. Старые ссылки сохранены как fallback.

## Интеллектуальный батл
Участник может выбирать ячейку только в режиме `host_mode=ai` и только если его команда владеет правом выбора. В режиме `host_mode=human` ячейки выбирает ведущий из host panel.

Серверная последовательность повторяет main-site contract: `ckm_quiz_jeopardy_select_cell()` -> проверка спецмеханики -> `ckm_quiz_open_next_question(..., participant, user_id)`. Отдельная standalone-проверка подключения всех команд удалена.
