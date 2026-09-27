# Sample AI prompts and expected JSON

All provider calls go through `PCT_AI::complete_json( $system, $user, $opts )`. The response
must be a single JSON object; it is validated server-side against the schema in
`PCT_Schema` (`includes/class-pct-schema.php`). An invalid answer is logged with
status `invalid` and retried **once** with the validation errors quoted back to the
model. A second failure falls back to the rule based planner — the visitor always
gets a tour.

Set **Settings → AI Integration → Sandbox / mock mode** to see these exact shapes
without an API key.

## 1. Tour generation (`kind = tour`)

### System prompt (default; editable via *Extra prompt instructions*)

```
You are an experienced local tour planner for Isfahan, Iran. You plan personalized city tours
strictly from the provided city database items. Never invent places: every stop must reference
an item id from the list. Respect accessibility, budget, dietary, pace and time-of-day
preferences and the planner_rules. If the traveller uses a wheelchair, only use items whose
access list contains "wheelchair". Write all texts in language "fa".
Return ONLY valid JSON matching this schema:
{"days":[{"day":1,"title":"string","summary":"string (2-4 sentences)","stops":[{"item_id":123,
"time":"HH:MM","note":"string, practical tip"}],"alternatives":[{"item_id":123,"note":"string"}],
"accessibility_notes":"string","quick_search_hints":["string"]}]}
```

### User message (JSON payload built by `PCT_Tour_Generator::ai_payload()`)

```json
{
  "title": "Requested PCT from Traveller in language fa for city Isfahan in date 1404/06/31 - 09:15",
  "consent": { "city_db_to_ai": true, "allowed_fields": ["name","categories","tags","description","typical_minutes","price_tier","accessibility","open_hours","channels"], "contact_shared": false },
  "instructions": { "days": 2, "stops_per_day": 3, "alternatives_per_day": 2,
    "quick_search_hints": "Short search phrases the traveller can type into the site search tool near each stop (3-5 per day).",
    "language": "fa", "today": "2026-09-22", "calendar": "jalali" },
  "planner_rules": [
    "Rule 'Family mornings': if group_type equals family then prefer categories [park, museum] in time window 09:00-13:00 (weight 8).",
    "Constraint: budget_tier low -> exclude price_tier high."
  ],
  "city": { "name": "Isfahan", "country": "Iran", "description": "…",
    "channels": [ { "label": "Isfahan events", "url": "https://t.me/isfahan_events" } ],
    "curated_maps": [ { "label": "Old town walk", "url": "https://www.google.com/maps/d/…" } ] },
  "traveller": {
    "group_type": { "question": "Who is travelling?", "answer": "Family" },
    "children_count": { "question": "Children", "answer": 2 },
    "nights": { "question": "Nights", "answer": 2 },
    "interests": { "question": "Interests (ranked)", "answer": ["historical", "food", "art"] },
    "pace": { "question": "Pace", "answer": "Relaxed" },
    "budget_tier": { "question": "Budget", "answer": "Medium" },
    "mobility_limitations": { "question": "Mobility", "answer": ["stroller"] }
  },
  "items": [
    { "id": 101, "type": "place", "name": "Naqsh-e Jahan Square", "categories": ["historical"], "tags": ["unesco","photo"], "typical_minutes": 90, "price_tier": "free", "accessibility": ["wheelchair","stroller"], "open_hours": "08:00-22:00" },
    { "id": 102, "type": "place", "name": "Grand Bazaar", "categories": ["shopping","historical"], "typical_minutes": 120, "price_tier": "medium", "accessibility": [] },
    { "id": 205, "type": "event", "name": "Miniature painting workshop", "categories": ["art"], "start": "2026-09-23 16:00", "end": "2026-09-23 18:00", "price_tier": "medium", "accessibility": ["wheelchair"] }
  ]
}
```

Contact data (`email`, `phone`) and the name are **omitted** unless the corresponding
opt-ins are enabled. Only fields ticked under *Allowed city-data fields* appear in `items`.

### Expected response

```json
{
  "summary": "دو روز آرام برای یک خانواده با تمرکز بر میدان تاریخی و بازار…",
  "days": [
    {
      "day": 1,
      "title": "روز اول — قلب تاریخی شهر",
      "summary": "صبح را در میدان نقش جهان آغاز کنید…",
      "stops": [
        { "item_id": 101, "time": "09:30", "note": "صبح زود خلوت‌تر است؛ کالسکه به‌راحتی حرکت می‌کند." },
        { "item_id": 102, "time": "11:30", "note": "ورودی شمالی پله ندارد." },
        { "item_id": 205, "time": "16:00", "note": "کارگاه از قبل رزرو شود." }
      ],
      "alternatives": [ { "item_id": 110, "note": "در صورت بارندگی" } ],
      "accessibility_notes": "همهٴ توقف‌ها برای کالسکه مناسب‌اند.",
      "quick_search_hints": ["کافه نزدیک میدان نقش جهان", "سرویس بهداشتی بازار"]
    },
    { "day": 2, "title": "…", "summary": "…", "stops": [ … ] }
  ]
}
```

Schema rules that are enforced: `days` 1…N (N = nights-derived), each `day.stops` has
1…(stops_per_day + 2) items, `item_id` must exist in `items`, `time` matches `HH:MM`,
`note` ≤ 600 chars, ≤ 6 alternatives, ≤ 8 hints. `PCT_Planner::post_filter()` then removes
stops that violate hard rules and `finalize()` attaches coordinates, Google Maps links,
tags and the localised title.

### Retry prompt (appended to the user message after an invalid answer)

```
<original user message>

YOUR PREVIOUS ANSWER WAS REJECTED. Fix these schema violations and answer again with ONE valid JSON object only (no prose, no markdown):
- days[0].stops[1].item_id: 999 is not one of the provided item ids
- days[1].stops: must contain at least 1 item
```

## 2. Analytics report (`kind = analysis`)

System: `You are a data analyst for a city tourism platform. Analyse the aggregated, anonymous statistics provided and answer the question. …`

User payload: `{ "question": "…", "period": {"from":"2026-08-01","to":"2026-08-31"}, "dataset": { "tours_per_day": {...}, "by_city": {...}, "by_language": {...}, "group_type": {...} } }` — aggregates only, no PII/free text (`PCT_Analytics::is_aggregatable_field()`).

Expected: `{ "summary": "string", "insights": ["string"], "charts": [{"title":"string","type":"bar|line|pie","labels":[…],"values":[…]}], "tables": [{"title":"string","columns":[…],"rows":[[…]]}] }`

## 3. My Trip, My Vibe (`kind = vibe`)

Prompt template (editable in *My Trip, My Vibe → Prompt template*):

```
Create a {style} illustration of {subject} in {city}. Mood: {vibe}. Notes from the traveller: {notes}.
Keep faces respectful and stylised; no text, no logos, no watermarks.
```

Image providers return `{ "image_url": "https://…" }` or `{ "b64_json": "…" }`; the job is stored
with status `pending → approved → generating → done → delivered` (or `rejected`, `failed`). In sandbox mode
a deterministic SVG placeholder is written to `uploads/`.

## 4. Audit log statuses

`ok` · `cached` (served from the tour cache, no provider call) · `invalid` (schema failure) ·
`error` (HTTP/transport or rate limit). The provider column shows `sandbox` for mock answers.
