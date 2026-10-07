# CHGK review card — 0.3.23.61

Participant UI only. The review card is rendered only for `chgk` after server state `questionFlow.answerRevealed=true`. It uses already-authorized state fields: `yourAnswer`, revealed `question.correctAnswers`, `question.explanation`, and the participant team score. No new API or DB migration.
