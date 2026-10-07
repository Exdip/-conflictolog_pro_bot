# 0.3.23.33 — Tenant Payment Frontdoor

- `ckkm.ru/` remains the only public platform homepage.
- The root of every registered tenant subdomain (`name.ckkm.ru/`) is no longer the generic platform homepage.
- Logged-out visitors to a tenant root see the game catalog with prices and `Войти и оплатить`.
- After login, the selected game is preserved and the organizer opens the payment screen for that tenant.
- A logged-in organizer opening the tenant root is sent directly to `ckm-organizer/?view=payment`.
- Tenant creation is still immediate; payment gates game formats, not the existence of the site.
