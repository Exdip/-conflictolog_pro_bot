# CKM Quiz Pro 0.3.10 — Host mode choice

Constructor host modes:
- `ai` — «ИИ-ведущий»;
- `human` — «Ведущий (с микрофоном/без микрофона)».

The selected mode is stored on the quiz and snapshotted into a game at room creation. Human mode disables AI autopilot and exposes the standalone host panel with start/close/next/finish controls. The current DEV standalone build keeps objective/local reference matching for scoring. Microphone transport is intentionally not faked in this stage; Gateway integration remains separate and no server secret is embedded in the plugin.
