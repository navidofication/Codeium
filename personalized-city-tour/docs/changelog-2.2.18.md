# 2.2.18

- Added a strict scheduled-event gate: event candidates must have a valid start date and must overlap the requested travel window; already-finished events are rejected.
- Applied the same expiry rule to local City Channel events, AI-discovered channel events, built-in event selection and interest-focus repair.
- Added a public social-page fallback for Instagram/Facebook/Threads metadata and visible public text when it contains a dated event.
- Added City Channel source diversity across proposals so one source (for example Evand) no longer monopolizes all channel-derived selections when other configured sources provide valid candidates.
- Expanded City Channel Audit response with explicit per-source rows and source_count so the admin coverage column can report actual source coverage reliably.
