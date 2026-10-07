# NEG-DIALOGUE-VIEWPORT verification

Version: `0.3.23.341-dev.373-NEG-CUSTOM-SCENARIO-LAUNCH-FIX`

Purpose: prevent the conversation history in Negotiation Master from collapsing to a narrow strip when the composer, coach and action buttons consume vertical space.

Expected layout:
- dialogue history has a visible minimum height on desktop/laptop/mobile;
- central dialogue column may grow beyond one viewport instead of clipping the history;
- composer and opponent header do not shrink;
- textarea is compact enough to preserve dialogue history;
- message history remains independently scrollable.
