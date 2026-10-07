# CKM Quiz Pro 0.3.11 — Human host microphone

- Human-host games keep working fully without a microphone.
- Optional live microphone transport uses the existing CKM Direct Gateway WebSocket `/voice`.
- Host: `Подключить микрофон` → hold `Нажать и говорить`.
- Participants and scoreboard: `Включить голос ведущего`.
- The browser receives only a short-lived HMAC-signed game/role/team token. The shared Gateway secret never reaches JavaScript.
- Gateway URL defaults to `https://gateway.video-training.ru`.
- Server secret can be supplied by `CKM_QUIZ_PRO_VOICE_SECRET`, `CKM_VOICE_SECRET`, or the admin-only CKM Quiz Pro → Голос settings page.
- No video/camera code is included.
- No database migration is required.
