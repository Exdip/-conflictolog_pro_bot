# Negotiation Master — NEG-RU-BRANDING

Current plugin build: `0.3.23.309-dev.341-NEG-ZOPA-SEMANTIC-FIX`.
Base: `.334-NEG-UI-SKIN`.
NEG DB version: `1.8.0` (pins opponent difficulty to sessions and organizer assignments).
Content version: `1.4.0` (publishes all 10 scenarios in «Договориться нельзя конфликтовать»; nine former stubs are promoted in place).

## Implemented path

- NEG-CORE: isolated storage and admin health; assignments expand it to 17 module tables.
- NEG-SCENARIO: system scenario «Контракт на поставку» as DB content.
- NEG-SESSION: start/resume/pause, idempotent player messages, safe player snapshot.
- NEG-OPPONENT: one server-side AI opponent reply per player message.
- NEG-ARBITER: second AI contour turns negotiation dialogue into validated formal state.
- NEG-COACH: on-demand in-game coach for Training mode only.
- NEG-AGREEMENT: exact final agreement and confirmed no-deal terminal branches.
- NEG-EVALUATION: PHP-authoritative /100 result with criterion-local AI analysis and evidence.
- NEG-RECOVERY: resumable turn pipeline, processing lease, writer lease/takeover and serialized evaluation.
- NEG-MVP: three materially different scenarios use the same runtime, including categorical/boolean items and data-driven hard package constraints.
- NEG-PRODUCT-UI: product route from catalogue to scenario, session and result.
- NEG-LIBRARY: data-driven scenario libraries with separate access state and public coming-soon cards.
- NEG-CONTENT: publishes all 10 scenarios in «Договориться нельзя конфликтовать» using data only; nine former stubs are promoted without scenario-specific runtime branches.

## NEG-COACH design

The coach is a separate AI role. It never becomes part of the negotiation transcript and does not
change `item_state`, discovered facts, arbiter events or formal session state.

Four help levels are available in Training mode:

- `attention` — point out one important issue without telling the player what exact move to make;
- `direction` — suggest a direction for the next action without a ready-made phrase;
- `example` — return exactly one sample negotiation utterance;
- `review_last_move` — briefly review the player's latest negotiation message.

Exam mode blocks the coach server-side with HTTP 403 / `COACH_NOT_AVAILABLE_IN_EXAM`; hiding the
button is not relied on for access control.

## Hidden-data boundary

`InGameCoachContextBuilder` builds the model request from `PlayerSessionSnapshotBuilder`, the same
projection that is safe for the player. Therefore the model receives only:

- the player-visible scenario card;
- player-visible item state;
- partial/revealed facts already visible to the player;
- negotiation dialogue;
- previous coach hints;
- the latest player message and help counters.

It does not query or receive opponent hidden interests, target/boundary values, hidden alternative,
walkaway rules, reveal rules, ZOPA or evaluation answer keys. Unknowns may only be mentioned as
explicit hypotheses.

## Persistence

Coach responses are stored in `ckm_neg_messages` with:

- `channel='coach'`;
- `actor='coach'`;
- `coach_level`;
- `related_message_id` (latest player negotiation message when one exists);
- a client request id for idempotency.

The main negotiation transcript remains `channel IN ('dialogue','negotiation')`, so neither the
opponent nor arbiter receives coach messages.

## Intentional limits

NEG-MVP does not yet provide:

- draft test-launch for unpublished builder versions; the frontend scenario builder itself is now implemented.
- background job queues; recovery is request-driven from resume/retry and protected by short server leases.

Existing quiz/game/payment/STT/voice/organizer mechanics are not refactored by this module.

## NEG-AGREEMENT (dev.324)

- Exact package drafts are built only from validated server item state.
- A draft is pinned to state revision and dialogue position; stale drafts return a conflict.
- Final opponent response is constrained to accept / partial / reject, while PHP remains authoritative for completion.
- Player red-line breaches are recorded as training outcomes; opponent hard constraints remain server-only and can block impossible finalization.
- No-deal is a two-step preview/confirm action with a signed short-lived token.
- Completed sessions are immutable and readable; replay creates a new session.


## NEG-EVALUATION (dev.325)

