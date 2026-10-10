# 2.2.22

## Architecture alignment pass

- Keeps 2.2.21 Serendipity handling for current Events without verified coordinates.
- Adds explicit stop roles: core, supporting, experience, mobility, comfort.
- Replaces nearest-neighbour fallback route ordering with exhaustive geographic permutation pre-optimization for normal 3-4 stop half-day tours.
- Preserves curated Google My Maps route order as an authoritative exception.
- Exposes all traveller-selected mobility modes alongside the active route mode so the frontend can distinguish single-mode from multi-mode preferences.
- Marks the generated route strategy in the tour result for auditability.

## Not claimed as complete

- Live Google Maps/Places Discovery remains a later stage.
- True mixed-mode road/transit optimization and provider-side travel-time matrix optimization remain later-stage work.
- Public production readiness still requires live source acceptance, route tests and cross-device regression tests.
