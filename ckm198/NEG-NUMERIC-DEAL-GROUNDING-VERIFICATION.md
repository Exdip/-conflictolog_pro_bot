# NEG-NUMERIC-DEAL-GROUNDING verification

Version: `0.3.23.341-dev.373-NEG-CUSTOM-SCENARIO-LAUNCH-FIX`
Arbiter: `neg-arbiter-1.7`

- Ordered-list markers such as `1.` and `2.` are excluded from numeric deal extraction.
- Calendar-like dates are excluded from numeric deal extraction.
- Numeric AI deal updates require an explicit item/value anchor, a safe short-context continuation, or a valid acceptance of an existing offer.
- Explicit labeled packages remain supported, including values before or after item/unit wording.
- Short continuations such as `А если 35?` remain valid when the prior turn identifies exactly one negotiation item.
- Prompt explicitly forbids list numbers, dates, percentages and unrelated numbers as deal values.
