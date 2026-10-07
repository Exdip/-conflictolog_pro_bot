# NEG-COMMITMENTS verification

Build: `0.3.23.305-dev.337-NEG-COMMITMENTS`

## Scope

Добавлен формальный lifecycle явных переговорных обязательств без новой таблицы БД. Источник истины — `sessions.state_json`, история — `ckm_neg_events`.

## Invariants

- acknowledgement и willingness не создают обязательство;
- «постараюсь» может быть только обязательством усилия;
- обязательство результата требует явного server-side cue;
- conditional commitment не считается нарушенным без подтверждённого наступления условия;
- player API получает только публичную проекцию уже произнесённых обязательств;
- opponent/coach/evaluation используют один и тот же validated commitment state;
- существующие игры, платежи, STT и контент сценариев не изменяются.
