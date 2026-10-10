<?php
/* REST / logic flows: i18n, tours (fallback planner), guide no-login, route proxy, vibe OTP, planner rules, analytics permissions, drive sandbox. */
require __DIR__ . '/wp-stubs.php';
set_error_handler( function ( $no, $str, $file, $line ) {
	fwrite( STDERR, "  PHP[$no] $str @ " . basename( $file ) . ":$line\n" );
	foreach ( array_slice( debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS ), 1, 5 ) as $fr ) {
		fwrite( STDERR, '      <- ' . basename( $fr['file'] ?? '?' ) . ':' . ( $fr['line'] ?? '?' ) . ' ' . ( $fr['function'] ?? '' ) . "\n" );
	}
	$GLOBALS['fails']++;
	return true;
} );
pct_seed_user( 1, 'admin', array( 'administrator' ) );
pct_seed_user( 2, 'visitor', array( 'subscriber' ) );
pct_seed_city();
pct_boot();

echo "== i18n\n";
$d = pct_data( pct_rest( 'GET', '/i18n' ) );
pct_assert( 'fa' === ( $d['current'] ?? $d['lang'] ?? '' ), 'default language is fa: ' . json_encode( array_keys( $d ) ) );
pct_assert( ! empty( $d['rtl'] ), 'fa is RTL' );
$d = pct_data( pct_rest( 'GET', '/i18n', array( 'pct_lang' => 'en' ) ) );
pct_assert( 'en' === ( $d['current'] ?? $d['lang'] ?? '' ), 'pct_lang=en switches' );
pct_assert( empty( $d['rtl'] ), 'en is LTR' );
$d = pct_data( pct_rest( 'GET', '/i18n', array(), array( 'X-PCT-LANG' => 'ar' ) ) );
pct_assert( 'ar' === ( $d['current'] ?? $d['lang'] ?? '' ) && ! empty( $d['rtl'] ), 'X-PCT-LANG header ar + RTL' );
pct_assert( is_array( $d['strings'] ?? $d['bundle'] ?? null ), 'bundle strings present' );
$d = pct_data( pct_rest( 'GET', '/i18n', array( 'pct_lang' => 'xx' ) ) );
pct_assert( 'fa' === ( $d['current'] ?? $d['lang'] ?? '' ), 'unknown language falls back to default' );
pct_assert( '1404' === substr( PCT_I18n::format_date( strtotime( '2025-03-21 12:00:00 UTC' ), 'fa' ), 0, 4 ), 'Jalali format 2025-03-21 -> 1404: ' . PCT_I18n::format_date( strtotime( '2025-03-21 12:00:00 UTC' ), 'fa' ) );
pct_assert( array( 1404, 1, 1 ) === PCT_I18n::to_jalali( 2025, 3, 21 ), 'to_jalali(2025-3-21) = 1404/1/1' );
pct_assert( array( 2025, 3, 21 ) === PCT_I18n::to_gregorian( 1404, 1, 1 ), 'to_gregorian(1404/1/1) = 2025-3-21' );
pct_assert( '2025-03-21' === PCT_I18n::parse_date( '1404/01/01' ), 'parse_date Jalali -> gregorian: ' . PCT_I18n::parse_date( '1404/01/01' ) );
pct_assert( '2025-03-21' === PCT_I18n::parse_date( '2025-03-21' ), 'parse_date gregorian passthrough' );
PCT_I18n::set_current( 'en' );

echo "== cities/form\n";
$c = pct_data( pct_rest( 'GET', '/cities' ) );
pct_assert( 1 === count( $c ) && 'Testville' === $c[0]['name'], 'cities lists Testville' );
pct_assert( ! isset( $c[0]['mymaps'] ) || empty( $c[0]['mymaps'] ), 'My Maps hidden publicly by default' );
pct_assert( ! empty( $c[0]['channels'] ) || ! empty( $c[0]['google_url'] ), 'channels / google url exposed: ' . json_encode( array_keys( $c[0] ) ) );
$f = pct_data( pct_rest( 'GET', '/form' ) );
pct_assert( ! empty( $f['sections'] ), 'form schema has sections' );

