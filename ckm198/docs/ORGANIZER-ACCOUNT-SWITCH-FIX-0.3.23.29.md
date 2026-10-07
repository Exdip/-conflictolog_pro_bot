# 0.3.23.29 — organizer account switch fix

- `leogorn` is no longer treated in the UI as the organizer of every tenant merely because it is the current WordPress session.
- `/ckm-login/` offers an explicit “Войти другим организатором” action when another account is already logged in.
- Login fields use CKM-specific names and disable normal browser credential autofill to avoid pre-filling the saved owner account into every tenant login.
- The cabinet sidebar labels the current identity as `Аккаунт`, not as the tenant organizer.
- Tenant membership checks remain server-side; passwords remain WordPress-owned hashes and are never exposed by CKM.
