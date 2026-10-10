# Personalized City Tour 2.2.4

- Fixed the internal plugin version/cache namespace so the My Maps parser cannot reuse 2.2.2/2.2.3 transients after the route-import fixes.
- Reference-photo generation now uses a photo-specific edit prompt that ignores conflicting singular subject labels, preserves the original aspect ratio with GPT Image 2.5-compatible dimensions, and keeps all photographed people as the source of truth.
- Date-matched Events are retained in the AI candidate pool even when their score would place them outside the normal item cap.
- Curated My Maps route metadata is attached to matched City Data points as well as virtual points.
- Tour generation now selects consecutive segments from curated My Maps routes, inserts multiple route points into proposals, preserves curated route order, and then builds the final Google Maps route from those ordered coordinates.
- Added additional public My Maps KML endpoint variants and route/source usage metadata for verification.

Previous 2.2.x fixes remain unchanged.
