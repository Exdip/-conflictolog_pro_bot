# NEG-SESSION verification — dev.320

Base archive: `ckm-quiz-pro_0.3.23.287-dev.319-NEG-SCENARIO.zip`.
Output target: `ckm-quiz-pro_0.3.23.288-dev.320-NEG-SESSION.zip`.

## Implemented

- New isolated application layer:
  - `SessionService`
  - `MessageService`
  - `PlayerSessionSnapshotBuilder`
- New REST surface:
  - `POST /wp-json/ckm/v1/negotiation/sessions/start`
  - `POST /wp-json/ckm/v1/negotiation/sessions/{id}/messages`
  - `GET /wp-json/ckm/v1/negotiation/sessions/{id}/resume`
  - `POST /wp-json/ckm/v1/negotiation/sessions/{id}/pause`
- New player page `/ckm-negotiation-master/` created idempotently and backed by
  shortcode `[ckm_negotiation_master]`.
- Session starts with `status=in_progress`, `processing_status=idle`,
  `state_revision=1`, `evaluation_status=not_started` and a pinned published
  `scenario_version_id`.
- Every scenario item receives an `item_state` row with `not_discussed`.
- Hidden facts are not pre-created in `discovered_facts`.
- Duplicate `client_message_id` replays the existing message instead of
  creating a duplicate. Message save + state-revision increment are one DB
  transaction.
- Pause/resume keeps the same session. Explicit restart marks the active old
  attempt `abandoned`; it is never deleted.
- Player snapshot uses only explicit public fields. It does not serialize the
  complete scenario version or `state_json`.
- Hidden facts with no discovery row are absent. A future `partial` fact exposes
  title only; full `content` is withheld until reveal level >= 2.
- UI state sections are built from server snapshot and required scenario items,
  not hardcoded item state.

## Source-isolation check

Compared with `.319`, changes are limited to:

- `ckm-quiz-pro.php` version marker;
- `VERSION.txt`;
- `modules/negotiation-master/` additions and the two existing module files
  `bootstrap.php` and `repositories.php`;
- one new regression test;
- this verification/release documentation.

No existing game/payment/STT/voice/organizer/checkout implementation file was
modified.

## Checks executed in this environment

- PHP syntax: all 228 PHP files — OK.
- JavaScript syntax for the new session client — OK.
- New static NEG-SESSION regression test: 29/29 — PASS.
- Additional in-memory behavioral smoke of the actual SessionService/MessageService: start, four item states, no hidden leak, first message, duplicate replay, pause, resume and restart — PASS.
- Historical root test files discovered: 118.
  - 48 complete successfully unchanged.
  - 70 stop only on their historical exact plugin-version marker assertions.
    Their reported FAIL lines contain no functional assertion failures; these
    legacy tests intentionally pin old build numbers and were not rewritten.

The bundled SQLite integration harness could not run in this container because
its PHP build has PDO but no `pdo_sqlite` driver. This is an environment
limitation, not a successful DB-runtime test.

## Still required on the real site

This build has **not** been installed on WordPress/MySQL here. Before accepting
`.320` as the deployment baseline, verify on the real WordPress/MySQL site:

1. plugin update completes without fatal error;
2. existing games/cabinet/checkout still open normally;
3. `/ckm-negotiation-master/` is created and visible to an authenticated user;
4. start creates one session + four `not_discussed` item-state rows;
5. five messages survive F5/resume;
6. resending the same `client_message_id` creates no duplicate row;
7. pause/resume keeps the same session;
8. restart preserves the old attempt as `abandoned`;
9. Network responses contain no opponent hidden interests/boundaries/targets,
   hidden fact content, reveal rules or raw `state_json`.

No AI is called in this build by design.
