# NEG-CUSTOM-SCENARIO-LAUNCH-FIX (.373)

- Custom scenario launch links use stable `neg_scenario_id`.
- Existing legacy percent-encoded tenant slugs remain resolvable as exact stored values.
- `sanitize_key()` is no longer applied to `neg_scenario`.
- New custom scenario slugs are ASCII-only and do not derive from localized titles.
- Unsafe byte truncation of percent-encoded Cyrillic slugs is removed.
- Source .372 archive is not modified.
