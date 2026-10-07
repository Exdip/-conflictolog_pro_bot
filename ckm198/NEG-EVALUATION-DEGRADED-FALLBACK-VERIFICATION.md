# NEG-EVALUATION-DEGRADED-FALLBACK verification

Version: `0.3.23.341-dev.373-NEG-CUSTOM-SCENARIO-LAUNCH-FIX`

- AI criteria are requested one-by-one with compact responses.
- Model self-reported confidence is metadata and no longer discards an otherwise valid score.
- `result_quality` has a formal package-vs-target fallback.
- Hybrid criteria fall back to their PHP component when AI is temporarily unavailable.
- AI-only criteria still fail rather than inventing a score.
- Evaluation version: `neg-eval-1.5`.
