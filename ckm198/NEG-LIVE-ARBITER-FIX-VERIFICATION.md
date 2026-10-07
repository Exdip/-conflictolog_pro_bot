# NEG LIVE ARBITER FIX verification

Version: `0.3.23.341-dev.373-NEG-CUSTOM-SCENARIO-LAUNCH-FIX`

Live `.352` verification confirmed that builder-test rendering and the real AI opponent work, but both arbiter analyses returned `analysis_failed`. This build hardens the arbiter JSON parser for response shapes already known to occur through AI Puffer: BOM, fenced JSON, harmless surrounding prose and double-encoded JSON strings. Truncated JSON remains rejected.

The live game renderer also receives item/value label dictionaries and renders nested target/red-line structures recursively, so technical `Параметр: Параметр` labels are replaced with scenario item names and Russian boundary labels.

`ArbiterService` now returns a non-sensitive `failure_stage` (`context`, `transport`, `parse`, `validate`, or `apply`) when analysis fails, to make the next live check diagnostic without exposing provider content or credentials.
