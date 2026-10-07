# NEG-RELATIONSHIP verification

Build: `0.3.23.307-dev.339-NEG-RELATIONSHIP`

- `RelationshipService` stores only `tension` and actor-specific `credibility` in `state_json`.
- State transitions are driven by validated server events; free-form `dialogue_state.tension` does not directly mutate relationship state.
- Relationship events are audit events: `tension_increased`, `tension_reduced`, `credibility_weakened`.
- Opponent receives a safe internal projection and is explicitly forbidden to turn it into new constraints or reveal the status labels.
- Player does not receive live relationship gauges.
- Completed evaluation may show at most four evidence-backed relationship dynamics.
- No DB migration or content migration was added.
