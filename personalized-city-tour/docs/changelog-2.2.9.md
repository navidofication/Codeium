# Personalized City Tour 2.2.9

## Public visitor / iOS reliability
- Anonymous public REST requests no longer send the WordPress cookie-auth `wp_rest` nonce. This avoids stale/user-specific nonces embedded in cached public pages causing Safari/iOS `Load failed` errors.
- Logged-in users still send the WordPress REST nonce for authenticated requests.
- PCT REST responses are marked no-cache/no-store to prevent LiteSpeed or browser caches from reusing visitor-specific API responses.
- Public REST calls now explicitly request JSON, use `cache: no-store`, and carry the selected PCT language in the query string as well as the header.
- The REST base URL embedded in the public frontend is forced to HTTPS.
- The frontend now restores the selected PCT language from localStorage/cookie before boot, improving cross-page and mobile language persistence.

## Scope
- No Google Drive/OAuth logic was changed.
- Tour-content consistency was tightened: proposal summaries that mention a different City Data/KML place than the actual stops are replaced with an authoritative stop-based summary.
- Public "Tour details → Time window" is now calculated from the actual scheduled stop times and durations, so it cannot contradict the times shown on route/stop cards.
