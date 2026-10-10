# Personalized City Tour 2.2.2

## Browser Web Push
- Fixed the VAPID JWT construction bug in the native Web Push sender. The JWT is now sent as the required `header.payload.signature` string instead of being base64-encoded a second time.
- The admin push panel now verifies that the browser subscription also exists on the server before enabling the test button.
- Explicitly re-enabling notifications recreates the subscription, preventing stale subscriptions created with an older VAPID public key from surviving a key migration.
- The server-side test stores a short-lived diagnostic result so HTTP/push-service errors can be displayed in the admin UI.

## Built-in UI translations
- Removed runtime AI generation of UI-language bundles. UI localization no longer calls the AI provider and therefore no longer creates `ui_translation` audit errors.
- Added built-in core UI translation bundles for Spanish, Chinese, Russian, Hindi and Arabic.
- Persian remains the full bundled language. Any string not present in another language's built-in core bundle falls back deterministically to its English source; configured non-AI online translation can still be used where explicitly enabled.
- Legacy scheduled AI translation jobs are now harmless no-ops.

## My Trip, My Vibe reference photos
- A request containing an uploaded photo is now forced through the OpenAI Images edit path (`/v1/images/edits`) because the other configured providers in this plugin were not receiving the uploaded file.
- The uploaded file is sent as a multipart image input to OpenAI Images with `gpt-image-2.5-sunburst` when needed.
- Text-only providers are no longer allowed to silently generate a replacement image for a request that contains a reference photo.
- The prompt guard remains active even when an older/custom Prompt Template is saved, requiring the same people, identities, count and composition to be preserved.

## Events and Google My Maps
- Event date filtering now performs overlap checking in PHP, so events without a populated end date are not silently excluded from a valid travel window.
- Public Google My Maps points that are not yet present in City Data are now converted into stable virtual tour candidates with real coordinates, Google Maps links and map/folder context.
- Curated My Maps candidates can therefore be selected by the AI even when City Data contains only a small number of places.
- My Maps route lines are re-matched against both City Data items and virtual My Maps candidates so route order can be preserved as a route-planning signal.
- Google My Maps KML loading now tries additional export variants and can read KML contained in a KMZ response when the server has `ZipArchive`.
- The tour planner prompt now treats City Data, date-matched Events and curated My Maps candidates as the supplied candidate pool. It no longer rejects valid virtual My Maps candidates merely because they are not WordPress place/event posts.
- Route rendering continues to be done by the plugin from the selected stop coordinates; the AI does not need to draw a map itself.
- The AI candidate pool now keeps at least 60 and at most 120 regular candidates, while up to 100 virtual My Maps points can be added, subject to the existing data/privacy settings.

## Existing controls retained
- The existing OTP-per-image rule remains: each new image generation requires a new OTP request and verification.
- Existing anti-abuse rate limits remain in place.
- Google Drive integration and prior 2.2.x fixes remain unchanged.
