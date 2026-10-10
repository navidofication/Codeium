# 2.2.21

## City Channel Events → Serendipity

- Current City Channel Events are no longer discarded only because a public source does not expose a reliable location.
- AI-discovered Events with a valid date/time and configured-source provenance are accepted even when coordinates/address are unavailable.
- Such Events receive `location_status=needs_location_enrichment` and `serendipity_eligible=true`.
- Route/map selection remains strict: an Event without verified coordinates is never inserted as a navigable route stop.
- Added server-side Serendipity evaluation for current, date-matched locationless Events using interest relevance, source confidence and current-source provenance.
- Each proposal may expose up to two Serendipity discoveries with a clear confirmation note; these are informational/discovery items, not route stops.
- Added public and admin rendering for Serendipity discoveries.
- Extended the AI JSON schema/prompt so the model can explicitly evaluate current locationless Events in `serendipity` instead of dropping them.
- Added source usage counters for available Serendipity candidates and proposals containing them.
