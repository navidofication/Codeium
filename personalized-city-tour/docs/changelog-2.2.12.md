# 2.2.12

- Added optional Augmented Reality service links and labels to Cities, City Data places and Events. A stop uses its own AR URL first and the city-level AR URL as a fallback.
- Reworked public Suggested Stops into compact cards with a small image, fixed bottom actions for Web search / Photos / Augmented reality, and a collapsible guide-style description.
- Removed the old bottom carousel controls and `2 / 2` status; previous/next arrows now float at the two sides of the card and mobile touch-swipe is supported.
- Added public City Channel intelligence: only administrator-configured public channel URLs are fetched, cached and parsed for structured events/public Telegram posts and page metadata. High-confidence candidates can become City-Data-like virtual stops, with date/location evidence, source URL and optional public geocoding.
- City Channel candidates are secondary enrichment. They never replace authoritative City Data, Events or Google My Maps, and the AI is instructed not to treat unverified channel text as fact.
- Added an admin setting to enable/disable City Channel intelligence. It is enabled by default.
- Existing KML/My Maps, Events, AI planning, route generation and 2.2.11 mobile REST timeout behavior are preserved.
