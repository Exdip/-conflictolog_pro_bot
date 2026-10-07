# CKM Quiz Pro 0.3.14.2 — STATIC ROOM NONCE FIX

Причина ошибки «Сервер не вернул nonce участника»: физический файл `/ckm/quiz-room.html` обслуживался веб-сервером раньше WordPress и загружал main-platform `quiz-room.js`, который ожидает отдельную participant session nonce. Standalone API использует собственный local join contract.

Исправление:
- при standalone-режиме удаляются только распознанные stale HTML-shell файлы `quiz-room.html`, `quiz-host.html`, `quiz-scoreboard.html`;
- если активен основной `ckm-elevenagents-home-bridge`, очистка не выполняется;
- generated room URLs получают `ckmqpv=<plugin version>`, чтобы не использовать старый browser/proxy cache;
- Shared Quiz Core не меняется.
