# 2.2.15

- Fixed City Channel integration so public channel candidates are prefetched and promoted into the same first-class City-Data-like candidate pool used by the planner.
- Added dedicated public Evand city-page extraction for `/events/` links, including Persian Jalali date/time parsing.
- Changed City Channel virtual IDs to positive stable IDs so they are valid in the AI stop schema.
- Added explicit `city_channel_research` context to the AI payload.
- Added City Channel source-usage counters and a dedicated `channel` entry in AI Audit Log; channel research is excluded from AI-request statistics.
- Preserved the existing privacy boundary: only administrator-configured public URLs are fetched; no login, password, private content or access-control bypass is used.
