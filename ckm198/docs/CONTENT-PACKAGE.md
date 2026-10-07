CKM Game Package v1
===================

A .ckmgame file is a ZIP archive containing:
- manifest.json
- quiz.json
- rounds.json
- questions.json
- hashes.json
- signature.json (optional for local/manual packages; required for official CKM Cloud distribution later)

Integrity:
hashes.json stores SHA-256 for quiz.json, rounds.json and questions.json. manifest.json binds content_sha256.

Authenticity:
When signature.json is present it is an Ed25519 signed envelope with schema ckm.content-package.v1 and binds package_id, package_version, content_sha256 and manifest_sha256.

Update semantics:
package_id is stable across revisions. Re-importing a newer package_version creates a new quiz revision in the same local quiz, preserving previous game snapshots/history.

Security note:
local_unsigned packages are accepted only through the administrator's manual import screen. Future CKM Cloud delivery should require ckm_signed trust.
