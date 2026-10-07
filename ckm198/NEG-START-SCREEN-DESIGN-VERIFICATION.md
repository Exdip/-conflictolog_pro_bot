# NEG-START-SCREEN-DESIGN .375

Version: `0.3.23.343-dev.375-NEG-START-SCREEN-DESIGN`

Scope: redesign the Negotiation Master pre-start screen without changing negotiation mechanics.

Implemented:
- focused two-area desktop layout: briefing + launch controls;
- role/opponent grouped in scenario hero;
- dedicated negotiation task block;
- human-readable known facts without nested `Параметр` lists;
- compact target/red-line rows with item units;
- compact mode, difficulty and voice controls;
- existing runtime IDs/names preserved;
- responsive single-column layout on smaller screens.

Verification:
- `neg-start-screen-design-375-test.php`: 12/12 PASS;
- current `.349–.375` regression chain: 27/27 test sets PASS;
- PHP syntax: 336/336 PASS;
- JavaScript syntax: 13/13 PASS;
- archive root: `ckm198/` only.
