<?php
/**
 * Traveler ("I am a traveler") runtime harness against the WP stub layer.
 * php tests/php/traveler.php
 */
require __DIR__ . '/wp-stubs.php';
$GLOBALS['fails'] = 0;
$GLOBALS['php_notices'] = array();
set_error_handler( function ( $no, $str, $file, $line ) {
	$GLOBALS['php_notices'][] = "[$no] $str @ " . basename( $file ) . ":$line";
	echo "  PHP[$no] $str @ " . basename( $file ) . ":$line\n";
	return true;
} );
pct_seed_user( 1, 'admin', array( 'administrator' ) );
pct_seed_city();
$tomorrow = gmdate( 'Y-m-d', strtotime( '+1 day' ) );
$day2     = gmdate( 'Y-m-d', strtotime( '+2 days' ) );
$day3     = gmdate( 'Y-m-d', strtotime( '+3 days' ) );
// Expired event (ended yesterday) — must never be recommended.
pct_seed( 31, 'pct_event', 'Expired Festival', array( '_pct_city_id' => 1, '_pct_lat' => 48.855, '_pct_lng' => 2.352, '_pct_start' => gmdate( 'Y-m-d', strtotime( '-3 days' ) ) . ' 10:00', '_pct_end' => gmdate( 'Y-m-d', strtotime( '-1 day' ) ) . ' 20:00', '_pct_accessibility' => array( 'wheelchair' ) ), array( 'food', 'historical' ) );
// Current, date-matched event WITHOUT coordinates — Serendipity candidate, never a route stop.
pct_seed( 32, 'pct_event', 'Pop-up Food Lab', array( '_pct_city_id' => 1, '_pct_start' => $tomorrow . ' 11:00', '_pct_end' => $tomorrow . ' 22:00', '_pct_event_url' => 'https://foodlab.example', '_pct_accessibility' => array( 'wheelchair' ) ), array( 'food' ) );
// Valid event outside the requested window (+20 days).
pct_seed( 33, 'pct_event', 'Far Future Fair', array( '_pct_city_id' => 1, '_pct_lat' => 48.857, '_pct_lng' => 2.353, '_pct_start' => gmdate( 'Y-m-d', strtotime( '+20 days' ) ) . ' 10:00', '_pct_end' => gmdate( 'Y-m-d', strtotime( '+20 days' ) ) . ' 20:00', '_pct_accessibility' => array( 'wheelchair' ) ), array( 'food' ) );
// Plenty of accessible low-cost places so the 18-stop ceiling can actually be exercised.
$cats = array( 'historical', 'food', 'shopping', 'artistic', 'nature', 'museums' );
for ( $i = 0; $i < 30; $i++ ) {
	pct_seed( 100 + $i, 'pct_place', 'Spot ' . $i, array( '_pct_city_id' => 1, '_pct_lat' => 48.84 + ( $i % 6 ) * 0.003, '_pct_lng' => 2.33 + intdiv( $i, 6 ) * 0.004, '_pct_duration' => 45, '_pct_price_tier' => 'low', '_pct_accessibility' => array( 'wheelchair' ), '_pct_gmaps_url' => 'https://maps.google.com/?q=Spot+' . $i ), array( $cats[ $i % 6 ] ) );
}
pct_boot();

$form = array(
	'name' => 'Sara Tester', 'group_type' => 'friends', 'nights' => 3, 'age_group' => '26-35',
	'dates_mode' => 'custom', 'date_start' => $tomorrow, 'date_end' => $day3,
	'travel_style' => array( 'relaxed' ), 'pace' => 'fast',
	'interests' => array( 'food', 'historical', 'shopping' ),
	'mobility_modes' => array( 'walking' ), 'mobility_limitations' => array( 'wheelchair' ), 'budget_tier' => 'low',
);

