# NEG-LIVE-E2E-FIX verification

Version: `0.3.23.341-dev.373-NEG-CUSTOM-SCENARIO-LAUNCH-FIX`

## Live defect reproduced on .351
A published builder scenario validated and launched successfully, and `/sessions/{id}/resume` returned a valid snapshot, but the browser player area was blank. The special builder-test render path did not initialize `$itemLabels` / `$valueLabels`, although the pre-start renderer always consumed them. The assignment path had the same latent defect.

## Fix
- Build item and select-value labels after launch-path selection, so catalog, builder-test, and assignment sessions share the same renderer inputs.
- Treat `player_known_facts_json` arrays of `{title, content}` objects as first-class editable known-fact cards.
- Preserve extra keys on those fact objects and preserve genuinely complex legacy blocks unchanged.

## Expected live retest
Create/save/validate/publish/test a builder scenario, open `?neg_test_session=<id>`, verify the negotiation UI renders and the session resumes. A normal `{title,content}` known-facts array must not show the legacy-data warning.
