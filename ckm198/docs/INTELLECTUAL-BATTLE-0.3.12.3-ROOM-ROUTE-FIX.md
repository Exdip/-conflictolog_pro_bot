# CKM Quiz Pro 0.3.12.3 — room route fix

Gameplay links no longer rely on generated WordPress Page permalinks.

Canonical runtime links now use `/?ckmqp_screen=play|host|scoreboard&...`.
`template_redirect` handles this route before the managed front door.
Legacy page-based links remain supported.

No Quiz Core or database schema changes.
