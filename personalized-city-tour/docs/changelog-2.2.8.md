# Personalized City Tour 2.2.8

- Fixed weak fuzzy matching that could incorrectly map a KML point to an unrelated City Data place.
- Every usable KML point is now always materialized as a stable City-Data-like virtual item, even when it also matches an existing City Data record.
- Added an explicit `curated_city_data_items` block to the AI payload so curated KML points are first-class selectable tour data.
- Strengthened the server-side invariant so available KML points are not silently omitted from generated tours.
- Removed legacy public Tour Details labels for Planned visit time and Budget level.
- Reworked Suggested Stops cards to a compact, balanced mobile-first layout without the previous fixed 250px blank area.
