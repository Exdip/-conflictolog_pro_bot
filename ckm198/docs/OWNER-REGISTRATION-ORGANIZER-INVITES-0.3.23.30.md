# CKM Quiz Pro 0.3.23.30 — owner registration + organizer invitations

- `/ckm-register/` now separates the site name, subdomain slug, owner login, owner email and owner password.
- A logged-out registration creates the WordPress owner account and tenant, signs the owner in, and opens `Площадки и организаторы`.
- A signed-in owner can add another site without creating a second account or password.
- New organizer emails create an organizer account only as part of a successful tenant assignment, then send a one-time set-password invitation.
- Existing organizers are attached to the tenant and receive a login email.
- CKM never stores or displays plaintext passwords.
- Owner membership is preserved when the designated organizer changes.
