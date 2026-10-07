# CKM Cloud Entitlements v1

Client endpoint contract:

`POST /v1/catalog/entitlements`

Request: `license_key`, `plugin_slug`, `plugin_version`, `domain`, `installation_id`, `request_nonce`.

Response is an Ed25519 signed envelope with schema `ckm.entitlements.v1`. The signed payload contains `license_id`, `domain`, `installation_id`, `issued_at`, `valid_until`, and `packages[]`. Each package contains a stable `package_id`, `package_version`, `title`, `format_key`, entitlement status, delivery mode and package URL.

The client must verify the signature, domain, installation id, nonce and validity window before updating its local entitlement cache. A revoked or absent entitlement must never be treated as purchased. Package delivery remains separately protected by the signed `.ckmgame` content package.
