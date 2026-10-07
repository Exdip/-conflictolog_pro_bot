# NEG-COACH verification — dev.323

Target ZIP: `ckm-quiz-pro_0.3.23.291-dev.323-NEG-COACH.zip`
Base ZIP: `ckm-quiz-pro_0.3.23.290-dev.322-NEG-ARBITER.zip`

## Automated checks in this build

- PHP syntax: **251/251 files OK**.
- JavaScript syntax: Negotiation Master runtime **OK**.
- `tests/neg-coach-323-test.php`: **32/32 PASS**.
- `tests/neg-coach-validator-323-test.php`: **12/12 PASS**.
- Previous `NEG-ARBITER` targeted tests remain **PASS**.
- Root test sweep: **124 tests total = 52 PASS, 71 FAIL, 1 SKIP**.
- All 71 FAILs are historical exact-version/build assertions; **no non-version failure was found**.
- The SKIP is the existing SQLite runtime test because this PHP container has PDO but no `pdo_sqlite` driver.

## Storage migration

NEG DB version advances from `1.1.0` to `1.2.0`.
No new tables are created. `ckm_neg_messages` gains:

- `coach_level varchar(32) NULL`;
- `related_message_id bigint unsigned NULL`;
- index `(session_id, channel, related_message_id)`.

The existing migration mechanism remains idempotent and uses `dbDelta` plus schema verification.
Content version remains `1.1.0`.

## Security/state invariants checked

- Exam mode rejects the coach server-side with HTTP 403 / `COACH_NOT_AVAILABLE_IN_EXAM`.
- The coach context is derived from `PlayerSessionSnapshotBuilder`, not from the full scenario version.
- Coach model input has no direct query/access path to opponent hidden interests, opponent target/boundary values,
  hidden alternative, walkaway data, reveal rules, ZOPA or evaluation answer keys.
- Partial facts reach the coach only in the same redacted/public form visible to the player.
- Coach responses are stored separately as `channel='coach'`, `actor='coach'`.
- Opponent and arbiter context builders continue to use negotiation-only messages, so coach hints are not fed back into the negotiation.
- Coach service does not invoke the arbiter or mutate formal negotiation state.
- Four help levels are distinct and output-shape validation prevents the strongest common cross-level leaks
  (for example a ready-made phrase in `direction` or a prescribed next move in `attention`).
- Client request IDs make persisted coach responses idempotent against duplicate submission.
- No scenario-specific names/slugs are present in coach runtime code.

## Still requires live verification

- WordPress + MySQL/MariaDB upgrade from NEG DB `1.1.0` to `1.2.0`.
- Real AI Puffer/provider response shape, latency and retry behavior.
- Browser smoke test for Training vs Exam UI and coach history after refresh.
- Regression of existing games/payments/STT/voice/organizer on the installed site.
