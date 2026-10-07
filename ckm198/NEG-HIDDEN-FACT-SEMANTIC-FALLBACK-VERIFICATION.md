# NEG-HIDDEN-FACT-SEMANTIC-FALLBACK verification

- Adds conservative deterministic fact-update fallback when the arbiter model omits `fact_updates`.
- Player probes may create only `partial`, never `revealed`.
- Opponent text can promote to `revealed` only when it directly overlaps the hidden fact content/title strongly.
- Existing equal-or-stronger AI fact updates are preserved.
- Arbiter version: `neg-arbiter-1.7`.
- No DB/content migration.
