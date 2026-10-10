# 2.2.19

- Reworked City Channel fetch/cache diagnostics with versioned cache keys, HTTP status, cache-hit, fetch URL and per-source error reporting.
- Added bounded public source snapshots for AI channel research when structured extraction is incomplete.
- Strengthened OpenAI web-search context for configured public City Channel targets.
- Increased source diversity preference so different valid channel hosts can contribute across proposals without overriding relevance or date feasibility.
- Fixed AI Audit coverage to count the actual source matrix instead of the unused `accepted_by_source` field.
- Preserved strict date/time validation for scheduled events and stale-event rejection.
