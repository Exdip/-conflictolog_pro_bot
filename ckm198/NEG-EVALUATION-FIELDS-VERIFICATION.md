# NEG-EVALUATION-FIELDS verification

Version: `0.3.23.341-dev.373-NEG-CUSTOM-SCENARIO-LAUNCH-FIX`

## Scope
- Removed visible `rubric_json` and `config_json` editors from evaluation criteria.
- Added Russian fields for rubric description, scale, anchors, evidence/runtime metadata and hybrid PHP share.
- Preserved hidden JSON storage contracts and unknown legacy keys.
- Added `rubric` to builder-compatible evaluation types so the system scenario `contract-supply` can be copied and saved without changing runtime source-type fallback.
- No database or content-pack migration.

## Compatibility
System content pack `1.5.0` contains 88 evaluation criteria across 13 scenarios.
- 82 criteria use five named anchors (`weak`, `limited`, `adequate`, `strong`, `excellent`).
- 6 criteria use numeric anchors (`0`, `5`, `10`) on a 0–10 scale.
- Evaluation types present: `ai`, `hybrid`, `rubric`.
- Known config keys: `evidence_required`, `runtime_enabled`, `php_share`.
- All 88 rubrics and all 88 configs are representable by the new editor.

## Focused checks
Run:
`php tests/neg-evaluation-fields-349-test.php`
Expected: `34/34 PASS`.