- Uses the existing `ckm_neg_evaluations` and `ckm_neg_evaluation_scores` tables; no DB schema bump is required.
- `EvaluationService` reads six rules from the pinned scenario version and verifies that their weights total 100.
- PHP calculates formal evidence for economic result, revealed interests, concessions, boundary protection and package construction.
- `AiEvaluator` returns only criterion-local `raw_score`, level, confidence, evidence message ids and a reason; it never returns the global `/100` result.
- Hybrid criteria combine PHP and AI portions server-side; the global score is always calculated by PHP.
- If a required qualitative AI evaluation is missing or insufficiently confident, the evaluation is marked failed rather than rescaling partial points to 100.
- Agreement/no-deal result type is stored separately from the numeric score. A rational no-deal can score well, and a red-line breach remains visible even when other skills score highly.
- Training help counts are shown separately and do not reduce the negotiation score.
- Historical completed evaluations are immutable by default; repeated player requests return the stored result.


## NEG-RECOVERY (dev.326)

- Session processing is persisted through explicit phases: `player_message_saved`, `player_analysis_pending`, `player_analyzed`, `opponent_generation_pending`, `opponent_generating`, `opponent_saved`, `opponent_analysis_pending`, `opponent_analyzed`, `opponent_failed`, and `idle`.
- A short `processing_lock_token` lease prevents two PHP workers from processing the same turn concurrently. Expired leases can be recovered safely.
- `/resume` inspects persisted messages and analysis status and continues only the next provably incomplete stage. A saved opponent reply is reused instead of regenerated.
- One official opponent message remains enforced by `(session_id, channel, reply_to_message_id)`, while player resend remains idempotent by `(session_id, client_message_id)`.
- Each browser tab receives its own `client_id` in `sessionStorage`. A server-side writer lease allows many readers but one active writer. A conflicting writer gets `WRITER_CONFLICT`; takeover requires explicit user confirmation.
- Agreement/no-deal/version-sensitive mutations keep using state revisions. Completed sessions clear runtime leases and remain immutable.
- Evaluation uses the same processing lease so duplicate evaluation starts cannot execute concurrently.
- Admin-only recovery diagnostics expose status, last message ids, analysis states, failure count and lease state without exposing hidden scenario data or lease tokens.

## NEG-MVP (dev.327)

- Content pack `1.2.0` contains three published system scenarios:
  - «Контракт на поставку» — commercial/numeric negotiation.
  - «Проект под давлением» — deadline/scope/quality/resource negotiation with categorical and boolean items.
  - «Трудный разговор с коллегой» — organizational negotiation without money.
- The two new scenarios are content rows only: items, hidden facts, rules and evaluation rules. Runtime services do not branch on scenario slug or opponent name.
- Generic rule action `block` allows a data-defined hard package constraint to prevent impossible final agreements without exposing hidden opponent data.
- The player page no longer defaults by a hard-coded scenario slug; without `neg_scenario` it resolves the first published system scenario from the repository.
- Agreement result classification is generic and uses final `/100` plus red-line outcome rather than requiring contract-specific criterion codes.
- NEG DB schema remains `1.4.0`; only NEG content advances to `1.2.0`.



## NEG-LIBRARY (dev.329)

- Adds `ckm_neg_libraries`; scenarios reference a library by nullable `library_id`.
- «Базовые сценарии» is a free system library containing the three MVP scenarios.
- «Договориться нельзя конфликтовать» is a published paid-library shell with ten public cards.
- «Ценный кадр на выход» is the first fully published scenario in that library; the other nine are draft/coming-soon stubs and can be promoted in later content packs without changing runtime code.
- Four future library shells are stored as draft content: «Цена вопроса», «Разговор по делу», «На стыке интересов», «Доля влияния».
- `LibraryAccessService` server-enforces scenario start. It can reuse an existing entitlement key or external entitlement filter and adds no payment provider.
- Locked catalogue/library projections expose only title, public description and availability. Opponent hidden data, rules and reveal conditions remain server-side.
- The first library scenario is an adaptation of the source conflict about the phrase «незаменимых нет»; scenario-specific hidden motives and package parameters are explicitly marked as training construction rather than source facts.


## NEG-BUILDER (dev.331)

