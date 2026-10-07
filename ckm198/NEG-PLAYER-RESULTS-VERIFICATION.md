# NEG-PLAYER-RESULTS verification

Build: `0.3.23.313-dev.345-NEG-PLAYER-RESULTS`

- General raw JSON controls for player ideal result, target result and red lines are removed from the builder UI.
- Ideal result is editable per negotiation item.
- Item targets continue to use the normal controls introduced in `.344`.
- A per-item switch marks which player boundary is also a red line for the player summary.
- Legacy non-item result metadata and package-level conditions are preserved automatically during save.
- Database schema remains unchanged.
