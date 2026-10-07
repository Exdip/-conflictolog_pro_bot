# NEG-GAME-SCREEN-LAYOUT 378 verification

- Fixes the responsive negotiation game grid so hidden side panels no longer reserve desktop columns below 1080 px.
- Dialogue becomes the sole full-width game column on compact screens; task/state panels open below it through the existing mobile controls.
- Removes horizontal overflow from the application shell.
- Mobile tool controls are compact auto-width buttons instead of full-width bars.
- Top navigation links, including “Мастер переговоров”, use normal font weight and an identical fixed height.
- No negotiation mechanics, state transitions, arbitration, agreement or evaluation logic changed.
