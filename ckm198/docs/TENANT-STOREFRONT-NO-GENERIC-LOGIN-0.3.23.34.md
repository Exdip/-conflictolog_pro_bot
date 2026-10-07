# 0.3.23.34 — tenant storefront / no generic tenant login

- `https://tenant.ckkm.ru/` is always the game storefront, never a generic cabinet/login redirect.
- Removed the `Уже оплатили игру? / Войти в кабинет площадки` text and link from tenant roots.
- Storefront CTA is now `Оплатить`.
- Logged-in tenant organizers go directly to that game payment; logged-out buyers are asked to authenticate only for the selected purchase.
- Direct generic `tenant.ckkm.ru/ckm-login/` without purchase/return context redirects back to the tenant storefront.
- The tenant login flow no longer exposes the standard WordPress lost-password/admin surface; password recovery remains available from the central `ckkm.ru/ckm-login/`.
- Customer organizer accounts remain the `ckm_quiz_organizer` role; `/wp-admin/` continues to redirect organizer-only users to the frontend cabinet.
