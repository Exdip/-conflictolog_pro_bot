# CKM Quiz Pro — Signed API contract (dev.87.2)

## Envelope

CKM Cloud returns JSON:

```json
{
  "schema": "ckm.license-response.v1",
  "key_id": "...",
  "payload": {},
  "signature": "base64url-ed25519-signature"
}
```

The Ed25519 message is canonical JSON of exactly these three fields:

```json
{"key_id":"...","payload":{},"schema":"..."}
```

Canonicalization rules:
- recursively sort object keys lexicographically;
- preserve array order;
- UTF-8 JSON;
- no whitespace;
- unescaped Unicode and `/`;
- `signature` is NOT part of the signed message.

## license-response

Schema: `ckm.license-response.v1`

Minimum signed payload:
- `license_id`
- `status`: `active`, `expired`, `suspended`, etc.
- `plan`
- `domain`
- `installation_id`
- `request_nonce`
- `issued_at` (UTC ISO-8601)
- `valid_until` (short response validity window)
- `expires_at` (commercial license expiry)
- `features` object

The plugin rejects a valid signature if domain, installation_id or request_nonce do not match the current request, or if the signed response is stale.

## update-manifest

Schema: `ckm.update-manifest.v1`

Minimum signed payload:
- `plugin_slug`: `ckm-quiz-pro`
- `version`
- `package_url` (HTTPS)
- `sha256` of the ZIP, lowercase/uppercase hex accepted
- `issued_at`
- `valid_until`
- `request_nonce` for live Cloud responses
- optional `min_php`, `requires_wp`, `tested_wp`, `release_notes_url`

The plugin first verifies Ed25519, then WordPress may download the package. Before installation the downloaded ZIP is checked against the signed SHA-256. A mismatch aborts the update.

## Key rule

Private Ed25519 keys belong only to CKM Cloud and must never be shipped in a WordPress ZIP. The dev preview contains only a development public verification key and signed static fixtures for self-test. Replace the dev key with a production public key before commercial release.
