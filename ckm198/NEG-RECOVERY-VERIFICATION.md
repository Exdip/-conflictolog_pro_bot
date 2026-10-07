# NEG-RECOVERY verification

Build: `0.3.23.294-dev.326-NEG-RECOVERY`
Negotiation DB schema: `1.4.0`
Base: `0.3.23.293-dev.325-NEG-EVALUATION`

## Implemented

- Persisted recovery state machine for player → arbiter → opponent → arbiter pipeline.
- Per-session processing lease with TTL to prevent duplicate workers.
- Per-tab writer identity and writer lease with explicit takeover.
- Resume coordinator continues only the next incomplete persisted stage.
- Duplicate player POSTs remain idempotent through `client_message_id`.
- Existing opponent reply is reused; a second official reply is not generated.
- Failed opponent generation remains an explicit retry state and is not silently retried on every refresh.
- Stale agreement processing is returned to a safe retryable state rather than reinterpreted as a normal dialogue turn.
- Evaluation execution is serialized by the processing lease.
- Completed terminal states clear runtime leases and remain read-only.
- Admin-only recovery diagnostics endpoint.
- Player-facing snapshots do not expose runtime lease tokens or server-only recovery fields.

## Validation performed in the build environment

- PHP syntax: 269/269 PHP files passed `php -l`.
- Browser JS syntax: `negotiation-session.js` passed `node --check`.
- `neg-recovery-326-test.php`: 34/34 checks passed.
- `neg-arbiter-validator-322-test.php`: 7/7 passed.
- `neg-coach-validator-323-test.php`: 12/12 passed.
- `neg-evaluation-ai-validator-test.php`: passed.
- `neg-evaluation-calculator-test.php`: 5/5 passed.
- `neg-arbiter-runtime-322-test.php`: skipped because the current PHP container does not provide `pdo_sqlite`.

Full historical root test sweep on this build:

- PASS: 51
- FAIL: 76
- SKIP: 1
- TOTAL: 128

The same sweep on the unchanged `.325` base in this environment was:

- PASS: 53
- FAIL: 73
- SKIP: 1
- TOTAL: 127

The new recovery test accounts for one additional test file. Three previously passing structural tests became failures after the recovery refactor:

- `neg-opponent-321-test.php`: its old static assertion expects the turn-completion lifecycle directly in `MessageService`; that lifecycle now belongs to `RecoveryService`.
- `neg-arbiter-322-test.php`: two old static assertions expect direct arbiter/opponent calls in `MessageService`; orchestration now belongs to `RecoveryService`.
- `neg-evaluation-325-test.php`: old exact build/schema-version expectations no longer match build `.326` / schema `1.4.0`.

Older NEG tests also contain exact historic build/schema markers and therefore continue to report expected version-marker failures after later builds. No claim is made that the complete historical suite is green.

## Not yet verified

This build has not yet been installed and exercised on the production-style WordPress/MySQL (or MariaDB) environment, and has not yet been tested end-to-end with the live Puffer AI transport. Those checks remain required before treating `.326` as a site-verified release.
