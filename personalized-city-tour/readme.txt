=== Personalized City Tour ===
Contributors: rahimghazi
Tags: travel, tours, maps, events, ai
Requires at least: 6.0
Tested up to: 6.6
Requires PHP: 7.4
Stable tag: 2.2.26
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

City databases, local-guide event registration, a travel preference form builder, a rule based planner and AI-generated multilingual personalized tours with online route maps and navigation. Author: Rahimghazi.ir. Credits: RahimGhazi & Devin/R&D HumanAI.

== Description ==

**Admin**

* "City Tours" menu with a hamburger drawer for Settings, Form builder, Local guides and the active-city selector.
* City database as custom post types: Cities, Places (historical, cultural, social, artistic, …), Events (with organizer + public event page links) and generated Tours.
* Google My Maps links per city (shown to travelers, embedded when possible).
* Local guide role with application / approve / revoke / remove workflow. Guides only edit their own records; publishing is optional (`Auto-publish guide submissions`).
* Visual form builder for the travel preference form: 12 field types, sections, conditional logic (`equals`, `contains`, `not_empty`, …), JSON import/export.
* Access control: public or logged-in travelers.
* AI providers: OpenAI / ChatGPT, Azure OpenAI (Copilot stack), Anthropic or any OpenAI-compatible endpoint. Rule-based planner is used as a fallback (or when no provider is configured). Personal contact fields are never sent to the AI.
* Map & routing: configurable routing provider; only validated provider geometry is drawn as the planned route. OSRM profile support depends on the configured server; Google Routes can provide transit when correctly configured. Every day gets an online route map plus "Open in Google Maps / OpenStreetMap" links.

**Frontend** – shortcode `[personalized_city_tour]` (attributes: `view="both|traveler|guide"`, `city="ID"`).

* "I am a traveler": pick a city → multi-step preference form → tour options in a Day 1 / Day 2 / Day 3 hamburger menu. Each day has a title, summary, suggested stops (tags, times, notes), alternatives, accessibility notes, quick search hints and a route map with **Preview** and **Navigate** (live location) modes.
* "I am a local guide": apply for the guide role, register events with organizer info and a map-picked location, manage own events.

== Installation ==

1. Upload the `personalized-city-tour` folder to `/wp-content/plugins/` and activate.
2. Go to *City Tours → Settings* and configure access, AI provider and maps.
3. Add cities, places and events under *City Tours*.
4. Place `[personalized_city_tour]` on a page.

== REST API ==

Namespace `pct/v1`: `GET /cities`, `GET /form`, `GET /i18n`, `POST /route`, `POST /tours`, `GET /tours/{id}?token=`, `GET|POST|DELETE /guide/*`, `POST /vibe/otp`, `GET|POST /vibe/requests`, `GET /geocode?q=`.

See `docs/admin-guide.en.md`, `docs/admin-guide.fa.md`, `docs/ai-prompts-and-json.md` and `docs/migration-notes.md`.

== Changelog ==

= 2.2.26 =
* Route generation now publishes a canonical stop order shared by cards, map markers, external map links and provider geometry.
* Valhalla routing is wired into the provider switch and new map settings default to Valhalla without falling back to driving for unsupported transit.
* City Channels now record richer rejection reasons, route-readiness counters, stable candidate provenance and safer public URL validation.
* Current locationless events remain serendipity-only until coordinates are verified; expired events are rejected.
* Mobile stop cards and route preview behavior are tightened for routing failures and narrow screens.

= 2.0.0 =
* Attribution metadata; AI Integration panel (Devin.ai, OpenAI, Azure, Anthropic, custom), encrypted keys, consent, logging levels, audit log, strict JSON schema validation with one retry, sandbox mode, rate limits, tour cache TTL and batching window.
* Multilingual admin/frontend (fa default; en, es, zh, ru, hi, ar), RTL, Jalali calendar, language bundles.
* City Channels, Google Maps links, social links; My Maps hidden publicly; NameCityDayNddmmyy permalinks.
* Green planned route, blue live route with trimming, device-scoped saved places, Go to start.
* No-login local guide registration with mobile verification; pending events; steppers; Jalali date pickers.
* Rule based planner, analytics center with exports, Google Drive connector, SMS outbox, My Trip, My Vibe.

= 1.0.0 =
* Initial release.

== 2.2.14 ==

* Visitor-facing tour generation no longer waits for Google Drive exports. Forms and generated tours are queued through a one-off WP-Cron job after the tour is saved.
* Existing manual/scheduled Drive exports and local export snapshots are preserved.
