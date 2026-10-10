const fs = require('fs');
const path = require('path');

const root = path.resolve(__dirname, '..');
const read = (rel) => fs.readFileSync(path.join(root, rel), 'utf8');

let failures = 0;
function check(name, condition) {
  if (!condition) {
    failures += 1;
    console.error(`FAIL ${name}`);
    return;
  }
  console.log(`PASS ${name}`);
}

const main = read('personalized-city-tour.php');
const readme = read('readme.txt');
const maps = read('includes/class-pct-maps.php');
const rest = read('includes/class-pct-rest.php');
const settings = read('includes/class-pct-settings.php');
const channels = read('includes/class-pct-channel-intel.php');
const generator = read('includes/class-pct-tour-generator.php');
const publicJs = read('public/js/public.js');
const publicCss = read('public/css/public.css');

check('plugin header version is 2.2.26', /Version:\s+2\.2\.26/.test(main));
check('PCT_VERSION constant is 2.2.26', /define\(\s*'PCT_VERSION',\s*'2\.2\.26'\s*\)/.test(main));
check('readme stable tag is 2.2.26', /Stable tag:\s*2\.2\.26/.test(readme));

check('new installs default to Valhalla routing', /'routing_provider'\s*=>\s*'valhalla'/.test(settings));
check('maps settings sanitizer falls back to Valhalla', /routing_provider'.*\?\s*\$input\['routing_provider'\]\s*:\s*'valhalla'/s.test(settings));
check('Valhalla provider is wired in route switch', /case\s+'valhalla':\s*\$result\s*=\s*self::route_valhalla/s.test(maps));
check('route cache key includes provider endpoints and mode', /valhalla_endpoint/.test(maps) && /osrm_endpoint/.test(maps) && /\$provider\s*\.\s*'\|'\s*\.\s*\$mode/.test(maps));
check('OSRM transit is rejected rather than driving', /OSRM does not calculate public-transit/.test(maps) && /if\s*\(\s*'transit'\s*===\s*\$mode\s*\)/.test(maps));
check('OSM transit deep link is removed instead of car fallback', /osm_directions_url[\s\S]*if\s*\(\s*'transit'\s*===\s*\$mode\s*\)\s*\{\s*return\s+'';/s.test(maps));
check('straight-line navigable routing is disabled', /Straight-line mode is disabled for navigable tour routes/.test(maps));
check('REST route validates travel mode and preserves provider error status', /PCT_Maps::normalize_travel_mode/.test(rest) && /get_error_data/.test(rest) && /empty\(\s*\$data\['status'\]\s*\)/.test(rest));

check('City Channel audit has structured rejection reasons', [
  'no_coordinates',
  'expired_event',
  'outside_date_window',
  'invalid_url',
  'duplicate',
  'normalization'
].every((needle) => channels.includes(`'${needle}'`)));
check('City Channel counts route-ready and locationless accepted candidates', /route_ready_candidates/.test(channels) && /locationless_accepted_candidates/.test(channels));
check('City Channel records stable candidate provenance', /candidate_id/.test(channels) && /source_name/.test(channels) && /observed_at/.test(channels) && /route_eligible/.test(channels));
check('City Channel source URLs are public-url checked', /private static function is_public_url/.test(channels) && /source_allowed[\s\S]*self::is_public_url/.test(channels));
check('expired events are rejected explicitly', /expired_event/.test(channels) && /current_time\(\s*'timestamp'\s*\)/.test(channels));

const attachIndex = generator.indexOf('attach_serendipity_candidates');
const canonicalIndex = generator.indexOf('canonicalize_route_order( $result');
const finalizeIndex = generator.indexOf('self::finalize( $result');
check('canonical route ordering runs after serendipity and before finalization', attachIndex >= 0 && canonicalIndex > attachIndex && canonicalIndex < finalizeIndex);
check('generator exposes canonical route metadata', /canonical_stop_ids/.test(generator) && /route_order_source/.test(generator) && /route_provider/.test(generator));
check('generator preserves curated My Maps order and geographic preoptimization sources', /curated_mymap_route_order/.test(generator) && /geographic_preoptimizer/.test(generator));
check('generator syncs schedule to canonical order', /sync_stop_times_to_order/.test(generator) && /slot_times_for_window/.test(generator));
check('generated roles can use raw interest categories', /'categories'\s*=>\s*array_values/.test(generator));
check('AI audit compact source rows include rejection counters', /rejection_reasons/.test(generator) && /route_ready_candidates/.test(generator) && /rejected_candidates/.test(generator));

check('frontend never draws straight planned route on failure', /Never show straight stop-to-stop/.test(publicJs) && /this\.route\.setLatLngs\(\[\]\)/.test(publicJs));
check('frontend preview falls back to stop bounds when route geometry is absent', /routePoints/.test(publicJs) && /L\.latLngBounds\(this\.stops\.map/.test(publicJs));
check('mobile stop cards have narrow-screen constraints', /@media \(max-width:430px\)/.test(publicCss) && /pct-stop-action/.test(publicCss));

if (failures) {
  console.error(`\n${failures} static regression check(s) failed.`);
  process.exit(1);
}

console.log('\nAll static regression checks passed.');
