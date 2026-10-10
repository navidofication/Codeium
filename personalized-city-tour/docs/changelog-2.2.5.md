# Personalized City Tour 2.2.5

- Fixed the no-approval visitor flow: when administrator approval is disabled, the first click now asks for a deliberate second confirmation instead of showing the misleading generic error; the second click submits and generation starts automatically.
- Removed the synchronous WP-Cron loopback kick from automatic image approval. Requests are scheduled without making the visitor REST request depend on the hosting loopback configuration.
- The administrator's Prompt Template is now actually included in reference-photo edits, followed by a stronger photo-edit contract that keeps the uploaded people, composition, framing and proportions authoritative.
- Added a public-form Subject visibility switch.
- Removed public Name and Email fields from the My Trip, My Vibe form while retaining backward-compatible stored fields.
- Made Requests and SMS Outbox counts configurable and their admin tables independently scrollable.
- Browser notification clicks now open My Trip, My Vibe and anchor to SMS Outbox.
- Added persistent local Google My Maps KML/KMZ caching in uploads with a manual "Update KML database now" action on the City editor. Tour generation can continue using the last successful cache if Google is temporarily unreachable.
- Added My Maps cache status/staleness indicators and direct curated My Maps links on matched public tour stops.
- Improved Event selection for events that overlap a preferred time window even when the event starts before that window.
- De-duplicated repeated tour stops before curated-source enforcement so the same City Data place cannot fill an entire proposal.
- Preserved curated My Maps route ordering and plugin-built navigation URLs.
