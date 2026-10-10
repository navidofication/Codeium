# Personalized City Tour 2.2.6

- Fixed the Google My Maps KML refresh action on the City editor: the refresh control no longer nests a form inside WordPress’s post editor form, so it no longer redirects to the Posts screen.
- Added a detailed KML refresh report showing success/fallback/error status and feature counts for every configured map.
- Improved public My Maps KML/KMZ fetching with canonical and fallback export URLs plus safer response-size handling.
- Added planner strategy controls for ranked-interest rotation, Event/My Maps source requirements, and maximum stops per proposal.
- Tour proposals now rotate the traveller’s ordered interests cyclically and expose the active focus interest in each proposal’s summary metadata. A compatible focus stop is inserted when the AI does not already provide one.
- Public suggested-stop cards are now locked to a compact fixed size; featured images are rendered as small top-left thumbnails and open in a full-size lightbox when clicked.
