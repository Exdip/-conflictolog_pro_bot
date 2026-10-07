# NEG-LIST-NUMBER-HARD-GUARD verification

Version: `0.3.23.341-dev.373-NEG-CUSTOM-SCENARIO-LAUNCH-FIX`

- Arbiter version: `neg-arbiter-1.7`.
- Ordered-list markers such as `1.`, `2.`, `3.` are excluded before numeric deal grounding.
- The guard is UTF-8 safe and does not truncate the tail in the middle of a multibyte character.
- The exact live numbered-list failure from session 9 is covered by regression test.
- Genuine grounded values such as `40 дней` and `960 000 рублей` remain supported.
- Dates and percentages remain excluded from unrelated deal items.
