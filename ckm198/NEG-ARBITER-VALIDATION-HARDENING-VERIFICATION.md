# NEG Arbiter Validation Hardening Verification

Version: `0.3.23.341-dev.373-NEG-CUSTOM-SCENARIO-LAUNCH-FIX`

- Server target message/actor are authoritative; model envelope cannot redirect or block analysis.
- Confidence values in the common 0-100 representation are normalized to 0-1.
- Values outside both supported scales are still rejected.
- Arbiter state version: `neg-arbiter-1.2`.
