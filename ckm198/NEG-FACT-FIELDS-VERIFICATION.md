# NEG-FACT-FIELDS verification

Version: `0.3.23.315-dev.347-NEG-FACT-FIELDS`

## Scope

- Replaced the visible `player_known_facts_json` editor with ordinary known-fact rows.
- Replaced visible hidden-fact `reveal_rules_json` with Russian fields for partial reveal, full reveal, automatic reveal, and initial visibility.
- Kept existing database JSON contracts unchanged.
- Preserved non-scalar/nested legacy known-fact blocks and unknown reveal-rule keys across edit/save.
- No schema or content-pack migration.

## Compatibility

System content contains 13 scenarios and 52 hidden facts. All 52 hidden facts use the supported `partial`, `revealed`, `automatic_reveal` disclosure shape. The two nested known-fact blocks in `contract-supply` are preserved automatically instead of being flattened destructively.
