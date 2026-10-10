# 2.2.23

- Fixed a critical separation bug where a locationless City Channel Event selected by AI could appear as a real route stop even though it could not be mapped. Such current Events are now migrated to Serendipity and are never eligible for route/map stops until location is verified.
- City Channel AI candidates now allow nullable coordinates in the strict JSON schema, so the AI can truthfully return a high-confidence current Event whose venue is not yet verified.
- Added explicit `route_eligible` metadata to channel candidates.
- Serendipity normalization can preserve locationless discoveries while normal route-stop normalization rejects unusable coordinates.
- Improved City Channel audit accounting with locationless accepted counts.
- Preserved the geographic permutation route pre-optimizer and strengthened its route-ready guard.
- Development/test build; production launch still requires Stage 2 route/experience validation.
