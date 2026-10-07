# alpha.70.3.8 — AI voice provider switch

- Admin setting: Browser TTS (free) or Sergey (Cartesia via CKM Direct Gateway).
- Browser mode exposes the locally available Russian SpeechSynthesis voices in the game room and stores the participant choice in localStorage.
- Gateway mode mirrors `ai_host_message` events to `POST /v1/voice/messages` using `ckm_voice_broadcast_v1` + HMAC-SHA256.
- Real game participants/scoreboards/host listener tabs receive Cartesia WAV over authenticated `wss://.../voice` and play it through Web Audio.
- Human-host microphone relay remains intact and is only exposed for `host_mode=human`.
- Cartesia voice selection itself remains server-side in Gateway (`CARTESIA_VOICE_ID`), so the configured Sergey voice is shared consistently across clients.
