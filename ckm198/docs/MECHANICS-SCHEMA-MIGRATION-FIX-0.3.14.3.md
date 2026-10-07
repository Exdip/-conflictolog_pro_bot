# CKM Quiz Pro 0.3.14.3 — MECHANICS SCHEMA MIGRATION FIX

Причина: standalone-adapter уже ожидал таблицу `wp_ckm_quiz_mechanics`, а `standalone-schema.php` её не создавал. «Интеллектуальный батл» использует эту таблицу при первом выборе ячейки (`jeopardy_cell`), поэтому INSERT завершался `mechanic_insert_failed`.

Исправление:
- добавлена таблица `ckm_quiz_mechanics` по схеме основного CKM;
- DB version: `0.3.14.3`;
- миграция перенесена с `admin_init` на ранний `init`, чтобы frontend-организатор также гарантированно применял схему;
- Shared Quiz Core не изменён.
