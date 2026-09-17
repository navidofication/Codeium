=== Personalized City Tour ===
Contributors: personalizedcitytour
Tags: travel, tours, maps, events, ai
Requires at least: 6.0
Tested up to: 6.6
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

City databases, local-guide event registration, a travel preference form builder and AI-generated personalized tours with online route maps.

== Description ==

**Admin**

* "City Tours" menu with a hamburger drawer for Settings, Form builder, Local guides and the active-city selector.
* City database as custom post types: Cities, Places (historical, cultural, social, artistic, …), Events (with organizer + public event page links) and generated Tours.
* Google My Maps links per city (shown to travelers, embedded when possible).
* Local guide role with application / approve / revoke / remove workflow. Guides only edit their own records; publishing is optional (`Auto-publish guide submissions`).
* Visual form builder for the travel preference form: 12 field types, sections, conditional logic (`equals`, `contains`, `not_empty`, …), JSON import/export.
* Access control: public or logged-in travelers.
* AI providers: OpenAI / ChatGPT, Azure OpenAI (Copilot stack), Anthropic or any OpenAI-compatible endpoint. Rule-based planner is used as a fallback (or when no provider is configured). Personal contact fields are never sent to the AI.
* Map & routing: OpenStreetMap + OSRM by default; Google Maps optional. Every day gets an online route map plus "Open in Google Maps / OpenStreetMap" links.

**Frontend** – shortcode `[personalized_city_tour]` (attributes: `view="both|traveler|guide"`, `city="ID"`).

* "I am a traveler": pick a city → multi-step preference form → tour options in a Day 1 / Day 2 / Day 3 hamburger menu. Each day has a title, summary, suggested stops (tags, times, notes), alternatives, accessibility notes, quick search hints and a route map with **Preview** and **Navigate** (live location) modes.
* "I am a local guide": apply for the guide role, register events with organizer info and a map-picked location, manage own events.

== Installation ==

1. Upload the `personalized-city-tour` folder to `/wp-content/plugins/` and activate.
2. Go to *City Tours → Settings* and configure access, AI provider and maps.
3. Add cities, places and events under *City Tours*.
4. Place `[personalized_city_tour]` on a page.

== REST API ==

Namespace `pct/v1`: `GET /cities`, `GET /form`, `POST /tours`, `GET /tours/{id}?token=`, `GET|POST /guide/*`, `GET /geocode?q=`.

== Changelog ==

= 1.0.0 =
* Initial release.
