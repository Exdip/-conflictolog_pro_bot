# CHGK first to six — 0.3.23.63

Product contract:
- exactly one active team;
- Experts vs Game;
- accepted answer = +1 Experts; rejected/no answer = +1 Game;
- first side to 6 wins immediately;
- at least 11 active questions are required so a 5:5 match can be decided;
- no multi-team ranking or CHGK tie-break is used.

The restriction is enforced in template normalization, room creation, preflight and organizer UI. No DB schema migration is required.