function pct_stop_ids( array $day ) { return array_values( array_map( 'intval', wp_list_pluck( (array) ( $day['stops'] ?? array() ), 'item_id' ) ) ); }
function pct_all_stop_ids( array $result ) { $ids = array(); foreach ( (array) $result['days'] as $d ) { $ids = array_merge( $ids, pct_stop_ids( $d ) ); } return $ids; }
function pct_check_consistency( array $result, $label ) {
	$ok = true; $why = '';
	foreach ( (array) $result['days'] as $d ) {
		$ids = pct_stop_ids( $d );
		if ( count( $ids ) > 18 ) { $ok = false; $why = 'more than 18 stops'; }
		if ( $ids !== array_map( 'intval', (array) $d['canonical_stop_ids'] ) || $ids !== array_map( 'intval', (array) $d['map']['canonical_stop_ids'] ) ) { $ok = false; $why = 'canonical ids differ from stop order'; }
		if ( count( $ids ) !== count( array_unique( $ids ) ) ) { $ok = false; $why = 'duplicate stop'; }
		$coords = array();
		foreach ( (array) $d['stops'] as $s ) {
			if ( '' === trim( (string) $s['name'] ) || empty( $s['lat'] ) || empty( $s['lng'] ) || empty( $s['gmaps_url'] ) || ! isset( $s['note'] ) ) { $ok = false; $why = 'stop missing name/coords/link/note: ' . json_encode( $s ); }
			$coords[] = round( (float) $s['lat'], 3 ) . ',' . round( (float) $s['lng'], 3 );
		}
		// External directions link must list the same stops in the same order.
		parse_str( (string) parse_url( (string) $d['map']['google_url'], PHP_URL_QUERY ), $q );
		$link = array_merge( array( $q['origin'] ?? '' ), ! empty( $q['waypoints'] ) ? explode( '|', $q['waypoints'] ) : array(), array( $q['destination'] ?? '' ) );
		$link = array_values( array_filter( array_map( function ( $c ) { $p = explode( ',', $c ); return count( $p ) === 2 ? round( (float) $p[0], 3 ) . ',' . round( (float) $p[1], 3 ) : ''; }, $link ) ) );
		if ( $link !== $coords ) { $ok = false; $why = 'google_url stops differ from cards: ' . json_encode( $link ) . ' vs ' . json_encode( $coords ); }
		if ( 'walking' !== $d['map']['mode'] ) { $ok = false; $why = 'mode ' . $d['map']['mode']; }
		$times = wp_list_pluck( (array) $d['stops'], 'time' );
		if ( $times !== array_filter( $times ) ) { $ok = false; $why = 'stop without time'; }
	}
	pct_assert( $ok, $label . ': stops/canonical ids/markers/links/times consistent' . ( $why ? ' — ' . $why : '' ) );
}

echo "== A. fallback planner (AI disabled)\n";
PCT_Settings::set( 'ai_provider', 'none' );
$GLOBALS['php_notices'] = array();
$r = pct_rest( 'POST', '/tours', array( 'city_id' => 1, 'form' => $form ) );
$t = is_wp_error( $r ) ? array() : pct_data( $r );
pct_assert( ! is_wp_error( $r ) && ! empty( $t['result']['days'] ), 'tour created' . ( is_wp_error( $r ) ? ': ' . $r->get_error_message() : '' ) );
pct_assert( empty( $GLOBALS['php_notices'] ), 'no PHP notices/warnings during generation' );
$res = $t['result'];
$keys = array(); foreach ( $res['days'] as $d ) { $keys[] = $d['day'] . ':' . $d['proposal_number']; }
pct_assert( 12 === count( $res['days'] ) && count( array_unique( $keys ) ) === 12, '3 days x 4 proposals: ' . implode( ' ', $keys ) );
pct_check_consistency( $res, 'A' );
$all = pct_all_stop_ids( $res );
pct_assert( ! in_array( 31, $all, true ), 'expired event never recommended' );
pct_assert( ! in_array( 33, $all, true ), 'event outside requested dates never recommended' );
pct_assert( ! in_array( 32, $all, true ), 'locationless event is not a route stop' );
$ser = array(); foreach ( $res['days'] as $d ) { foreach ( (array) $d['serendipity'] as $s ) { $ser[] = (int) $s['item_id']; } }
pct_assert( in_array( 32, $ser, true ), 'locationless current event surfaced as Serendipity: ' . json_encode( $ser ) );
pct_assert( ! in_array( 31, $ser, true ), 'expired event not in Serendipity either' );
$acc_ok = true; foreach ( $res['days'] as $d ) { foreach ( $d['stops'] as $s ) { if ( ! in_array( 'wheelchair', (array) $s['accessibility'], true ) ) { $acc_ok = false; } } }
pct_assert( $acc_ok, 'wheelchair: all stops accessible' );
// Ranked-interest rotation: day 1 proposals 1..3 focus food, historical, shopping.
$by_id = array(); foreach ( array_merge( PCT_Data::places( 1 ), PCT_Data::events( 1 ) ) as $it ) { $by_id[ (int) $it['id'] ] = $it; }
$rot_ok = true; $rot = array();
foreach ( $res['days'] as $d ) {
	if ( 1 !== (int) $d['day'] || (int) $d['proposal_number'] > 3 ) { continue; }
	$focus = $form['interests'][ (int) $d['proposal_number'] - 1 ];
	$has = false; foreach ( $d['stops'] as $s ) { if ( in_array( $focus, (array) ( $by_id[ (int) $s['item_id'] ]['categories'] ?? array() ), true ) ) { $has = true; } }
	$rot[] = $d['proposal_number'] . '=' . $focus . ( $has ? '✓' : '✗' ); if ( ! $has ) { $rot_ok = false; }
}
pct_assert( $rot_ok, 'ranked interests rotate across proposals: ' . implode( ' ', $rot ) );
$sets = array(); foreach ( $res['days'] as $d ) { if ( 1 === (int) $d['day'] ) { $sets[] = implode( ',', pct_stop_ids( $d ) ); } }
pct_assert( count( array_unique( $sets ) ) === count( $sets ), 'day-1 proposals differ from each other' );
pct_assert( isset( $res['source_usage']['interest_weights']['food'] ) && $res['source_usage']['interest_weights']['food'] > $res['source_usage']['interest_weights']['shopping'], 'ranked weights preserved (food > shopping)' );
pct_assert( 'rules' === $res['provider'], 'provider recorded as rules: ' . $res['provider'] );

