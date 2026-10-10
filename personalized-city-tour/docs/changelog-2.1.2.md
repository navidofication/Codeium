# Personalized City Tour 2.1.2

- Moved administrator browser notification trigger to the **OTP request** stage in My Trip, My Vibe.
- Added native Web Push subscription support for administrator devices using VAPID.
- Added the **Allow plugin and browser to send notifications** option to the My Trip, My Vibe OTP screen for authorized administrators.
- When an OTP request is accepted, subscribed administrator devices receive: **درخواست تولید عکس آمده است**.
- Added a root-scope service worker endpoint and automatic cleanup of expired push subscriptions.
- Existing 2.1.0 tour, map, image-generation and Google Drive fixes are retained.
