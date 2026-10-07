# CKM Quiz Pro 0.3.23.55 — CHGK one-team hard fix

Исправлены все обнаруженные уровни, которые повторно поднимали минимум до 2:

- standalone `team_policy`: `chgk` теперь `min=1`;
- CHGK lobby preflight: `max(1, ...)`, а не `max(2, ...)`;
- frontend organizer: для `chgk` эффективный минимум всегда 1, даже при старой строке БД;
- wp-admin test room: то же правило;
- нормализация `min_teams=1` выполняется и на frontend `init`, а не только через `admin_init`.

Остальные форматы не изменены.
