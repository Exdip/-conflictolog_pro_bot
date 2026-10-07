# NEG-EVALUATION-HUMAN-COPY verification

Version: `0.3.23.341-dev.373-NEG-CUSTOM-SCENARIO-LAUNCH-FIX`

- User-facing evaluation copy no longer exposes fallback/provider implementation details.
- Degraded/formal scores keep the same numeric calculation and evidence.
- New results store criterion-specific Russian explanations.
- Existing completed results with legacy technical text are humanized at render time without recalculation.
- Internal `source=php_fallback` remains available for diagnostics/API consumers but is not shown in the player UI.