echo "== B. 18-stop ceiling\n";
PCT_Settings::set( 'stops_per_day', 40 );
$big = PCT_Tour_Generator::generate( 1, $form, array( 'lang' => 'en' ) );
$max = 0; foreach ( $big['days'] as $d ) { $max = max( $max, count( $d['stops'] ) ); }
pct_assert( $max <= 18 && $max >= 3, 'max stops per proposal within [3,18]: ' . $max );
pct_check_consistency( $big, 'B' );
PCT_Settings::set( 'stops_per_day', 3 );

echo "== C. planner rules + weights\n";
update_option( 'pct_planner_rules', PCT_Planner::sanitize( array(
	'rules' => array( array( 'name' => 'No shopping', 'enabled' => 1, 'priority' => 1, 'when' => array( array( 'field' => 'group_type', 'op' => 'equals', 'value' => 'friends' ) ), 'then' => array( array( 'action' => 'exclude', 'target' => 'category', 'value' => 'shopping' ) ) ) ),
) ) );
$c = PCT_Tour_Generator::generate( 1, $form, array( 'lang' => 'en' ) );
$shop = false; foreach ( pct_all_stop_ids( $c ) as $id ) { if ( in_array( 'shopping', (array) ( $by_id[ $id ]['categories'] ?? array() ), true ) ) { $shop = true; } }
pct_assert( ! $shop, 'If/Then exclude rule removes shopping stops' );
pct_assert( ! empty( $c['days'] ) && count( $c['days'] ) === 12, 'rules still yield 12 proposals' );
// max_distance_km caps the per-hop penalty; a high cap + strong penalty must tighten routes.
update_option( 'pct_planner_rules', PCT_Planner::sanitize( array( 'weights' => array( 'max_distance_km' => 50, 'distance_penalty' => 10, 'route_compactness_penalty' => 2 ) ) ) );
$w = PCT_Tour_Generator::generate( 1, $form, array( 'lang' => 'en' ) );
function pct_span_km( array $day ) { $lats = wp_list_pluck( $day['stops'], 'lat' ); $lngs = wp_list_pluck( $day['stops'], 'lng' ); return $lats ? ( max( $lats ) - min( $lats ) ) * 111 + ( max( $lngs ) - min( $lngs ) ) * 73 : 0; }
$span_w = 0; foreach ( $w['days'] as $d ) { $span_w += pct_span_km( $d ); }
$span_c = 0; foreach ( $c['days'] as $d ) { $span_c += pct_span_km( $d ); }
pct_assert( $span_w < $span_c, sprintf( 'distance weight tightens proposals (span %.2f < %.2f km)', $span_w, $span_c ) );
update_option( 'pct_planner_rules', PCT_Planner::sanitize( array() ) );

