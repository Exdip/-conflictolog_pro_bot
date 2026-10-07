# NEG-ITEM-GOALS verification

Build: `0.3.23.312-dev.344-NEG-ITEM-GOALS`

- Raw JSON textareas for item targets/boundaries are no longer visible in the builder.
- Stored data contract is unchanged: `player_target_json`, `player_boundary_json`, `opponent_target_json`, `opponent_boundary_json`.
- Numeric and categorical values are converted back to the existing JSON contract before save.
- Existing unknown JSON keys survive the builder round-trip.
- `higher`/`lower` template values render as the canonical Russian preference choices.
