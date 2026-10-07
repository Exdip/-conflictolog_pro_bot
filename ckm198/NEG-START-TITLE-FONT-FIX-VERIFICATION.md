# NEG-START-TITLE-FONT-FIX .377

Version: `0.3.23.346-dev.378-NEG-GAME-SCREEN-LAYOUT`

Live `.376` verification showed the scenario title still inherited Caveat because the older `body.ckm-neg-app-document h1` rule had higher specificity and both declarations used `!important`.

`.377` raises only the scenario-title selector specificity to `body.ckm-neg-app-document .ckm-neg-app-main .ckm-neg-start-hero h1`, preserving Caveat for branding/accent headings while forcing the scenario H1 to Ubuntu. No gameplay logic or layout structure changed.
