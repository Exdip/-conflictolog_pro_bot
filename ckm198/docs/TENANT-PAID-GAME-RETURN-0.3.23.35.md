# 0.3.23.35 — Tenant paid-game return

- Payment returns that land on `ckkm.ru` with `test_order` are redirected to the order's registered tenant subdomain before login/session checks.
- A successful one-format payment opens `https://<tenant>.ckkm.ru/ckm-organizer/?view=library&format=<paid-format>`.
- Multi-format payments open the tenant's game library.
- Access is still granted only after server-side payment verification; the early redirect uses the order UUID only to recover the tenant host.
- No payment-success route sends the buyer to the primary platform cabinet anymore.
