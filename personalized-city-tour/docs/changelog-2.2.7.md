# Changelog — 2.2.7

## Google My Maps / KML pipeline
- Promoted public KML point features to first-class City Data-like virtual place records with stable IDs, coordinates, categories, tags, summaries and explicit KML source metadata.
- The exact KML context built by the server is now reused by the AI payload instead of being fetched/parsing a second time during the same generation.
- Added explicit `proposal_focus_interests` and interest-rotation state to the AI instructions.
- Added stronger AI rules telling the model to treat KML-derived virtual items exactly like City Data places and return their supplied `item_id` values.
- Added server-side repair/enforcement so the final proposals actually use the rotated interest and curated My Maps candidates instead of trusting the AI response alone.
- Expanded KML keyword classification to the plugin's real interest/category slugs (including cultural, social, nightlife and viewpoints).
- KML cache metadata now records the number of parsed map features.
- Invalid My Maps viewer URLs without a `mid` value now record a useful cache error instead of failing silently.

## Public tour presentation
- Removed the public "Planned visit time" and "Budget level" pills from Tour Details, as requested.
- Redesigned Suggested Stops as a cleaner compact fixed-height card carousel: thumbnail top-left, stop number top-right, title/time, concise tags, practical note and cleaner previous/next controls.
- Kept image thumbnails clickable to a large lightbox and fixed the lightbox Escape-listener cleanup.
- Removed the address from the compact stop card to reduce visual clutter; address remains available in the map/navigation context.

## Version
- Plugin version: **2.2.7**
