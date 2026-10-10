# Personalized City Tour 2.2.26

Production hardening release focused on traveller tour generation, source auditability and route consistency.

## Highlights

- Added a server-side canonical stop order after interest rotation, source coverage repair and serendipity attachment. Stop cards, marker numbering, map links and route-provider geometry now use the same ordered stop IDs.
- Wired the Valhalla routing adapter into the provider switch and aligned new map-settings fallback to Valhalla. Unsupported modes are rejected instead of silently substituting a driving route.
- Expanded City Channels audit data with stable `candidate_id`, `source_name`, `observed_at`, `route_eligible`, locationless candidate counts and structured rejection reasons such as `expired_event`, `outside_date_window`, `invalid_url`, `duplicate` and `normalization`.
- Kept current events without verified coordinates as serendipity candidates only; they are never promoted to map/routing stops until coordinates are available.
- Improved public URL validation for configured City Channels and same-host direct event links.
- Tightened frontend route-preview behavior when routing fails and made narrow mobile stop cards more resilient.

## Upgrade Notes

The plugin slug and root folder remain `personalized-city-tour`, so uploading this ZIP over an existing installation updates the same plugin rather than creating a duplicate. Existing settings are preserved by the normal `PCT_Install::maybe_upgrade()` path.
