# NEG explicit deal fallback verification

Version: `0.3.23.341-dev.373-NEG-CUSTOM-SCENARIO-LAUNCH-FIX`

- Explicit numeric item values are extracted from the target message only after the model retry still fails semantic completeness.
- Server fallback never invents a value: it only canonicalizes items whose title/unit token and numeric value are both present in the analyzed message.
- Multi-item explicit packages receive a stable `msg-{message_id}` bundle.
- An explicit confirmation becomes `acceptance_candidate` only when the other actor already has the same stored offer; otherwise it is safely stored as a proposal.
- Arbiter version: `neg-arbiter-1.7`.
