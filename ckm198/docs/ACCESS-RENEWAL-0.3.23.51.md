# CKM Quiz Pro 0.3.23.51 — access renewal

- Active game access shows the remaining days and expiry date.
- When 7 days or less remain, the organizer sees “Доступ скоро закончится”.
- A “Продлить на 30 дней” action is shown in the integrated games catalog, organizer home, and paid-games section.
- Renewal reuses the existing checkout/payment gateway; no new product or payment subsystem is introduced.
- A paid renewal adds 30 days after the furthest active expiry. Example: 12 days remaining becomes about 42 days after successful payment.
- Real payment callbacks use an idempotency grant reference so the same success webhook cannot extend access twice.
- Test-payment renewals are idempotent by the existing order_id + format_key primary key and also add 30 days after the current expiry.
- No new MySQL tables are created.
