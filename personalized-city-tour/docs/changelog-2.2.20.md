# 2.2.21

- Fixed City Channel date-window normalization so Jalali form dates are converted through PCT_I18n::parse_date before expiry checks.
- Added direct-link enrichment for public event listing candidates, allowing Eventro-like pages to expose date, venue and JSON-LD data from the actual event page.
- Public channel candidates without coordinates are retained as pending enrichment instead of being discarded before AI research; physical route selection still requires verified coordinates.
- Added per-source rejection reasons and route-ready counts to City Channel research diagnostics.
- Added a mandatory per-source AI research contract (`channel_research`) and explicit research tasks for every configured City Channel; the model is instructed to inspect each source and use web search for incomplete public Telegram/Instagram pages.
- Added public social-profile place extraction for strongly place-like Instagram/Facebook/Threads pages when the public page contains city-specific place information.
- Versioned the City Channel cache to prevent stale parser results from 2.2.19.
- Expanded AI Audit Coverage to show accepted/raw/sources/researched/route-ready/rejected/used counts.
- Expired events remain hard-rejected and cannot enter the final itinerary.
