# CKM Phone Media Gateway 531

Телефонный голосовой мост для единого голоса **Сергей**.

Поток: `SIP/PSTN -> Asterisk AudioSocket -> Deepgram Nova-3 ru -> CKM AI seller -> CKM Voice Gateway -> Cartesia Сергей -> AudioSocket -> абонент`.

WordPress не получает телефонное аудио. Cartesia API key и Voice ID не копируются в WordPress: синтез выполняет уже настроенный CKM Voice Gateway.

## ENV

- `CKM_BASE_URL=https://ckkm.ru`
- `CKM_PHONE_GATEWAY_SECRET=...` — совпадает с полем в «Интеграции»
- `DEEPGRAM_API_KEY=...`
- `CKM_PHONE_HTTP_HOST=127.0.0.1` (default)
- `CKM_PHONE_HTTP_PORT=9018` (default)
- `CKM_AUDIOSOCKET_HOST=0.0.0.0` (default)
- `CKM_AUDIOSOCKET_PORT=9019` (default)
- `ASTERISK_CONTROL_URL=...` — optional local control endpoint for transfer; without it hangup works, transfer is reported unavailable

## Asterisk inbound example

Requires `func_uuid`, `func_curl` and `app_audiosocket`. Keep the control HTTP listener on localhost.

```ini
[ckm-ai-inbound]
exten => _X.,1,Set(CKM_UUID=${UUID()})
 same => n,Set(CKM_INIT=${CURL(http://127.0.0.1:9018/calls/inbound?uuid=${URIENCODE(${CKM_UUID})}&did=${URIENCODE(${EXTEN})}&caller=${URIENCODE(${CALLERID(num)})})})
 same => n,Answer()
 same => n,AudioSocket(${CKM_UUID},127.0.0.1:9019)
 same => n,Hangup()
```

The worker accepts PCM16 AudioSocket frames. Deepgram receives 8 kHz `linear16`, `model=nova-3`, `language=ru`. Sergey audio is collected as WAV from the existing CKM Voice Gateway, converted to mono PCM16/8k and paced back to Asterisk.

## Boundaries

531 implements the **inbound media path** and command queue. Outbound PBX originate remains provider/PBX-specific and is intentionally not exposed as a fake «Позвонить» button. Configure the SIP trunk in Asterisk/PBX first. Operator transfer requires `ASTERISK_CONTROL_URL`; hangup can be executed by closing the AudioSocket.
