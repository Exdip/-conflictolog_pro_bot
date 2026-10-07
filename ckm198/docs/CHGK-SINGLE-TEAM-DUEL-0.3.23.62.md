# CHGK single-team duel — 0.3.23.62

When exactly one active team is present in `chgk`, state exposes `singleTeamDuel`. Experts score is the existing authoritative team score. Game score is derived from revealed CHGK questions whose final outcome is rejected/incorrect/no-answer. This keeps manual re-scoring and appeals self-correcting and requires no DB migration.

Standalone participant/host/scoreboard UI renders two score cards: `Знатоки` and `Игра`. The review card reports `+1 Знатокам` or `+1 Игре`. AI host no longer reads the correct answer on the pre-reveal `question_closed` event; it announces the answer and duel score only on `answer_revealed`.