echo "== tour generation (fallback planner, AI disabled)\n";
$answers = array(
	'name'         => 'Jane Tester',
	'email'        => 'jane@example.com',
	'group_type'   => 'family',
	'children_count' => 1,
	'children_ages' => array( 'kids' ),
	'nights'       => 3,
	'age_group'    => '26-35',
	'dates_mode'   => 'custom',
	'date_start'   => gmdate( 'Y-m-d', strtotime( '+1 day' ) ),
	'date_end'     => gmdate( 'Y-m-d', strtotime( '+4 days' ) ),
	'travel_style' => array( 'relaxed' ),
	'pace'         => 'moderate',
	'interests'    => array( 'food', 'historical', 'shopping' ),
	'mobility_modes' => array( 'walking' ),
	'mobility_limitations' => array( 'wheelchair' ),
	'budget_tier'  => 'low',
);
$r = pct_rest( 'POST', '/tours', array( 'city_id' => 1, 'form' => $answers ) );
if ( is_wp_error( $r ) ) { echo '  ERR ' . $r->get_error_message() . ' ' . json_encode( $r->get_error_data() ) . "\n"; }
$t = is_wp_error( $r ) ? array() : pct_data( $r );
pct_assert( ! is_wp_error( $r ) && ! empty( $t['result']['days'] ), 'tour created with days' );
$days = $t['result']['days'] ?? array();
pct_assert( 3 === count( array_unique( array_map( 'intval', wp_list_pluck( $days, 'day' ) ) ) ) && 12 === count( $days ), '3 nights -> 3 days x 4 proposals: ' . count( $days ) );
pct_assert( ! empty( $t['id'] ) && ! empty( $t['token'] ) && ! empty( $t['url'] ), 'tour stored with id/token/url: ' . ( $t['url'] ?? '' ) );
pct_assert( false !== strpos( (string) ( $t['url'] ?? '' ), 'jane' ) || false !== strpos( (string) ( $t['url'] ?? '' ), 'testville' ), 'permalink contains name/city: ' . ( $t['url'] ?? '' ) );
$title = $t['result']['title'] ?? '';
pct_assert( false !== stripos( $title, 'Jane' ) && false !== stripos( $title, 'Testville' ), 'title has name + city: ' . $title );
$stop = $days[0]['stops'][0] ?? array();
pct_assert( isset( $stop['lat'], $stop['lng'] ), 'stop has coordinates' );
pct_assert( 'https://maps.google.com/?q=Place+' . ( (int) $stop['item_id'] - 10 ) === $stop['gmaps_url'] || 30 === (int) $stop['item_id'], 'stop uses stored Google Maps link: ' . $stop['gmaps_url'] );
$all_ok = true;
foreach ( $days as $day ) { foreach ( (array) $day['stops'] as $s ) { $acc = (array) ( $s['accessibility'] ?? $s['tags'] ?? array() ); if ( ! in_array( 'wheelchair', $acc, true ) ) { $all_ok = false; } } }
pct_assert( $all_ok, 'wheelchair constraint: every stop accessible' );
pct_assert( ! empty( $days[0]['quick_search_hints'] ), 'quick search hints present' );
pct_assert( ! empty( $t['result']['city']['search_url'] ), 'search url present' );
$hints = json_encode( $t['result'], JSON_UNESCAPED_SLASHES );
pct_assert( false !== strpos( $hints, 't.me/testville' ), 'City Channels included in output' );
pct_assert( false === strpos( $hints, 'maps/d/viewer' ), 'My Maps link NOT in public output' );
$g = pct_data( pct_rest( 'GET', '/tours/' . $t['id'], array( 'token' => $t['token'] ) ) );
pct_assert( ( $g['id'] ?? 0 ) === $t['id'], 'tour retrievable with token' );
$g = pct_rest( 'GET', '/tours/' . $t['id'], array( 'token' => 'bad' ) );
pct_assert( is_wp_error( $g ), 'tour NOT retrievable with bad token' );
$bad = pct_rest( 'POST', '/tours', array( 'city_id' => 1, 'form' => array( 'name' => '' ) ) );
pct_assert( is_wp_error( $bad ) && 400 === ( $bad->get_error_data()['status'] ?? 0 ), 'invalid form -> 400' );

