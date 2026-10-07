# NEG-RULE-FIELDS verification

Version: `0.3.23.316-dev.348-NEG-RULE-FIELDS`

- Visible `condition_json` and `action_json` editors removed from the negotiation scenario builder.
- Conditions are edited as item comparisons, ALL/ANY groups, game events, or repeated hard-boundary violations.
- Actions are edited as red-line flags, requirements, alternative requirements, required items, blocks, opponent walkaway, or explicit confirmation.
- Hidden JSON fields remain the storage/transport contract, so the runtime schema is unchanged.
- Unsupported future/legacy structures stay preserved until the organizer intentionally replaces them with a supported rule.
- Builder rule types now include the canonical types used by all system scenarios.
- Current system content: 78/78 rule conditions and 78/78 rule actions fit the structured editor.
