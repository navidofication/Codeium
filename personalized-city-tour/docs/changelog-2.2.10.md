# 2.2.10

## iOS / mobile REST hardening
- Added a same-origin WordPress REST fallback through `index.php?rest_route=` for hosts/WAFs that block `/wp-json/`.
- REST requests now have a 15-second timeout so Safari does not remain stuck indefinitely.
- Anonymous public requests continue without a cached WordPress REST nonce.
- The public app now redirects its own HTTP page to HTTPS before booting, preventing mixed HTTP/HTTPS transport on Safari.

## Language chooser
- Choosing a language no longer waits for the `/i18n` REST request before showing the next screen.
- The selected language is stored immediately; the full server translation bundle is loaded in the background.
- This prevents a blocked/slow REST request from making the Persian choice appear to do nothing.
