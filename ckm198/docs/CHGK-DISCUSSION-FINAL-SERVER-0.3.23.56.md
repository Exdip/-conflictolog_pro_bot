# CHGK discussion → final answer server split

Version: `0.3.23.56-dev.88-CHGK-DISCUSSION-FINAL-SERVER`

## Scope

Server-side phase split only. Frontend phase rendering is intentionally deferred to the next iteration.

## Behavior

- CHGK question starts in `discussion`.
- Final answer submission before `chgk_final_answer_opened` returns `409 final_answer_not_open`.
- When discussion deadline expires, the server emits `chgk_discussion_closed`, opens a separate final-answer window, emits `chgk_final_answer_opened`, and replaces `question_deadline_at` with the final-answer deadline.
- Default final-answer window: 30 seconds; supported server range: 10–300 seconds.
- Discussion remains configurable at 60–300 seconds.
- When the final-answer deadline expires, the shared core closes the question and starts the existing arbitration flow.
- Human-host `close` during discussion now opens the final-answer window; a subsequent close closes answer intake.
- Existing single-final-answer locking, local/AI arbitration and `closeWhenAllAnswered` are preserved.

## No schema migration

The sub-phase is derived from immutable CHGK events, so no MySQL table or column is added.
