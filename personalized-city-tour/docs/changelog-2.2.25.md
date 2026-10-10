# Personalized City Tour 2.2.25

## Routing correctness and diagnostics

- Normalize mobility values from the form (`walking`, `public_transport`, `bike`, `taxi`, `car`) into routing modes consistently.
- Do not silently treat public transit as a driving route on OSRM or Mapbox. Transit requires a provider that actually supports it.
- Record server-side routing success/failure metadata in AI Audit (provider, mode, HTTP status, stop count, distance/duration when available); raw coordinates are not included in these routing log details.
- Check HTTP status and routing payload before accepting an OSRM route.
- The public map no longer draws straight lines between stops as a fallback that could be mistaken for a navigable route. If routing fails, it displays an error status and keeps the stop markers visible without a misleading green route.
- Clarify that walking/cycling on OSRM require an OSRM deployment built with the corresponding profiles; the public OSRM demo commonly exposes driving only.
- Preserve plugin folder slug `personalized-city-tour` and bump the version to 2.2.25.

## Important limitation

This release improves route-mode correctness and failure visibility. It does not itself provision a walking/cycling/transit routing backend. The configured provider must support the requested mode; route-order optimization and mixed-mode itinerary routing remain separate engineering work and must be validated before launch.

- Added an optional Valhalla routing backend for real pedestrian, bicycle, car and multimodal-transit route geometry; endpoint is configurable and public demo fair-use limitations are disclosed in settings.
