# CKM managed-sites infrastructure

Default deployment roles:

- `https://centr-razvitia-uma.ru` — public site, sales and CKM administration.
- `https://api.centr-razvitia-uma.ru/v1` — licenses, entitlements, content delivery, updates and future AI Cloud.
- `*.ckkm.ru` — managed customer game sites. Example: `acme.ckkm.ru`.
- `https://gateway.video-training.ru` — realtime voice/WebSocket gateway.

The root `video-training.ru` website is not required and is not used as an API dependency.

All defaults are filterable in WordPress:
- `ckm_quiz_pro_cloud_base_url`
- `ckm_quiz_pro_managed_sites_base_domain`
- `ckm_quiz_pro_gateway_base_url`
- `ckm_quiz_pro_primary_site_url`
