# NEG-EVALUATION verification — dev.325

Target ZIP: `ckm-quiz-pro_0.3.23.293-dev.325-NEG-EVALUATION.zip`

## Static/runtime checks

- 267 PHP files: syntax OK.
- `neg-evaluation-325-test.php`: 29/29 PASS.
- `neg-evaluation-ai-validator-test.php`: 2/2 PASS.
- `neg-evaluation-calculator-test.php`: 5/5 PASS.
- Negotiation-session JS: `node --check` PASS.
- ZIP integrity to be checked after packaging.

## Root regression sweep

128 root PHP test files were executed after the dev.325 changes:

- 55 PASS
- 73 FAIL

The remaining FAILs are legacy exact-version / historical schema-version marker assertions from earlier build-specific tests. The fail log contains no new non-version functional assertion from the NEG-EVALUATION implementation.

## Important limits

This build has not been installed on the live WordPress/MySQL site and has not yet been exercised against the live AI Puffer transport. SQLite end-to-end DB simulation is not used for this build. The first live verification should therefore cover evaluation-table writes, AI evaluation response parsing, completed-session result UI and retry after an intentionally failed AI evaluation.
