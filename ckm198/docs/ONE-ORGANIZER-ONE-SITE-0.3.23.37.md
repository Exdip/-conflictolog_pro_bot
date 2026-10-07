# 0.3.23.37 — One organizer, one site

- Removed the frontend section “Площадки и организаторы”.
- Removed assigning, changing and inviting a separate organizer for a tenant.
- Registration now follows one account → one site.
- A newly registered organizer is automatically attached to the site created during registration.
- After registration and normal login, the organizer is sent to that site's subdomain.
- The site itself is available immediately; paid access still applies only to game formats.
- Legacy `/ckm-sites/` renders only “Моя площадка” and never exposes organizer assignment controls.