echo "== planner rules\n";
PCT_Planner::sanitize( array() );
$rules = PCT_Planner::sanitize( array(
	'weights'     => array( 'distance_penalty' => 2, 'variety_penalty' => 1, 'event_bonus' => 3, 'max_distance_km' => 5 ),
	'constraints' => array( 'day_start' => '10:00', 'day_end' => '20:00', 'max_price_tier' => 'low', 'require_accessible' => 0 ),
	'rules'       => array(
		array( 'name' => 'No shopping for families', 'enabled' => 1, 'priority' => 5, 'when' => array( array( 'field' => 'group_type', 'op' => 'equals', 'value' => 'family' ) ), 'then' => array( array( 'action' => 'exclude', 'target' => 'category', 'value' => 'shopping' ) ), 'window' => array( 'start' => '', 'end' => '' ), 'note' => 'Families skip malls.' ),
		array( 'name' => 'Boost food', 'enabled' => 1, 'priority' => 1, 'when' => array( array( 'field' => 'interests', 'op' => 'contains', 'value' => 'food' ) ), 'then' => array( array( 'action' => 'boost', 'target' => 'category', 'value' => 'food', 'weight' => 5 ) ), 'window' => array( 'start' => '18:00', 'end' => '23:00' ), 'note' => '' ),
		array( 'name' => '', 'when' => array(), 'then' => array() ),
	),
) );
pct_assert( 2 === count( $rules['rules'] ), 'blank rule dropped, 2 kept: ' . count( $rules['rules'] ) );
update_option( 'pct_planner_rules', $rules );
pct_assert( 'Boost food' === PCT_Planner::get()['rules'][0]['name'], 'rules sorted by priority: ' . PCT_Planner::get()['rules'][0]['name'] );
$items  = PCT_Data::places( 1 );
foreach ( $items as &$it ) { $it['score'] = 1; } unset( $it );
pct_assert( count( $items ) > 5, 'candidates loaded: ' . count( $items ) );
$filtered = PCT_Planner::apply_to_items( $items, $answers );
$has_shop = false; $food_score = null; $hist_score = null;
foreach ( $filtered as $it ) {
	if ( in_array( 'shopping', (array) $it['categories'], true ) ) { $has_shop = true; }
	if ( in_array( 'food', (array) $it['categories'], true ) ) { $food_score = $it['score'] ?? $it['_score'] ?? null; }
	if ( in_array( 'historical', (array) $it['categories'], true ) ) { $hist_score = $it['score'] ?? $it['_score'] ?? null; }
}
pct_assert( ! $has_shop, 'exclude rule removed shopping items for family' );
pct_assert( null !== $food_score && null !== $hist_score && $food_score > $hist_score, "boost rule raised food score ($food_score > $hist_score)" );
$prompt = PCT_Planner::prompt_rules( $answers );
$prompt = implode( "\n", (array) $prompt );
pct_assert( false !== strpos( $prompt, 'Families skip malls' ) && false !== strpos( $prompt, '10:00' ), 'prompt_rules includes note + day window' );
$t2 = pct_data( pct_rest( 'POST', '/tours', array( 'city_id' => 1, 'form' => $answers ) ) );
$cats = json_encode( $t2['result']['days'] ?? array() );
pct_assert( false === stripos( $cats, '"shopping"' ), 'generated tour honours exclude rule' );
delete_option( 'pct_planner_rules' );

