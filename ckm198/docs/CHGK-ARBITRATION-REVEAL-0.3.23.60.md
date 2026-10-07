# CHGK arbitration → reveal

Version `0.3.23.60-dev.92-CHGK-ARBITRATION-REVEAL`.

- Adds `chgk_answers_closed`.
- Keeps correct answers private during arbitration.
- Adds explicit `chgk_answer_revealed`.
- Human host gets **Показать правильный ответ** only after arbitration resolves.
- Next question is blocked until reveal.
- AI host reveals automatically and holds the reveal for at least 5 seconds before advancing.
- No database migration.
