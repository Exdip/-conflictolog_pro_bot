# Negotiation Answer Box Fix — 0.3.23.40

Fixes the participant room for `negotiation_duel`.

Before: `standalone-game.js` treated `negotiation_duel` as a classic multiple-choice quiz, cleared the options list and hid the text answer box.

After: while a negotiation question is open, the participant sees a textarea with **Отправить реплику**. Existing answer submission and negotiation auto-review/auto-close backend paths are unchanged.
