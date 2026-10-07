# NEG-ARBITER-SEMANTIC-RETRY verification

Version: `0.3.23.341-dev.373-NEG-CUSTOM-SCENARIO-LAUNCH-FIX`

- Explicit numeric values tied to known negotiation items may not silently produce an empty validated analysis.
- If the first model response omits one or more explicitly mentioned items, the normal second arbiter attempt is triggered with a stronger semantic instruction.
- The retry instructs the model to emit `proposed` or `acceptance_candidate` updates and a package event for multi-item offers.
- Discovery questions without explicit values are not forced into deal updates.
- No database or content version changes.
