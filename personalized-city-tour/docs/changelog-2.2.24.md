# Personalized City Tour 2.2.24

- Raised planner maximum stops per proposal to 18 and Suggested stops per day ceiling to 18.
- Updated AI instructions so 18 is a ceiling, not a filler quota; time, pace, opening hours and route feasibility remain authoritative.
- Reworked interest rotation so focus continues across days/proposals instead of resetting at the start of every day, while retaining soft secondary-interest blending.
- Added planner weights for focus/secondary interests, event relevance, My Maps, channel freshness, source diversity, verified location, Serendipity, time fit and route compactness.
- Added If/Then rule targets for location status, City Channel source host and channel discovery flag.
- Replaced factorial route ordering for larger plans with nearest-neighbour + 2-opt optimization so 18-stop plans remain computationally practical.
- Preserved the hard rule that locationless City Channel discoveries cannot become route stops; current date-matched Events without verified location remain Serendipity candidates.
- Packaged the ZIP with the canonical `personalized-city-tour/` directory so WordPress can recognize it as the same plugin during upload/replace.