echo "== route proxy\n";
$r = pct_rest( 'POST', '/route', array( 'points' => array( array( 'lat' => 48.85, 'lng' => 2.35 ), array( 'lat' => 48.86, 'lng' => 2.36 ) ), 'mode' => 'walking' ) );
pct_assert( is_wp_error( $r ) ? in_array( $r->get_error_data()['status'] ?? 0, array( 400, 501, 502 ), true ) : ! empty( pct_data( $r )['geometry'] ), 'route without provider -> clean error or geometry: ' . ( is_wp_error( $r ) ? $r->get_error_code() : 'geometry' ) );
$r = pct_rest( 'POST', '/route', array( 'points' => array( array( 'lat' => 99, 'lng' => 2.35 ), array( 'lat' => 48.86, 'lng' => 2.36 ) ) ) );
pct_assert( is_wp_error( $r ) && 400 === ( $r->get_error_data()['status'] ?? 0 ), 'invalid lat -> 400' );
$r = pct_rest( 'POST', '/route', array( 'points' => array( array( 'lat' => 48.85, 'lng' => 2.35 ) ) ) );
pct_assert( is_wp_error( $r ) && 400 === ( $r->get_error_data()['status'] ?? 0 ), '<2 points -> 400' );
$poly = PCT_Maps::decode_polyline( '_p~iF~ps|U_ulLnnqC_mqNvxq`@' );
pct_assert( 3 === count( $poly ) && abs( $poly[0][0] - 38.5 ) < 0.001 && abs( $poly[0][1] + 120.2 ) < 0.001, 'polyline decode (Google sample)' );
$cfg = PCT_Maps::client_config();
pct_assert( '' === ( $cfg['googleKey'] ?? 'x' ) && '' === ( $cfg['mapboxToken'] ?? 'x' ), 'client config leaks no keys with OSM tiles' );
pct_assert( false !== strpos( PCT_Maps::item_google_url( array( 'lat' => 1.5, 'lng' => 2.5, 'name' => 'X' ) ), 'google.com/maps' ), 'item_google_url fallback' );
pct_assert( 'https://maps.google.com/?q=Place+1' === PCT_Maps::item_google_url( array( 'gmaps_url' => 'https://maps.google.com/?q=Place+1', 'lat' => 1, 'lng' => 2 ) ), 'item_google_url prefers stored link' );

