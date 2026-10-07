# CKM Quiz Pro 0.3.12 — Интеллектуальный батл

Technical key: `jeopardy`; shared runtime: `jeopardy_v1`.

Standalone UI exposes the existing shared Quiz Core mechanics without modifying Core:

- 4 categories × values 100/200/300;
- selector team chooses the next available cell;
- normal questions use first-to-buzz flow; wrong answer = 0 points, no penalty, other teams may buzz;
- `cat_in_bag` is shown to users as «Секретная передача» and gives one selected target team exclusive answer rights;
- optional final is fixed +500, no wagers, hidden answers until reveal;
- both `ai` and `human` host modes are supported; human-host microphone transport remains the existing Gateway path.

The standalone DEV arbiter is intentionally deterministic: reference/accepted-variant matching only. Protected semantic arbitration and methodologies stay server-side in CKM Cloud.
