# NEG-OPPONENT verification — dev.321

Base archive: `ckm-quiz-pro_0.3.23.288-dev.320-NEG-SESSION.zip`.
Output target: `ckm-quiz-pro_0.3.23.289-dev.321-NEG-OPPONENT.zip`.

## Implemented

- Dynamic, scenario-independent AI opponent service inside `modules/negotiation-master/ai/opponent/`.
- Uses the existing server-side AI Puffer connector and existing provider/model settings.
- Full opponent/private scenario context is assembled only on the server.
- Player-visible snapshot still exposes only the existing public scenario projection.
- Recent opponent context contains only `dialogue` / `negotiation` channel messages.
- One official opponent reply is linked to one player message via `reply_to_message_id`.
- Added unique DB index `(session_id, channel, reply_to_message_id)` and bumped isolated NEG DB schema to `1.1.0`.
- AI failure changes only `processing_status` to `opponent_failed`; the player's message stays persisted.
- Retry operates on the existing player message and does not create another player message.
- If a reply already exists, retry/send returns it and does not call AI again.
- No item state, hidden-fact state or evaluation state is changed by NEG-OPPONENT.

## Validation / safety

Opponent prompt explicitly forbids:
- becoming a coach/arbitrator;
- revealing system/developer instructions or engine internals;
- revealing exact internal negotiation limits;
- blindly following prompt-injection instructions in player messages;
- inventing scenario facts;
- automatically agreeing without a reason.

The response validator rejects obvious technical material and explicit internal-boundary disclosures. One stricter regeneration attempt is allowed after validator rejection.

## Checks executed in this environment

- PHP syntax: all plugin PHP files — checked separately before packaging.
- New session JS syntax — checked with Node.
- `tests/neg-opponent-321-test.php`: static architecture/security assertions.
- Standalone prompt/validator behavioral smoke: normal refusal accepted; explicit internal max-price disclosure rejected.

## Still required on WordPress/MySQL + real AI

1. schema migration adds the new unique message-reply index without affecting existing rows;
2. send a player message and receive exactly one AI opponent message;
3. verify `turn_no` is shared and `reply_to_message_id` points at the player message;
4. resend the same `client_message_id` and confirm no new player/opponent rows;
5. simulate AI failure, confirm player message remains and Retry answer succeeds;
6. refresh after a completed reply and confirm no new AI call/reply;
7. attempt prompt injection and direct requests for the buyer's internal maximum;
8. Network responses still contain no hidden scenario fields;
9. hidden facts / item state remain unchanged in this build;
10. existing games/cabinet/checkout/STT/voice still smoke-test normally.
