# 0.3.23.49 — Integrated Games Catalog

- Games Hub storefront moved into CKM Quiz Pro.
- One registry renders 7 cards in two groups: business and intellectual.
- Business order: Decision Price, Sales, Business Negotiations, Express Round.
- Intellectual order: Classic Quiz, Battle of Experts, Intellectual Battle.
- Decision Price is marked as flagship; Classic Quiz as simple entry.
- Three negotiation cards share the existing negotiation_duel entitlement/runtime.
- Added organizer cabinet view `view=games`.
- Added automatic `/games/` WordPress page with `[ckm_quiz_store]` for already-installed sites.
- Paid cards open the exact runtime via `ckm_format`; unpaid cards go to existing payment flow.
- No new MySQL tables and no duplicate gameplay engine.
