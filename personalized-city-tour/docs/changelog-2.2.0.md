# Personalized City Tour 2.2.0

- OTP browser notifications now fire immediately when the OTP is created, before the SMS/manual delivery path.
- Hardened administrator Web Push recipient discovery and added a browser-notification test action in My Trip, My Vibe.
- Automatic My Trip, My Vibe mode now reports automatic generation clearly when administrator approval is disabled.
- Downloading or successfully sharing a generated illustration now clears the verified-device session and returns the visitor to the OTP screen.
- OpenAI reference-photo generation now uses GPT Image 2.5 Sunburst for higher-precision edits and appends strict identity/group-preservation constraints, including exact people-count preservation.
- Google My Maps ingestion now retries KML endpoints, captures folders, matches by coordinates as well as names, extracts route-nearby city-item sequences, and promotes curated map matches into the AI candidate pool.
- AI tour instructions now explicitly preserve My Maps route order and use the supplied map URL as a web-search reference if server-side KML export is unavailable.
- Removed the “More suggestions” block from public tour option presentation.
- Reworked the admin checkbox groups for mobile screens so each checkbox is clearly associated with its label.
- Enabled automatic background generation of complete non-Persian UI translation bundles through the configured AI provider; generated bundle counts are shown in the language table.
