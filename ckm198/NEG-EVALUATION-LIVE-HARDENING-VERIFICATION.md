# NEG-EVALUATION-LIVE-HARDENING verification

Version: `0.3.23.341-dev.373-NEG-CUSTOM-SCENARIO-LAUNCH-FIX`

Live defect addressed: a completed negotiation could create an evaluation row but fail before scores because the post-game AI evaluator still used a fragile JSON parser, accepted confidence only as 0..1, and could reject valid user-rubric scores returned on a 0..10 scale.

Changes:
- post-game AI uses the hardened balanced/double-JSON parser;
- confidence accepts 0..1 or 0..100 and normalizes internally;
- rubric-native scores are converted to the internal 0..100 scale only when the returned score does not already fit the declared level band;
- prompt explicitly separates user rubric scale from internal 0..100 score;
- retrying a failed evaluation updates `evaluation_version` to `neg-eval-1.3` and clears stale final result fields;
- failed evaluations store only a safe failure stage (`ai`, `validate`, `runtime`).

No DB schema or content-pack bump.
