# Personalized City Tour 2.2.1

- Moved the administrator browser-notification permission control out of the public My Trip, My Vibe screen and into the WordPress admin My Trip, My Vibe page.
- The administrator test-notification button now uses the same real server-side Web Push path as OTP alerts and is enabled only when this browser has an active subscription.
- Push REST subscription permissions are restricted directly to users with the `pct_manage` capability.
- OTP notifications are sent after the configured OTP delivery path accepts the request, so failed SMS/delivery attempts do not create a false browser alert.
- Removed the one-successful-image-per-device restriction.
- Each verified OTP authorizes exactly one new image request. The token remains usable for viewing that request, but creating another image requires a fresh OTP request and verification.
- Kept the existing anti-abuse rate limits; the old permanent one-image device lock is gone.
