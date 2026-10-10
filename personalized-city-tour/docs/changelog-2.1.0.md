# 2.1.0

- Fixed multilingual Vibe choice labels and frontend language switching for dynamic options.
- Moved My Places above the interactive tour map.
- Removed Style, Priority and Preferred time from Tour Details.
- Added a compact Suggested route (3–4 short stops) summary with official-link / Google Search fallback, tags, time ranges and notes.
- Added OpenAI Responses API web-search enrichment for tour planning (optional setting, enabled by default for OpenAI).
- Strengthened Google My Maps KML parsing with route/line geometry context for route planning.
- Added tile-server fallbacks for public/admin maps and the local-guide location picker.
- Made City Channels and Google My Maps repeaters mobile-safe in WordPress admin.
- Fixed OpenAI image generation to use GPT Image 2.5 models and the image edits endpoint when a visitor photo is supplied.
- Improved automatic Vibe generation kick-off and added one-successful-generation-per-verified-mobile enforcement.
- Added image click-to-save/open, download and file sharing affordances, plus automatic device-session clearing after a successful image appears.
- Added additional AI audit logging for tour fallbacks and AI failures.