- Adds a frontend scenario constructor at `/ckm-negotiation-builder/`; it is not a WordPress-admin editor.
- Organizer access reuses the existing frontend-constructor entitlement and additionally requires access to `negotiation_duel_v1`; administrators bypass the entitlement check. No payment provider is added.
- Builder sections mirror the data model: situation, player card, AI opponent + hidden facts, negotiation items, rules and evaluation.
- Organizers can create tenant-owned draft scenarios, edit only their own content, duplicate system scenarios they are already entitled to, validate and publish.
- Published content is not rewritten in place. Saving a published scenario forks a new draft scenario version; `current_version_id` changes only when the draft passes validation and is published.
- A publish gate requires the player/opponent cards, at least one negotiation item, at least one required agreement item, evaluation criteria, and criterion weights totaling 100.
- System scenarios remain immutable. Paid-library hidden content can be copied only after the same server-side library entitlement check used for gameplay.
- Tenant-published custom scenarios appear in the normal «Мастер переговоров» catalogue and run through the same generic Session/Opponent/Arbiter/Coach/Agreement/Evaluation runtime.
- A dedicated commercial builder SKU remains intentionally deferred; dev.332 adds autosave and isolated draft test-launch.


## NEG-BUILDER-QA (dev.332)

- Builder drafts autosave after a short debounce; malformed JSON pauses autosave instead of overwriting the draft.
- The editor warns before leaving while local changes are still dirty.
- `POST /negotiation/builder/scenarios/{id}/test-launch` starts an isolated `builder_test` session pinned to the current draft version.
- Draft test-runs use the same Opponent/Arbiter/Coach/Agreement/Evaluation runtime but are excluded from normal active-attempt, progress, history and completed-count queries.
- Sessions now have `session_kind` (`player` or `builder_test`), advancing NEG DB to 1.6.0; existing rows default to `player`.
- The normal `/sessions/start` route still rejects unpublished scenarios; only the builder-owned test route can launch a draft.
- A builder test can be opened only by the owning tenant/user (or administrator with builder access), and system drafts cannot be test-launched through this path.
- Returning from a test-run opens the same scenario in the builder.

## NEG-ASSIGNMENTS (dev.333)
- Two tables: `assignments` and `assignment_participants`; scenario versions are pinned at assignment creation.
- Organizer can set training/exam, independent or shared-team play, attempt limit, voice, deadline and result visibility.
- Existing WordPress accounts are assigned by login/email/ID; anonymous invite-code onboarding is intentionally deferred.
- Assigned sessions use `session_kind=assignment`; team members may share one server-authoritative session.


## NEG-DIFFICULTY (dev.335)

- Adds four server-pinned opponent strategy levels: `soft`, `medium`, `hard`, `expert`.
- Difficulty changes opponent strategy and concession/reveal tempo only; it never changes hard constraints, player red lines, scenario facts, formal state validation or evaluation weights.
- Standalone players choose the level before starting; organizer assignments can force a level for every assigned attempt.
- The chosen level is stored on the session and exposed in the player-safe snapshot/history.
- `OpponentPromptBuilder` receives a server-side strategy policy: soft is more transparent and responsive, hard requires stronger reciprocity, expert negotiates across multiple variables and tests consistency.
- Hard/expert remain professional rather than hostile and may not become artificially impossible to negotiate with.
- Scenario `mechanics_json` may optionally define `allowed_difficulties` and `default_difficulty` without runtime branches.
- NEG DB advances to `1.8.0`; content remains `1.4.0`.


## NEG-RU-BRANDING (dev.336)

- Интерфейс «Мастера переговоров» использует те же семейства шрифтов, что и основной сайт ЦКМ: Ubuntu и Caveat.
- Текстовая заглушка бренда заменена официальным логотипом CKKM.
- Пользовательские надписи переведены на русский язык; машинные enum-коды остаются внутренними.
- Категориальные значения в игровом состоянии получают русские подписи из безопасной части конфигурации предмета переговоров.


## NEG-REOPEN (dev.338)

- Individual negotiation items can now become formally `agreed` before the final package when one side explicitly accepts the other side's concrete offer.
- Agreed conditions are not silently overwritten by later offers. Reopening follows each item's `reopen_policy`: `never`, `with_reason`, `free_until_final`, or `explicit_mutual_confirmation`.
- Valid reasons are server-whitelisted: new information, new risk, scope change, withdrawal of a linked concession, dependency change, or changed circumstances.
- `explicit_mutual_confirmation` keeps the old agreed value authoritative until the other side explicitly confirms reopening; rejection leaves the old condition agreed.
- Reopening is auditable through server-derived `reopen_requested`, `item_reopened`, `reopen_rejected`, `justified_reopen`, and `unjustified_reopen_attempt` events.
- Player-safe snapshots expose only visible agreed/reopen state and never hidden rule data. The right panel shows pending and active revisions in Russian.
- NEG DB remains `1.8.0`; content remains `1.4.0`.