echo "== guide no-login flow\n";
$r = pct_rest( 'GET', '/guide/events' );
pct_assert( is_wp_error( $r ) && 403 === ( $r->get_error_data()['status'] ?? 0 ), 'guide events without token -> 403' );
$r = pct_rest( 'POST', '/guide/apply', array( 'name' => 'Guide Gal', 'mobile' => '0912 345 6789', 'city_id' => 1, 'languages' => array( 'fa', 'en' ) ) );
if ( is_wp_error( $r ) ) { echo '  ERR ' . $r->get_error_message() . "\n"; }
$a = pct_data( $r );
pct_assert( ! empty( $a['token'] ) && 'pending' === ( $a['status'] ?? '' ), 'apply returns token + pending' );
pct_assert( '+989123456789' === PCT_Guide_Profiles::get( $a['id'] )['mobile'], 'mobile normalised to E.164: ' . PCT_Guide_Profiles::get( $a['id'] )['mobile'] );
pct_assert( 'fa, en' === PCT_Guide_Profiles::get( $a['id'] )['languages'], 'languages array stored as list' );
$token = $a['token'] ?? '';
$outbox = PCT_SMS::outbox();
pct_assert( 1 === count( $outbox ) && false !== strpos( $outbox[0]['message'], 'Guide Gal' ), 'registration SMS queued in manual outbox' );
pct_assert( false === strpos( $outbox[0]['message'], 'http' ), 'guide SMS contains no login link' );
$s = pct_data( pct_rest( 'GET', '/guide/status', array(), array( 'X-PCT-GUIDE' => $token ) ) );
pct_assert( 'pending' === ( $s['status'] ?? '' ), 'status via header token = pending: ' . json_encode( $s ) );
$r = pct_rest( 'POST', '/guide/apply', array( 'name' => 'Guide Gal', 'mobile' => '+98 912 345 6789', 'city_id' => 1 ) );
$a2 = pct_data( $r );
pct_assert( ( $a2['id'] ?? 0 ) === ( $a['id'] ?? -1 ) && $a2['token'] !== $token, 'same mobile re-registers -> same profile, rotated token' );
$token = $a2['token'];
$r = pct_rest( 'POST', '/guide/events', array( 'title' => 'Evening Food Walk', 'city_id' => 1, 'start' => '1405/07/10 18:00', 'end' => '1405/07/10 20:00', 'lat' => 48.851, 'lng' => 2.351, 'organizer_name' => 'City Org', 'organizer_mobile' => '09123456780', 'categories' => array( 'food' ), 'description' => 'Walk' ), array( 'X-PCT-GUIDE' => $token ) );
if ( is_wp_error( $r ) ) { echo '  ERR ' . $r->get_error_message() . "\n"; }
$e = pct_data( $r );
pct_assert( ! empty( $e['id'] ) && 'pending' === ( $e['status'] ?? '' ), 'pending guide event stays pending: ' . json_encode( $e ) );
pct_assert( '2026-10-02 18:00' === get_post_meta( $e['id'], '_pct_start', true ), 'Jalali start converted: ' . get_post_meta( $e['id'], '_pct_start', true ) );
$r = pct_rest( 'POST', '/guide/events', array( 'title' => 'No mobile', 'city_id' => 1, 'start' => '2026-10-02 18:00', 'lat' => 48.851, 'lng' => 2.351 ), array( 'X-PCT-GUIDE' => $token ) );
$nm = pct_data( $r );
pct_assert( ! is_wp_error( $r ) && '+989123456789' === get_post_meta( $nm['id'], '_pct_organizer_mobile', true ), 'organizer mobile falls back to guide profile mobile' );
wp_delete_post( $nm['id'], true );
$list = pct_data( pct_rest( 'GET', '/guide/events', array(), array( 'X-PCT-GUIDE' => $token ) ) );
pct_assert( 1 === count( $list ) && 'Evening Food Walk' === $list[0]['title'], 'guide sees only own events' );
$before = count( pct_data( pct_rest( 'GET', '/cities' ) ) );
// admin approves and publishes pending events
wp_set_current_user( 1 );
$ok = PCT_Guide_Profiles::set_status( $a['id'], PCT_Guide_Profiles::STATUS_APPROVED, true );
pct_assert( 'publish' === get_post( $e['id'] )->post_status, 'approval publishes pending events' );
$outbox = PCT_SMS::outbox();
pct_assert( 2 === count( $outbox ), 'approval SMS queued: ' . count( $outbox ) );
PCT_SMS::mark_done( $outbox[0]['id'] );
pct_assert( 1 === count( PCT_SMS::outbox() ), 'mark_done removes from pending outbox' );
wp_set_current_user( 0 );
$s = pct_data( pct_rest( 'GET', '/guide/status', array(), array( 'X-PCT-GUIDE' => $token ) ) );
pct_assert( 'approved' === ( $s['status'] ?? '' ), 'status now approved' );
$r = pct_rest( 'POST', '/guide/events', array( 'title' => 'Second', 'city_id' => 1, 'start' => '2026-10-03 18:00', 'lat' => 48.851, 'lng' => 2.351, 'organizer_mobile' => '09123456780' ), array( 'X-PCT-GUIDE' => $token ) );
$e2 = pct_data( $r );
pct_assert( 'pending' === ( $e2['status'] ?? '' ), 'approved guide event still pending when auto-publish off: ' . json_encode( $e2 ) );
PCT_Settings::set( 'guide_auto_publish', 1 );
$e3 = pct_data( pct_rest( 'POST', '/guide/events', array( 'title' => 'Third', 'city_id' => 1, 'start' => '2026-10-03 18:00', 'lat' => 48.851, 'lng' => 2.351 ), array( 'X-PCT-GUIDE' => $token ) ) );
pct_assert( 'publish' === ( $e3['status'] ?? '' ), 'approved guide event publishes when auto-publish on' );
PCT_Settings::set( 'guide_auto_publish', 0 );
$r = pct_rest( 'DELETE', '/guide/events/' . $e['id'], array(), array( 'X-PCT-GUIDE' => $token ) );
pct_assert( ! is_wp_error( $r ) && 'trash' === get_post( $e['id'] )->post_status, 'guide deletes (trashes) own event' );
$r = pct_rest( 'DELETE', '/guide/events/30', array(), array( 'X-PCT-GUIDE' => $token ) );
pct_assert( is_wp_error( $r ), 'guide cannot delete others\' events' );
wp_set_current_user( 1 );
PCT_Guide_Profiles::set_status( $a['id'], PCT_Guide_Profiles::STATUS_REVOKED );
wp_set_current_user( 0 );
$r = pct_rest( 'GET', '/guide/events', array(), array( 'X-PCT-GUIDE' => $token ) );
pct_assert( is_wp_error( $r ), 'revoked guide -> 403' );

