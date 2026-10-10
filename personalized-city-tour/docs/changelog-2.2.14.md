# Personalized City Tour 2.2.14

## City Channel Intelligence becomes a first-class tour source

- OpenAI web-search generations now ask the model to research configured City Channels and return high-confidence `channel_candidates` in a strict structured schema.
- The plugin validates channel source URLs, date range, confidence, coordinates, and converts accepted discoveries into the same City-Data-like virtual stop shape used by My Maps/KML.
- Channel candidates receive stable plugin item IDs, coordinates, source links, categories/tags, duration, price tier and `channel_virtual` metadata before final planning.
- Server-side source enforcement guarantees that accepted City Channel candidates can enter the final stop list instead of remaining passive prompt context.
- For OpenAI + web search, remote channel fetching is skipped on a cold generation unless cached data exists; this avoids serial 5-second channel fetches before an already long AI request.
- Public Evand/Telegram/Instagram sources do not require administrator login for public content. Private/login-only content is not accessed.
- Existing Google My Maps/KML and Event source-enforcement behavior is preserved.
