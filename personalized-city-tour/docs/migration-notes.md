# Migration notes — prototype (1.x) → 2.0.0

Upgrading in place is supported: upload the new ZIP over the old plugin (or deactivate,
delete, and install — settings and content are kept in the database). On the first
admin page load `PCT_Install::maybe_upgrade()` compares `pct_version`, re-registers the
roles/capabilities, inserts missing default categories, creates the new tables and
schedules the cron jobs.

## What is created

| Kind | Name | Purpose |
|---|---|---|
| Table | `{prefix}pct_ai_log` | AI audit log (metadata, redacted payloads, retention) |
| CPT | `pct_guide` | No-login guide profiles (mobile, status, device token) |
| CPT | `pct_report` | Analytics requests, queue state and stored reports |
| CPT | `pct_vibe` | My Trip, My Vibe requests |
| Options | `pct_settings`, `pct_planner_rules`, `pct_vibe_settings`, `pct_sms_outbox`, `pct_tour_cache_gen`, `pct_lang_bundles`, `pct_translation_cache`, `pct_drive_state`, `pct_drive_log` | Settings, bundles, state |
| Cron | `pct_ai_log_purge` (daily), `pct_analytics_run` (hourly), `pct_drive_export` (per Drive schedule) | Housekeeping |

No existing tables are altered; no prototype data is deleted.

## Settings changes

* New keys with safe defaults: `ai_provider` (`none`), `ai_model`, `ai_deployment`, `ai_model_map` (provider→models), `ai_endpoint`, `ai_consent_city_db` (off), `ai_allowed_fields`, `ai_share_name`/`ai_share_contact` (off), `ai_log_level` (`minimal`), `ai_redact_pii` (on), `ai_log_retention_days`, `ai_sandbox`, `ai_rate_limit`, `ai_rate_limit_ip`, `ai_timeout`, `ai_temperature`, `ai_max_items`, `ai_retry_invalid`, **`ai_cache_ttl` (360 min)**, **`ai_batch_window` (30 s)**, `ai_prompt_template`, `language` (`fa`), `enabled_languages`, `translation_provider`, `routing_provider` (`osrm`), `search_page_url`, `show_mymaps_public` (off), Drive/SMS blocks.
* `ai_api_key` and every other secret are now stored encrypted (`PCT_Secrets`). A plaintext key from the prototype is encrypted on the first save of the AI tab; until then it is still readable by the plugin. Alternatively delete it and define `PCT_AI_API_KEY` in `wp-config.php`.
* The prototype's provider ids (`none`, `openai`, `azure`, `anthropic`, `custom`) are unchanged; `devin` is new.

## Content changes

* Cities gained `_pct_channels`, `_pct_gmaps_url`, `_pct_socials`; the existing `_pct_mymaps` links are kept but no longer rendered publicly unless *Show curated My Maps publicly* is enabled.
* Places/events gained `_pct_gmaps_url` (Google Maps location link) and events gained `_pct_organizer_mobile`. Prototype items without a link fall back to a `https://www.google.com/maps/search/?api=1&query=lat,lng` link built from stored coordinates.
* Event dates are still stored as Gregorian `Y-m-d H:i`; the Jalali picker converts on input, so no data conversion is needed.
* Generated tours: new tours use the `NameCityDayNddmmyy` slug and the localised title. Old tours keep their slugs; their stored JSON is still rendered by the new frontend (missing fields such as `gmaps_url`, `tags`, `alternatives` are treated as empty).

## Form builder

The default schema was extended (children stepper/ages, `dates_mode` + Jalali-aware date fields, `mobility_modes`, `mobility_limitations`, guide language, dietary). A customised prototype schema is **not** overwritten; open *Form Builder → Reset to default* to adopt the new fields, or add them manually. Field keys renamed in the default schema: `full_name → name`, `dates → dates_mode/date_start/date_end`, `mobility → mobility_modes`.

## Frontend

* Shortcode name unchanged: `[personalized_city_tour]`. New `view` values: `traveler`, `guide`, `both`.
* Assets: `public/js/pct-datepicker.js` (new), `public/js/public.js`, `public/css/public.css`; Leaflet stays bundled under `public/vendor/leaflet`.
* REST namespace unchanged (`pct/v1`). New routes: `/i18n`, `/i18n/translate`, `/route`, `/vibe/*`, `/admin/ai-logs`, `/admin/analytics/{id}`. Existing `/cities`, `/form`, `/tours`, `/guide/*` keep their request shape (responses gained extra fields only; `/guide/*` additionally accepts the `X-PCT-GUIDE` device token).
* Device-scoped data (saved places, guide token, Vibe visitor token, language) lives in `localStorage` under `pct_*` keys and in the `pct_lang` cookie; nothing to migrate.

## Roll back

Deactivate 2.0.0 and reinstall the prototype ZIP. The new tables/options are ignored by the
prototype and can be removed manually (`DROP TABLE {prefix}pct_ai_log;`
and `wp option delete pct_planner_rules pct_vibe_settings pct_sms_outbox pct_tour_cache_gen pct_lang_bundles pct_translation_cache pct_drive_state pct_drive_log`).
