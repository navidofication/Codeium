# 2.0.9

- Cache-busted frontend assets by bumping PCT_VERSION to 2.0.9.
- Tour presentation: explicit Style | Priority | Suggested time lead line before the narrative summary.
- Map is placed immediately after Tour Details and given inline dimensions plus delayed Leaflet invalidateSize passes for mobile/page-builder layouts.
- Official-only “Contact them” action; Google Search is no longer silently substituted for an official link.
- Curated Google My Maps public KML is read server-side when available, cached, matched to city items, and supplied to the AI as active route-planning context.
- My Maps-matched items receive a planner preference boost and are explicitly preferred by the AI when compatible.
- Added a localized map-unavailable fallback message.
- AI HTTP error logging now includes provider request ID/rate-limit metadata when supplied, without storing secrets.

## 2.0.9 — OpenAI production adapter + provider-neutral AI + image providers
- OpenAI default model updated to `gpt-5.6-luna` for cost-sensitive high-volume tour generation.
- OpenAI and Azure OpenAI use Structured Outputs (`json_schema`, strict mode) when a tour schema is supplied; other providers retain the portable plugin validation/retry path.
- Azure supports the current `/openai/v1/chat/completions` style as well as the older deployment URL format.
- Tour generation now requests four distinct options per travel day in one AI call, with 3–4 stops per option and reduced alternative payloads.
- Candidate city items are capped at 40 by default and the full exported city snapshot is no longer duplicated into every AI prompt.
- AI prompt explicitly requires meaningful diversity and minimizes repeated stops while honoring dates, opening hours, My Maps matches, accessibility and official links.
- Added Gemini/Nano Banana 2 image generation (`gemini-3.1-flash-image`) with server-side API key handling.
- Added Higgsfield image API support with Key ID + Secret authentication and asynchronous polling; default model is `z-image/turbo`.
- Existing Pollinations and Hugging Face fallback behavior remains available.
