# CKM Frontend Organizer v1

CKM Quiz Pro can run in a managed-site model where customers never receive WordPress administrator access.

## Roles

- Site owner / CKM administrator: WordPress `administrator`.
- Customer: `ckm_quiz_organizer` with capability `ckm_quiz_organize`.
- Organizer users are redirected away from `/wp-admin/` to the frontend cabinet.
- The WordPress admin bar is hidden for organizer-only users.

## Frontend routes

- `/ckm-login/` — customer login.
- `/ckm-organizer/` — customer cabinet.

The cabinet exposes only product workflows:

- My Games
- Start Game
- Results
- Constructor (optional per user)

## Constructor entitlement

The constructor is not treated as the protected intellectual property boundary. It may be enabled per organizer account. The protected value remains server-side: Cloud AI, prompts, MIND/SMKM methodologies, arbitration, analytics, licensed content and updates.

## Security boundary

Organizer users do not have `manage_options`, plugin management, database access, theme editing, server settings, license administration or CKM Cloud secrets.


## 0.3.8 permission fix
- The frontend constructor requires an explicit per-user entitlement flag.
- WordPress administrators no longer receive frontend constructor access implicitly.
- Administrators retain backend quiz editing through manage_options.
- The managed site root renders a CKM frontdoor; organizer-only users are redirected to their frontend cabinet.
