# NEG-REOPEN verification

Build: `0.3.23.306-dev.338-NEG-REOPEN`

- Item-level mutual acceptance creates `status=agreed`.
- Agreed terms cannot be silently overwritten.
- `never`, `with_reason`, `free_until_final`, and `explicit_mutual_confirmation` are supported server-side.
- Pending mutual reopen keeps the old agreed value until the counterpart confirms.
- Player-safe snapshot exposes only visible reopen state.
- No DB or content migration is required: DB `1.8.0`, content `1.4.0`.
