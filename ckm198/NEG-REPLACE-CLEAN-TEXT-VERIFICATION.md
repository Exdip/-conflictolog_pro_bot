# NEG-REPLACE-CLEAN-TEXT verification

Version: `0.3.23.341-dev.373-NEG-CUSTOM-SCENARIO-LAUNCH-FIX`

- Distribution root is canonical `ckm198/`, so WordPress replaces the installed plugin instead of creating a second plugin directory.
- AI opponent, agreement reply and coach remove markdown asterisks before saving.
- Numbered list markers at line starts are converted to `- `.
- Session UI applies the same cleanup to historical messages/results.
- Internal engine data and scoring are unchanged.
