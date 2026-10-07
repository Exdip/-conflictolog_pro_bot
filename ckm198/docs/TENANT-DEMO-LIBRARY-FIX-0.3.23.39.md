# 0.3.23.39 — Tenant demo library fix

Fixes paid formats appearing in the organizer navigation but showing an empty **My games** / **Launch game** list on tenant subdomains.

Cause: the tenant-content migration shared only the older demo slugs. The new `demo-solution-price` and three `demo-negotiation-*` templates stayed `legacy` at tenant 0 and were filtered out by tenant library scope.

Fix:
- classify Solution Price and Negotiation Duel builtin demos as `tenant_id=0, content_scope=shared`;
- one-time repair for already-installed databases;
- negotiation seeder writes/repairs shared scope explicitly.