echo "== vibe (sandbox)\n";
update_option( PCT_Vibe::OPTION, array_merge( PCT_Vibe::settings(), array( 'enabled' => 1, 'sandbox' => 1 ) ) );
$r = pct_rest( 'POST', '/vibe/otp', array( 'mobile' => '09121112233' ) );
if ( is_wp_error( $r ) ) { echo '  ERR ' . $r->get_error_message() . "\n"; }
$o = is_wp_error( $r ) ? array() : pct_data( $r );
pct_assert( ! is_wp_error( $r ) && ! isset( $o['code'] ), 'OTP requested, real code not returned: ' . json_encode( $o ) );
$last = array();
foreach ( PCT_SMS::outbox() as $row ) { if ( 'otp' === $row['context'] ) { $last = $row; } }
preg_match( '/\b\d{6}\b/', (string) ( $last['message'] ?? '' ), $m );
$code = $m[0] ?? '';
pct_assert( '' !== $code && $code === ( $o['dev_code'] ?? '' ), 'OTP code delivered via SMS outbox (matches sandbox dev_code): ' . json_encode( $last ) );
$r = pct_rest( 'POST', '/vibe/otp/verify', array( 'mobile' => '09121112233', 'code' => '000000' ) );
pct_assert( is_wp_error( $r ), 'wrong OTP rejected' );
$r = pct_rest( 'POST', '/vibe/otp/verify', array( 'mobile' => '+98 912 111 2233', 'code' => $code ) );
$v = is_wp_error( $r ) ? array() : pct_data( $r );
pct_assert( ! empty( $v['token'] ), 'OTP verified (intl format of same number) -> visitor token: ' . ( is_wp_error( $r ) ? $r->get_error_message() : '' ) );
$vt   = $v['token'] ?? '';
$tmp  = tempnam( sys_get_temp_dir(), 'pct' ) . '.png';
file_put_contents( $tmp, base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==' ) );
$set = PCT_Vibe::settings();
$r = pct_rest( 'POST', '/vibe/requests', array( 'visitor_token' => $vt, 'name' => 'Vi', 'city_id' => 1, 'vibe' => $set['vibes'][0] ?? 'calm', 'style' => $set['styles'][0] ?? 'photo', 'subject' => $set['subjects'][0] ?? 'me', 'notes' => 'hi' ), array(), array( 'photo' => array( 'name' => 'me.png', 'type' => 'image/png', 'tmp_name' => $tmp, 'error' => 0, 'size' => filesize( $tmp ) ) ) );
if ( is_wp_error( $r ) ) { echo '  ERR ' . $r->get_error_message() . ' ' . json_encode( $r->get_error_data() ) . "\n"; }
$vr = pct_data( $r );
pct_assert( ! empty( $vr['id'] ) && 'pending' === ( $vr['status'] ?? '' ), 'vibe request created pending: ' . json_encode( $vr ) );
$r = pct_rest( 'POST', '/vibe/requests', array( 'visitor_token' => 'bad', 'name' => 'Vi', 'city_id' => 1 ) );
pct_assert( is_wp_error( $r ), 'vibe request with bad token rejected' );
$st = pct_data( pct_rest( 'GET', '/vibe/requests/' . $vr['id'], array( 'visitor_token' => $vt ) ) );
pct_assert( 'pending' === ( $st['status'] ?? '' ) && ! isset( $st['mobile'] ), 'public view hides mobile' );
wp_set_current_user( 1 );
$ap = PCT_Vibe::approve( $vr['id'] );
$gen = PCT_Vibe::generate( $vr['id'] );
$full = PCT_Vibe::get( $vr['id'] );
pct_assert( 'done' === $full['status'] && ! empty( $full['image_url'] ) && '' === $full['error'], 'sandbox generation produced image: ' . $full['status'] . ' ' . $full['image_url'] );
$ready = array_filter( PCT_SMS::outbox(), function ( $row ) use ( $vr ) { return 'vibe_ready:' . $vr['id'] === $row['context']; } );
pct_assert( 1 === count( $ready ), 'ready SMS queued in manual outbox (not auto-marked delivered)' );
$prompt = PCT_Vibe::prompt( $full );
pct_assert( false !== strpos( $prompt, 'Testville' ), 'prompt template includes city: ' . substr( $prompt, 0, 80 ) );
PCT_Vibe::mark_delivered( $vr['id'] );
pct_assert( 'delivered' === PCT_Vibe::get( $vr['id'] )['status'], 'manual mark_delivered -> delivered' );
wp_set_current_user( 0 );
$st = pct_data( pct_rest( 'GET', '/vibe/requests/' . $vr['id'], array( 'visitor_token' => $vt ) ) );
pct_assert( ! empty( $st['image_url'] ?? $st['result_url'] ?? '' ), 'visitor sees image url after delivery' );

echo "== AI validation (sandbox)\n";
PCT_Settings::set( 'ai_provider', 'openai' );
PCT_Settings::set( 'ai_sandbox', 1 );
pct_assert( PCT_AI::enabled(), 'AI enabled in sandbox' );
$res = PCT_AI::complete_json( 'sys', 'user', array( 'kind' => 'test', 'schema' => array( 'type' => 'object', 'required' => array( 'a' ), 'properties' => array( 'a' => array( 'type' => 'integer' ) ) ), 'mock' => function () { return array( 'a' => 1 ); } ) );
pct_assert( is_array( $res ) && 1 === $res['a'], 'sandbox mock passes schema' );
$GLOBALS['mock_n'] = 0;
$res = PCT_AI::complete_json( 'sys', 'user', array( 'kind' => 'test', 'schema' => array( 'type' => 'object', 'required' => array( 'a' ), 'properties' => array( 'a' => array( 'type' => 'integer' ) ) ), 'mock' => function () { $GLOBALS['mock_n']++; return array( 'a' => 'x' ); } ) );
pct_assert( is_wp_error( $res ), 'invalid mock fails schema -> WP_Error: ' . ( is_wp_error( $res ) ? $res->get_error_code() : 'ok?' ) );
pct_assert( 2 === $GLOBALS['mock_n'], 'exactly one retry: calls=' . $GLOBALS['mock_n'] );
$logs = PCT_AI_Log::query( array( 'status' => 'invalid' ) );
pct_assert( count( $logs['items'] ?? $logs ) >= 1, 'invalid responses logged' );
PCT_Settings::set( 'ai_provider', 'none' );
$t3 = pct_data( pct_rest( 'POST', '/tours', array( 'city_id' => 1, 'form' => $answers ) ) );
pct_assert( 'rules' === ( $t3['result']['source'] ?? $t3['result']['generator'] ?? '' ) || ! empty( $t3['result']['days'] ), 'AI off -> deterministic fallback produces tour' );

require __DIR__ . "/cache.inc.php";

echo "== analytics permissions\n";
wp_set_current_user( 2 );
pct_assert( ! PCT_Analytics::can_access( 2 ), 'subscriber cannot access analytics' );
$r = pct_rest( 'GET', '/admin/analytics/1' );
pct_assert( is_wp_error( $r ), 'analytics REST forbidden for subscriber' );
$r = pct_rest( 'GET', '/admin/ai-logs' );
pct_assert( is_wp_error( $r ), 'ai-logs REST forbidden for subscriber' );
wp_set_current_user( 1 );
pct_assert( PCT_Analytics::can_access( 1 ), 'admin can access analytics' );
$job = PCT_Analytics::create_job( array( 'title' => 'T', 'question' => 'Q', 'days' => 30, 'city_id' => 0, 'fields' => array( 'group_type' ), 'use_ai' => 0 ) );
PCT_Analytics::run_job( $job );
$j = PCT_Analytics::get_job( $job );
pct_assert( 'done' === ( $j['status'] ?? '' ) && ! empty( $j['result']['charts'] ), 'analytics job done with charts: ' . json_encode( array_keys( $j['result'] ?? array() ) ) );
$titles = array_column( $j['result']['charts'], 'title' );
pct_assert( in_array( 'group_type', $titles, true ), 'form field breakdown chart present: ' . json_encode( $titles ) );
$ds = PCT_Analytics::dataset( array( 'days' => 30, 'city_id' => 0, 'fields' => array( 'email', 'full_name', 'group_type' ) ) );
pct_assert( ! isset( $ds['form_fields']['email'] ) && ! isset( $ds['form_fields']['full_name'] ), 'PII fields excluded from dataset' );

echo "== drive sandbox\n";
PCT_Settings::set( 'drive_sandbox', 1 );
PCT_Settings::set( 'drive_exports', array( 'cities', 'tours' ) );
$out = PCT_Drive::run_exports( array( 'cities', 'tours' ) );
$log = PCT_Drive::log();
pct_assert( count( $log ) >= 2, 'drive sandbox export logged: ' . count( $log ) );

echo "\nFAILS: " . ( $GLOBALS['fails'] ?? 0 ) . "\n";
