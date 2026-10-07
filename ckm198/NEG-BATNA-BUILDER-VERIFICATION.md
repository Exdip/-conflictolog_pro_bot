# NEG-BATNA-BUILDER verification

Build: `0.3.23.311-dev.343-NEG-BATNA-BUILDER`

- DB schema stays `1.8.0`.
- Content pack advances to `1.5.0`.
- Player alternative is edited with Russian description + optional 0–100 valuation fields; raw JSON is no longer exposed for this field.
- Existing extra keys in `player_alternative_json` are preserved by the builder round-trip.
- `contract-supply` becomes immutable published version 2 with formal alternative value 65/100.
- Generic content migration now imports the declared published version without overwriting earlier published versions and refuses version downgrade.
