# CKM Live sideband worker

This is a platform-owned process, not a partner process. It keeps the long-lived OpenAI Live sideband WebSocket outside PHP-FPM and forwards only trusted session events to WordPress.

Environment:

- `CKM_BASE_URL=https://ckkm.ru`
- `CKM_SIDEBAND_SECRET=<same secret configured by the CKM administrator>`
- `OPENAI_API_KEY=<platform OpenAI project key>`
- `OPENAI_PROJECT_ID=proj_...` (recommended)

Run with Node 20+:

```bash
npm install --omit=dev
node index.mjs
```

Use a service manager such as systemd, Supervisor, Docker, or the existing realtime gateway. The worker must remain online for the duration of calls. It never receives partner SIP passwords: audio stays on the SIP↔OpenAI media path and the worker only attaches to the existing Live session for events.
