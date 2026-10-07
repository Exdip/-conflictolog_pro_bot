# NEG-EVALUATION-CRITERION-FALLBACK verification

Version: `0.3.23.341-dev.373-NEG-CUSTOM-SCENARIO-LAUNCH-FIX`

- Batch evaluation remains the fast path.
- If the batch response is malformed, incomplete or low-confidence, missing criteria are evaluated one by one.
- In a single-criterion fallback the server may restore an omitted `criterion_code` because the requested criterion is unambiguous.
- Author-facing rubric scales such as 0–5 and 0–10 are normalized to the internal 0–100 scale before validation.
- The canonical weak/limited/adequate/strong/excellent level is derived from the normalized numeric score; a mismatched model label no longer destroys an otherwise valid score.
- Confidence accepts 0–1 and 0–100 forms, while values outside those ranges remain invalid.
- Unparseable model output is treated as AI unavailability and retried/falls back by criterion rather than being mislabeled as a validator failure.
- Evaluation version: `neg-eval-1.4`.