echo "== D. AI configured but provider fails -> must fall back, not fatal\n";
PCT_Settings::set( 'ai_provider', 'openai' );
PCT_Settings::set( 'ai_api_key', 'sk-test-not-real' );
PCT_Settings::set( 'ai_sandbox', 0 );
PCT_Settings::set( 'ai_consent_city_db', 1 );
$GLOBALS['http_handler'] = function ( $url ) { return pct_http_response( array( 'error' => array( 'message' => 'upstream down' ) ), 500 ); };
$GLOBALS['php_notices'] = array();
try {
	$r = pct_rest( 'POST', '/tours', array( 'city_id' => 1, 'form' => $form ) );
	$t = is_wp_error( $r ) ? array() : pct_data( $r );
	pct_assert( ! is_wp_error( $r ) && ! empty( $t['result']['days'] ), 'provider 500 -> planner fallback tour returned' );
	pct_assert( 'rules' === ( $t['result']['provider'] ?? '' ), 'fallback recorded as rules (admin-only warning hidden from visitors): provider=' . ( $t['result']['provider'] ?? '?' ) );
	$errlog = PCT_AI_Log::query( array( 'status' => 'error' ) ); $errlog = $errlog['items'] ?? $errlog;
	pct_assert( count( $errlog ) >= 1, 'provider failure written to AI audit log' );
} catch ( Throwable $e ) {
	pct_assert( false, 'FATAL during AI-failure fallback: ' . $e->getMessage() . ' @ ' . basename( $e->getFile() ) . ':' . $e->getLine() );
}
pct_assert( empty( $GLOBALS['php_notices'] ), 'no PHP notices in fallback path' );
$GLOBALS['http_handler'] = function ( $url ) { return new WP_Error( 'http_request_failed', 'cURL error 28: timeout' ); };
try {
	$r = pct_rest( 'POST', '/tours', array( 'city_id' => 1, 'form' => $form ) );
	pct_assert( ! is_wp_error( $r ) && ! empty( pct_data( $r )['result']['days'] ), 'provider timeout -> planner fallback tour returned' );
} catch ( Throwable $e ) {
	pct_assert( false, 'FATAL during AI-timeout fallback: ' . $e->getMessage() . ' @ ' . basename( $e->getFile() ) . ':' . $e->getLine() );
}
$GLOBALS['http_handler'] = null;

echo "== E. AI sandbox (mock) with consent -> audit distinguishes retrieved/selected/used\n";
PCT_Settings::set( 'ai_sandbox', 1 );
$GLOBALS['php_notices'] = array();
$r = pct_rest( 'POST', '/tours', array( 'city_id' => 1, 'form' => $form ) );
$t = is_wp_error( $r ) ? array() : pct_data( $r );
pct_assert( ! is_wp_error( $r ) && ! empty( $t['result']['days'] ), 'sandbox AI tour returned' );
pct_assert( empty( $GLOBALS['php_notices'] ), 'no PHP notices in AI path' );
pct_check_consistency( $t['result'], 'E' );
$su = $t['result']['source_usage'] ?? array();
pct_assert( isset( $su['city_channel_candidates_available'], $su['serendipity_candidates_available'], $su['interest_weights'] ), 'source_usage reports candidates available/selected: ' . implode( ',', array_keys( $su ) ) );
$logs = PCT_AI_Log::query( array( 'kind' => 'tour' ) ); $logs = $logs['items'] ?? $logs;
pct_assert( count( $logs ) >= 1, 'AI tour request logged (' . count( $logs ) . ')' );
$all = pct_all_stop_ids( $t['result'] );
pct_assert( ! in_array( 31, $all, true ) && ! in_array( 33, $all, true ), 'AI path: expired/out-of-window events still excluded' );
PCT_Settings::set( 'ai_provider', 'none' ); PCT_Settings::set( 'ai_sandbox', 0 );

echo "== F. route proxy (walking / transit)\n";
$pts = array( array( 'lat' => 48.85, 'lng' => 2.35 ), array( 'lat' => 48.86, 'lng' => 2.36 ) );
$rt = pct_rest( 'POST', '/route', array( 'points' => $pts, 'mode' => 'teleport' ) );
pct_assert( is_wp_error( $rt ) && 422 === (int) ( $rt->get_error_data()['status'] ?? 0 ), 'unknown mode rejected with 422' );
$rt = pct_rest( 'POST', '/route', array( 'points' => $pts, 'mode' => 'walking' ) );
pct_assert( is_wp_error( $rt ) && in_array( (int) ( $rt->get_error_data()['status'] ?? 0 ), array( 502, 503 ), true ), 'walking with no provider reachable -> provider error (no straight-line geometry): ' . ( is_wp_error( $rt ) ? $rt->get_error_code() : json_encode( pct_data( $rt ) ) ) );
$GLOBALS['http_handler'] = function ( $url ) { return pct_http_response( array( 'trip' => array( 'legs' => array( array( 'shape' => '_p~iF~ps|U_ulLnnqC_mqNvxq`@', 'summary' => array( 'length' => 1.2, 'time' => 900 ) ) ), 'summary' => array( 'length' => 1.2, 'time' => 900 ) ) ) ); };
$rt = pct_rest( 'POST', '/route', array( 'points' => $pts, 'mode' => 'walking' ) );
$rd = is_wp_error( $rt ) ? array() : pct_data( $rt );
pct_assert( ! is_wp_error( $rt ) && ! empty( $rd['geometry'] ?? $rd['coordinates'] ?? $rd['points'] ?? null ), 'walking route via Valhalla mock returns geometry: ' . ( is_wp_error( $rt ) ? $rt->get_error_message() : implode( ',', array_keys( $rd ) ) ) );
$GLOBALS['http_handler'] = null;

echo "FAILS: {$GLOBALS['fails']}\n";
exit( $GLOBALS['fails'] ? 1 : 0 );
