# Navigation Architecture 0.3.23.53

Customer-facing architecture: Деловые игры / Интеллектуальные игры / Создать свою игру.

- Public first screen and primary navigation use the three paths.
- `/games/` is rendered by CKM itself, avoiding legacy theme navigation.
- Tenant storefront gets the same navigation.
- Organizer sidebar and overview use the same three paths; payment/library/results are utility sections.
- Duplicate `Игры` and `Запустить игру` entries are removed from the organizer menu.
- WordPress admin labels are explicitly technical.
- Legacy default interface copy is migrated only when it was not manually customized.
- No new database tables or schema changes.
