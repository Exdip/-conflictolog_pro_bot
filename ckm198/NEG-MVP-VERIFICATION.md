# NEG-MVP verification

Build: `0.3.23.295-dev.327-NEG-MVP`

## Scope

This build adds two system scenarios to the existing Negotiation Master runtime without adding a scenario-specific service:

1. `contract-supply` — existing commercial/numeric scenario.
2. `project-under-pressure` — deadline/scope/quality/resources.
3. `difficult-colleague` — organizational workload/responsibility negotiation without money.

NEG DB remains `1.4.0`; NEG content advances to `1.2.0`.

## Generic runtime changes

- `ScenarioRepository::firstPublishedSystem()` removes the hard-coded default scenario from the public page.
- `AgreementValidator` accepts a generic data-defined `block` rule action for impossible package combinations.
- `RuleEngine` emits a generic hard-constraint/dependency signal from the same `block` action.
- `EvaluationService` classifies completed agreements from red-line state and the final weighted score, so new scenario criteria do not need contract-specific criterion codes.

## Content expectations

- scenarios: 3
- scenario_versions: 3
- items: 15
- hidden_facts: 12
- rules: 18
- evaluation_rules: 18
- evaluation weight: 100 per scenario

## Non-regression boundary

No changes are required in legacy quiz/game/payment/STT/voice/organizer mechanics. The new scenario names/slugs belong only in content fixtures/tests/documentation, not runtime branching.
