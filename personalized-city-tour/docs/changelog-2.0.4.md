# 2.0.4

- Hardened Google OAuth callback handling and persistent error diagnostics.
- Added `include_granted_scopes=true` and preserved an existing refresh token on re-authorization when Google omits a new one.
- Google token-exchange HTTP errors are now recorded in the Drive log.
- Fixed a false-success bug: when Sandbox is off but Drive is not connected, exports now fail visibly instead of silently being treated as local success.
- Upload failures now propagate as export errors while preserving the local snapshot.
- Added last OAuth diagnostic to the Drive settings page.
- Bumped plugin version to 2.0.4.
