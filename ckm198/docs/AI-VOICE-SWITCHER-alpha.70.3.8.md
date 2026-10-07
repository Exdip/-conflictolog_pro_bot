# alpha.70.3.8 — AI Voice Switcher

- Admin voice provider switch: free browser SpeechSynthesis or Sergey via Cartesia/CKM Direct Gateway.
- Browser mode exposes the Russian voices available on the participant device and remembers the selected voice in localStorage.
- Gateway mode mirrors authoritative `ai_host_message` events to `/v1/voice/messages`, signed with the existing CKM voice secret.
- Participant/scoreboard/host listeners authenticate to `/voice` with existing short-lived real-game tokens.
- Gateway TTS frames (`voice_start` + binary WAV + `voice_end`) are queued and played through Web Audio.
- Human-host microphone relay remains unchanged and is still exposed only when `host_mode=human`.
- No new database tables. Existing voice settings option is extended compatibly.
