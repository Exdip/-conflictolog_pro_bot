# NEG-RU-BRANDING verification

Build: `0.3.23.304-dev.336-NEG-RU-BRANDING`

Проверяется:
- загрузка Ubuntu/Caveat как на основном сайте ЦКМ;
- официальный CKKM-logo-icon.webp в верхней панели;
- отсутствие видимых AI/BATNA/hard constraints/User/email/ID и сырых enum-подписей в пользовательском интерфейсе NEG;
- сохранение внутренних машинных значений без изменения БД;
- безопасная передача только публичных labels категориальных значений в session snapshot.
