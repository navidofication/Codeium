# Personalized City Tour — Administrator Guide (English)

Version 2.0.0 · Author: Rahimghazi.ir · Credits: RahimGhazi & Devin/R&D HumanAI

## 1. Installation

1. **Plugins → Add New → Upload Plugin**, choose `personalized-city-tour.zip`, install and activate.
2. Activation creates the custom roles (`pct_local_guide`), the AI-log and analytics tables, default categories, and schedules the WP-Cron jobs (log retention, analytics queue, Drive exports).
3. Put the shortcode on a page: `[personalized_city_tour]` (or `view="traveler"` / `view="guide"` to show only one entry point).
4. Requirements: WordPress 6.0+, PHP 7.4+, HTTPS (needed for browser geolocation in navigation mode).

## 2. Menu overview (**City Tour** in the admin sidebar)

| Menu | Purpose |
|---|---|
| Dashboard | Counts, recent tours, pending guides/events, hamburger panel for quick city selection |
| Cities | One post per city: description, centre/zoom, **City Channels**, Google Maps link, social links, curated Google My Maps (internal only) |
| City Data | Places (historical, cultural, food, …) with location, tags, price tier, accessibility, typical visit time, Google Maps link |
| Events | Time-boxed items with organizer, organizer mobile, event/organizer URLs, Jalali/Gregorian date pickers |
| Local Guides | Approve / revoke / remove guides (WordPress users **and** no-login mobile registrations), verification SMS outbox |
| Form Builder | Sections, fields, conditional rules, ordering, required flags |
| Generated Tours | Stored tours (title `Name — your personalized tour — City — Date`, permalink `NameCityDayNddmmyy`) |
| Planner | Rule based planner: if/then rules, weights, constraints, priority order |
| Analytics | Center for Statistics, Information & Analysis: access control, AI analysis request builder, job queue, dashboard, exports |
| My Trip, My Vibe | OTP settings, approval queue, vibes/styles/subjects, prompt template, job viewer, storage |
| AI Audit Log | Every AI call (provider, model, status, duration, redacted title; request/response with logging = full) |
| Settings | General · AI Integration · Maps & Routing · Google Drive · SMS · About |

## 3. Settings

### General
Default language (Persian by default), enabled languages, access (public vs. logged-in), whether submissions are stored, contact fields optional/required.

### Languages
English is the source language and Persian ships as a complete built-in bundle (`languages/pct-fa.json`, admin + frontend). For es/zh/ru/hi/ar upload a JSON bundle (`{"Source string": "Translation"}`) under **Settings → Languages**, or enable an online translation provider; missing strings fall back to English. Uploaded bundles override the built-in one.

### AI Integration
* **Provider**: none · Devin.ai · OpenAI · Azure OpenAI · Anthropic · custom OpenAI-compatible endpoint.
* **Model / deployment**: pick from the per-provider mapping or type a custom model name. Azure uses the deployment name.
* **API key**: stored encrypted (AES-256-CBC with `PCT_SECRET_KEY` or the site salts). Alternatively define constants in `wp-config.php`: `PCT_AI_API_KEY`, `PCT_GOOGLE_MAPS_API_KEY`, `PCT_MAPBOX_TOKEN`, `PCT_TRANSLATION_API_KEY`, `PCT_DRIVE_CLIENT_SECRET`, `PCT_SMS_API_KEY`, `PCT_IMAGE_API_KEY`. Constants win over stored values and are never shown.
* **Send city database to AI** – explicit consent. While off, the built-in planner answers every request.
* **Allowed city-data fields** – only the ticked fields are sent (contact fields excluded by default). **Share traveller name / contact** are separate opt-ins.
* **Logging level**: none · minimal (metadata + redacted title) · full (redacted request/response). Retention in days; purge button.
* **Sandbox / mock mode** – deterministic answers, no external calls (use it for QA and for the automated tests).
* **Limits & reliability** – global and per-visitor calls per hour, timeout, temperature, max database items, retry-once-with-schema-errors.
* **On-demand usage controls**
  * *Cache generated tours* (minutes): identical payloads (same city, answers, language, rules, model) reuse the cached plan. The traveller's name/contact never enter the cache key, and the final title/permalink are still personalised.
  * *Batching window* (seconds): duplicate payloads that arrive while one is in flight wait for that answer instead of calling the provider again; if none arrives in time the rule based planner replies.
  * The cache is invalidated automatically when a city, place, event or the planner rules change.
* **Extra prompt instructions** – appended to the system prompt.

### Maps & Routing
Tile provider (OSM/Mapbox/Google), routing provider (OSRM/Mapbox/Google/straight lines), search-page URL used by quick search hints, "show curated My Maps publicly" (off by default).

### Google Drive
OAuth client, folder mapping (city DB, submissions, tours, analytics exports), schedule, retention, sandbox (writes to `uploads/`).

### SMS
Provider (bulk-SMS API or *Manual* outbox), sender, admin mobile. Manual mode queues messages for you to send from your own phone and lets you mark them done.

## 4. Local guides without login

1. Visitor chooses **I am a local guide → Register**, gives name, **mobile (required)**, optional e-mail, city, languages.
2. A pending `pct_guide` profile is created; the device stores a token (no account, no login link in any message).
3. Admin gets an e-mail/SMS, calls the guide, and clicks **Approve** in *Local Guides*. The guide's device shows the new status on the next visit.
4. Approved guides can register events (organizer mobile, Jalali/Gregorian start/end, venue, coordinates, Google Maps link). Events stay **pending** until an editor publishes them (unless *auto-publish* is enabled for verified guides).

## 5. Frontend flow

1. Language selector (fa default) → **I am a traveler** / **I am a local guide** / **My Trip, My Vibe**.
2. Traveler: pick city → preference form (steppers, conditional sections, Jalali picker in Persian) → progress steps while the plan is generated → Day 1/2/3 menu.
3. Each day: title, summary, stops with tags/time/note, **Open in Google Maps** (stored city-data link), quick search hints (site search + City Channels), accessibility notes.
4. Map: **green** planned route (static), **blue** live route from the visitor to the tour start, trimmed behind the visitor and hidden after arrival except for the **Go to the tour start** button. Device-only saved places: parking, bus stop, metro, accommodation with *Go to* / *Remove*.

## 6. My Trip, My Vibe

Visitor: fee notice → OTP (SMS/e-mail) → photo upload + vibe/style/subject → progress → result with download/share.
Admin: OTP auto-generation, optional approval before sending, expiry/retry limits, presets, prompt template (`{style} {subject} {city} {vibe} {notes}`), job queue with sandbox/production, storage (local or Drive).

## 7. Analytics center

Grant roles under *Data access*. Build a request (dataset, period, breakdown fields, question), it is queued through WP-Cron, and the report page shows summary, insights, charts and tables with PNG/DOCX/PDF/XLSX export. PII and free-text fields are never aggregated or sent.

## 8. Troubleshooting

* *"Plugin file not found" on activation* – the ZIP must contain a top-level `personalized-city-tour/` folder; rebuild with forward-slash paths.
* *Tours always come from the planner* – check provider/key, city-DB consent, rate limit, and the audit log status column (`invalid`, `rate_limited`, `error`).
* *Navigation does not start* – the site must be served over HTTPS and the browser must allow location.
* *Persian dates rejected* – the picker sends `1404/06/30 10:30`; the server converts it to Gregorian. Ensure the language is `fa`.
