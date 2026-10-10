# 2.0.6

- Added a dedicated encrypted persistent storage option for the Google Drive refresh token, with immediate round-trip verification after OAuth.
- Kept the legacy settings token synchronized for backward compatibility.
- Improved connection status and token-refresh diagnostics without exposing secrets.
- Added automatic support for Google Drive folder URLs as well as raw folder IDs, including URLs with `?usp=drive_link`.
- Disconnect now clears the dedicated refresh-token storage as well as the legacy token and access-token state.
- Bumped plugin version to 2.0.6.
