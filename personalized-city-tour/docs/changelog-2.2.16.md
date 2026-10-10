# Personalized City Tour 2.2.16

## Stage 1 — Candidate Intelligence foundation

- Expanded City Channel discovery from 4 to 8 administrator-configured public sources and increased the candidate budget to 40.
- Added generic public event-listing extraction for configured sources such as Eventro-style `/event`, `/events` and `/e/` pages.
- Improved public Telegram parsing path and event metadata handling without login/private-content access.
- Improved generic public metadata classification so event-like pages can become event candidates when a date can be established.
- Added per-source City Channel research diagnostics to AI Audit Log, including raw/accepted candidate counts per configured source.
- Preserved the configured channel label on accepted virtual candidates.
- Introduced a soft interest-weight profile: the top ranked travel interest dominates scoring, while secondary interests remain eligible as supporting/experience stops.
- AI instructions now explicitly treat interest focus as a soft blend rather than a hard category filter.
- Added interest weights and source-report metadata to `source_usage` for later route/experience stages.

## Architecture direction

This release deliberately does not yet replace the map route builder. Stage 2 will introduce route-aware composition and hard/soft constraint separation; later stages can add Google Maps discovery for route-support locations and a public “why this tour” experience.
