<?php
/* Integrations: City Channels editor, save-triggered export refresh (PCT_Sync), signed export links,
 * Drive local-first exports with retry, consent-gated AI links, diagnostics REST (AI / Drive / image). */
require __DIR__ . '/wp-stubs.php';
set_error_handler( function ( $no, $str, $file, $line ) {
	fwrite( STDERR, "  PHP[$no] $str @ " . basename( $file ) . ":$line\n" );
	$GLOBALS['fails']++;
	return true;
} );
pct_seed_user( 1, 'admin', array( 'administrator' ) );
pct_seed_user( 2, 'visitor', array( 'subscriber' ) );
pct_seed_city();
pct_boot( true );
wp_set_current_user( 1 );
PCT_I18n::set_current( 'en' );
$json = function ( $r ) { return wp_json_encode( pct_data( $r ) ); };

echo "== city editor: channels + Google Maps link\n";
$html = pct_render( array( 'PCT_Meta_Boxes', 'render_city' ), get_post( 1 ) );
pct_assert( false !== strpos( $html, 'name="_pct_channels[0][url]"' ), 'City Channels repeater rendered' );
pct_assert( false !== strpos( $html, 'https://t.me/testville' ), 'existing channel pre-filled' );
pct_assert( false !== strpos( $html, 'name="_pct_gmaps_url"' ), 'Google Maps location field rendered' );
pct_assert( false !== strpos( $html, 'name="_pct_mymaps[0][url]"' ), 'My Maps repeater still rendered' );
pct_assert( strpos( $html, '_pct_channels' ) < strpos( $html, '_pct_mymaps' ), 'City Channels appear before Google My Maps' );
$_POST = array(
	'pct_meta_nonce' => wp_create_nonce( 'pct_save_meta' ),
	'_pct_country'   => 'Utopia',
	'_pct_lat'       => '48.85',
	'_pct_lng'       => '2.35',
	'_pct_zoom'      => '13',
	'_pct_gmaps_url' => 'https://maps.app.goo.gl/abc',
	'_pct_channels'  => array(
		array( 'label' => 'Telegram', 'url' => 'https://t.me/testville' ),
		array( 'label' => 'Instagram', 'url' => ' https://instagram.com/testville ' ),
		array( 'label' => 'empty', 'url' => '' ),
		array( 'label' => 'bad', 'url' => 'javascript:alert(1)' ),
		'not-an-array',
	),
	'_pct_mymaps'    => array( array( 'label' => 'Best of', 'url' => 'https://www.google.com/maps/d/viewer?mid=abc123' ) ),
);
PCT_Meta_Boxes::save_city( 1, get_post( 1 ) );
$_POST = array();
$ch = get_post_meta( 1, '_pct_channels', true );
pct_assert( 2 === count( $ch ) && 'https://instagram.com/testville' === $ch[1]['url'], 'channels saved, trimmed, empty/javascript rows dropped: ' . json_encode( $ch ) );
pct_assert( 'https://maps.app.goo.gl/abc' === get_post_meta( 1, '_pct_gmaps_url', true ), 'city Google Maps link saved' );
$city = PCT_Data::city( 1 );
pct_assert( 2 === count( $city['channels'] ) && 'https://maps.app.goo.gl/abc' === $city['gmaps_url'], 'PCT_Data::city exposes channels + gmaps_url' );
$pub = pct_data( pct_rest( 'GET', '/cities' ) );
pct_assert( 2 === count( $pub[0]['channels'] ), 'public /cities carries channels' );

echo "== save-triggered refresh (PCT_Sync)\n";
$gen0 = (int) get_option( 'pct_tour_cache_gen', 0 );
$s = PCT_Sync::status();
pct_assert( 'idle' === $s['state'], 'initial state idle' );
do_action( 'save_post_pct_place', 10, get_post( 10 ), true );
$s = PCT_Sync::status();
pct_assert( 'pending' === $s['state'] && ! empty( $s['dirty'] ) && $s['next_run'] > time() + 30, 'save marks dirty + debounced pending' );
pct_assert( wp_next_scheduled( PCT_Sync::CRON_HOOK ) === $s['next_run'], 'single cron event scheduled at next_run' );
pct_assert( (int) get_option( 'pct_tour_cache_gen', 0 ) > $gen0, 'AI tour cache invalidated on save' );
$first = $s['next_run'];
do_action( 'save_post_pct_event', 30, get_post( 30 ), true );
pct_assert( PCT_Sync::status()['next_run'] >= $first && 1 === count( array_filter( array_keys( $GLOBALS['cron'] ), function ( $k ) { return PCT_Sync::CRON_HOOK === $k; } ) ), 'second save re-debounces a single job' );
pct_assert( false === PCT_Sync::ensure_fresh(), 'ensure_fresh does not run before the debounce' );
pct_assert( PCT_Sync::run() === PCT_Sync::run() && 0 === (int) PCT_Sync::status()['attempts'], 'run() before next_run is a no-op' ) ;
// simulate cron firing
$s = PCT_Sync::status(); $s['next_run'] = time() - 1; update_option( PCT_Sync::STATUS_OPT, $s );
do_action( PCT_Sync::CRON_HOOK );
$s = PCT_Sync::status();
pct_assert( 'ok' === $s['state'] && empty( $s['dirty'] ) && $s['last_ok'] > 0, 'cron run exports and clears dirty: ' . $s['state'] . ' ' . $s['last_error'] );
pct_assert( false === wp_next_scheduled( PCT_Sync::CRON_HOOK ), 'cron cleared after success' );
$latest = PCT_Drive::latest( 'cities' );
pct_assert( $latest && file_exists( $latest['path'] ) && 1 === (int) $latest['records'], 'latest cities export on disk with 1 record' );
$export = json_decode( file_get_contents( $latest['path'] ), true );
pct_assert( 'https://maps.app.goo.gl/abc' === ( $export['records'][0]['gmaps_url'] ?? '' ) && 12 === count( $export['records'][0]['places'] ), 'export contains the just-saved city data' );
pct_assert( false !== strpos( PCT_Sync::describe(), 'Up to date' ), 'describe(): ' . PCT_Sync::describe() );
$trashed = get_post( 11 ); $trashed->post_status = 'trash';
do_action( 'trashed_post', 11 );
pct_assert( 'pending' === PCT_Sync::status()['state'], 'trashing a place marks dirty again' );
do_action( 'deleted_post', 999, null );
PCT_Sync::run_now();
pct_assert( 'ok' === PCT_Sync::status()['state'], 'run_now refreshes immediately' );

echo "== AI access links (consent-gated) + signed export URLs\n";
PCT_Settings::set( 'ai_consent_city_db', 1 );
pct_assert( array() === PCT_Sync::ai_links(), 'no links when both attach options are off' );
PCT_Settings::set( 'ai_attach_upload_links', 1 );
$links = PCT_Sync::ai_links();
pct_assert( 1 === count( $links ) && 'uploads' === $links[0]['source'] && 'cities' === $links[0]['type'], 'uploads link for cities: ' . json_encode( $links ) );
pct_assert( false !== strpos( $links[0]['url'], '/pct/v1/exports/pct-cities-' ) && false !== strpos( $links[0]['url'], 'sig=' ), 'signed REST url' );
parse_str( parse_url( $links[0]['url'], PHP_URL_QUERY ), $q );
$fname = basename( parse_url( $links[0]['url'], PHP_URL_PATH ) );
$served = pct_rest( 'GET', '/exports/' . $fname, array( 'exp' => $q['exp'], 'sig' => $q['sig'] ) );
pct_assert( ! is_wp_error( $served ) && 1 === count( pct_data( $served )['records'] ), 'signed link serves export JSON' );
pct_assert( 'noindex, nofollow' === ( $served->headers['X-Robots-Tag'] ?? '' ), 'noindex header set' );
$r = pct_rest( 'GET', '/exports/' . $fname, array( 'exp' => $q['exp'], 'sig' => 'deadbeef' ) );
pct_assert( is_wp_error( $r ) && 403 === $r->get_error_data()['status'], 'tampered signature rejected' );
$old = time() - 10;
$r = pct_rest( 'GET', '/exports/' . $fname, array( 'exp' => $old, 'sig' => PCT_Sync::signature( $fname, $old ) ) );
pct_assert( is_wp_error( $r ) && 403 === $r->get_error_data()['status'], 'expired link rejected' );
$r = pct_rest( 'GET', '/exports/..%2Fwp-config.php', array( 'exp' => $q['exp'], 'sig' => $q['sig'] ) );
pct_assert( is_wp_error( $r ), 'traversal name rejected' );
$missing = 'pct-cities-20200101-000000.json';
$r = pct_rest( 'GET', '/exports/' . $missing, array( 'exp' => $q['exp'], 'sig' => PCT_Sync::signature( $missing, $q['exp'] ) ) );
pct_assert( is_wp_error( $r ) && 404 === $r->get_error_data()['status'], 'missing file 404' );
PCT_Settings::set( 'ai_attach_drive_links', 1 );
$links = PCT_Sync::ai_links();
pct_assert( 1 === count( $links ) && 'uploads' === $links[0]['source'], 'no Drive link fabricated while nothing reached Drive' );
// tour payload
$form = array( 'name' => 'Ana', 'group_type' => 'solo', 'nights' => 2, 'age_group' => '25-34', 'travel_style' => 'balanced', 'pace' => 'moderate', 'interests' => array( 'historical', 'food' ), 'preferred_times' => array( 'morning' ), 'mobility_modes' => array( 'walking' ), 'activity_level' => 'medium', 'budget_tier' => 'low', 'dates_mode' => 'flexible' );
$items   = PCT_Data::places( 1 );
$payload = PCT_Tour_Generator::ai_payload( $city, $form, $items, 2, 3, 'en' );
pct_assert( 1 === count( $payload['city_database_files']['files'] ?? array() ), 'AI payload lists city database file links' );
pct_assert( array( 'uploads' ) === ( $payload['consent']['export_links'] ?? null ), 'consent block names link sources: ' . json_encode( $payload['consent'] ?? null ) );
pct_assert( 2 === count( $payload['city']['channels'] ?? array() ), 'AI payload includes City Channels' );
PCT_Settings::set( 'ai_consent_city_db', 0 );
$payload = PCT_Tour_Generator::ai_payload( $city, $form, $items, 2, 3, 'en' );
pct_assert( ! isset( $payload['city_database_files'] ) && empty( $payload['consent']['export_links'] ), 'without consent: no export links in payload' );
PCT_Settings::set( 'ai_consent_city_db', 1 );

echo "== Google Drive: local-first store, upload retry, 401 refresh, no fabricated links\n";
PCT_Settings::set( 'drive_sandbox', 0 );
PCT_Settings::set( 'drive_enabled', 1 );
PCT_Settings::set( 'drive_client_id', 'client-id' );
PCT_Settings::set( 'drive_client_secret', 'client-secret-XYZ' );
PCT_Settings::set( 'drive_refresh_token', 'refresh-token-SECRET' );
PCT_Settings::set( 'drive_exports', array( 'cities' ) );
pct_assert( PCT_Drive::connected(), 'drive considered connected' );
$calls = array();
$mode  = 'fail';
$GLOBALS['http_handler'] = function ( $url, $args ) use ( &$calls, &$mode ) {
	$calls[] = $url;
	if ( PCT_Drive::TOKEN_URL === $url ) {
		return pct_http_response( array( 'access_token' => 'tok-' . count( $calls ), 'expires_in' => 3600 ) );
	}
	if ( 0 === strpos( $url, 'https://www.googleapis.com/upload/' ) ) {
		if ( 'fail' === $mode ) { return pct_http_response( array( 'error' => array( 'message' => 'Backend Error' ) ), 503 ); }
		if ( '401once' === $mode ) { $mode = 'ok'; return pct_http_response( array( 'error' => array( 'message' => 'Invalid Credentials' ) ), 401 ); }
		return pct_http_response( array( 'id' => 'drive-file-1', 'name' => 'x', 'webViewLink' => 'https://drive.google.com/file/d/drive-file-1/view' ) );
	}
	if ( false !== strpos( $url, '/permissions' ) ) { return pct_http_response( array( 'id' => 'perm' ) ); }
	if ( false !== strpos( $url, '/about' ) ) { return pct_http_response( array( 'user' => array( 'emailAddress' => 'owner@example.com' ), 'storageQuota' => array( 'usage' => 10, 'limit' => 1000 ) ) ); }
	if ( false !== strpos( $url, '/files' ) ) { return pct_http_response( array( 'files' => array() ) ); }
	return pct_http_response( array(), 404 );
};
PCT_Sync::run_now( 'test' );
$s = PCT_Sync::status();
pct_assert( 'pending' === $s['state'] && 1 === (int) $s['attempts'] && false !== strpos( $s['last_error'], '503' ), 'Drive 503 -> retry scheduled: ' . $s['last_error'] );
pct_assert( $s['next_run'] >= time() + 290 && wp_next_scheduled( PCT_Sync::CRON_HOOK ) === $s['next_run'], 'backoff 5 min' );
pct_assert( 3 === count( array_filter( $calls, function ( $u ) { return 0 === strpos( $u, 'https://www.googleapis.com/upload/' ); } ) ), 'upload retried 3x on 5xx: ' . count( $calls ) );
$latest = PCT_Drive::latest( 'cities' );
pct_assert( $latest && file_exists( $latest['path'] ) && '' === $latest['drive_id'], 'local snapshot still written when Drive fails' );
pct_assert( in_array( PCT_Drive::log()[0]['status'], array( 'upload_failed', 'error' ), true ), 'log records upload_failed' );
$links = PCT_Sync::ai_links();
pct_assert( 1 === count( $links ) && 'uploads' === $links[0]['source'], 'still no Drive link after failed upload' );
pct_assert( false !== strpos( PCT_Sync::describe(), 'attempt 2' ), 'describe shows next attempt: ' . PCT_Sync::describe() );
// retries exhausted -> failed
foreach ( array( 1, 2, 3 ) as $i ) { $s = PCT_Sync::status(); $s['next_run'] = time() - 1; update_option( PCT_Sync::STATUS_OPT, $s ); PCT_Sync::run(); }
$s = PCT_Sync::status();
pct_assert( 'failed' === $s['state'] && 4 === (int) $s['attempts'] && false === wp_next_scheduled( PCT_Sync::CRON_HOOK ), 'after 3 retries state=failed and cron cleared: ' . $s['state'] . '/' . $s['attempts'] );
pct_assert( false === PCT_Sync::maybe_run_overdue(), 'failed jobs are not re-run inline' );
// a new save resets attempts; 401 once -> token refresh -> success
$calls = array(); $mode = '401once';
do_action( 'save_post_pct_city', 1, get_post( 1 ), true );
$s = PCT_Sync::status();
pct_assert( 'pending' === $s['state'] && 0 === (int) $s['attempts'], 'new save resets attempts' );
$s['next_run'] = time() - 1; update_option( PCT_Sync::STATUS_OPT, $s );
PCT_Sync::run();
$s = PCT_Sync::status();
pct_assert( 'ok' === $s['state'] && '' === $s['last_error'], 'after 401 the token is refreshed and upload succeeds: ' . $s['last_error'] );
pct_assert( 1 === count( array_filter( $calls, function ( $u ) { return PCT_Drive::TOKEN_URL === $u; } ) ), 'token refreshed exactly once after 401: ' . json_encode( $calls ) );
$latest = PCT_Drive::latest( 'cities' );
pct_assert( 'drive-file-1' === $latest['drive_id'] && 'uploaded' === PCT_Drive::log()[0]['status'], 'latest export now has drive id' );
pct_assert( (bool) array_filter( $calls, function ( $u ) { return false !== strpos( $u, '/permissions' ); } ), 'public reader permission requested because Drive links are enabled' );
$links = PCT_Sync::ai_links();
$src   = array_column( $links, 'source' );
pct_assert( array( 'drive', 'uploads' ) === $src && false !== strpos( $links[0]['url'], 'drive-file-1' ), 'Drive + uploads links offered: ' . json_encode( $src ) );
// Drive fails again later: local link refreshes, previous Drive file kept
$mode = 'fail';
PCT_Sync::run_now( 'again' );
$latest = PCT_Drive::latest( 'cities' );
pct_assert( 'drive-file-1' === $latest['drive_id'] && ! empty( $latest['drive_time'] ), 'previous Drive file retained with its own timestamp' );
// network error path in api()
$GLOBALS['http_handler'] = function ( $url ) { return PCT_Drive::TOKEN_URL === $url ? pct_http_response( array( 'access_token' => 't', 'expires_in' => 3600 ) ) : new WP_Error( 'http_request_failed', 'cURL error 28' ); };
delete_option( PCT_Drive::STATE_OPT );
$r = PCT_Drive::api( 'GET', PCT_Drive::API_URL . '/about?fields=user' );
pct_assert( is_wp_error( $r ) && 'http_request_failed' === $r->get_error_code(), 'network error surfaces as WP_Error after retries' );

echo "== diagnostics REST\n";
wp_set_current_user( 2 );
$r = pct_rest( 'POST', '/admin/diagnose/drive' );
pct_assert( is_wp_error( $r ), 'diagnose forbidden for subscriber' );
pct_assert( is_wp_error( pct_rest( 'POST', '/admin/sync' ) ), 'sync forbidden for subscriber' );
wp_set_current_user( 1 );
pct_assert( is_wp_error( pct_rest( 'POST', '/admin/diagnose/other' ) ), 'unknown target has no route' );
$GLOBALS['http_handler'] = function ( $url ) {
	if ( PCT_Drive::TOKEN_URL === $url ) { return pct_http_response( array( 'access_token' => 't2', 'expires_in' => 3600 ) ); }
	if ( false !== strpos( $url, '/about' ) ) { return pct_http_response( array( 'user' => array( 'emailAddress' => 'owner@example.com' ), 'storageQuota' => array( 'usage' => 10, 'limit' => 1000 ) ) ); }
	if ( false !== strpos( $url, '/models' ) ) { return pct_http_response( array( 'error' => array( 'message' => 'Incorrect API key provided' ) ), 401 ); }
	return pct_http_response( array( 'files' => array() ) );
};
$d = pct_data( pct_rest( 'POST', '/admin/diagnose/drive' ) );
pct_assert( ! empty( $d['ok'] ) && 'drive' === $d['target'] && count( $d['checks'] ) >= 6, 'drive diagnostic ok with checks: ' . json_encode( array_column( $d['checks'], 'status' ) ) );
$blob = wp_json_encode( $d );
pct_assert( false === strpos( $blob, 'client-secret-XYZ' ) && false === strpos( $blob, 'refresh-token-SECRET' ) && false === strpos( $blob, 'owner@example.com' ), 'drive diagnostic leaks no secrets / full email' );
pct_assert( false !== strpos( $blob, 'o***@example.com' ) || false !== strpos( $blob, '@example.com' ), 'masked account shown' );
PCT_Settings::set( 'drive_refresh_token', '' );
$d = pct_data( pct_rest( 'POST', '/admin/diagnose/drive' ) );
pct_assert( ( in_array( 'error', array_column( $d['checks'], 'status' ), true ) || in_array( 'warn', array_column( $d['checks'], 'status' ), true ) ), 'disconnected Drive reported as error/warn, not exception' );
PCT_Settings::set( 'drive_refresh_token', 'refresh-token-SECRET' );

PCT_Settings::set( 'ai_provider', 'openai' );
PCT_Settings::set( 'ai_api_key', 'sk-super-secret-KEY' );
PCT_Settings::set( 'ai_sandbox', 1 );
$d = pct_data( pct_rest( 'POST', '/admin/diagnose/ai' ) );
$labels = array_column( $d['checks'], 'status', 'label' );
pct_assert( ! empty( $d['ok'] ) && 'ok' === ( $labels['API key'] ?? '' ) && 'ok' === ( $labels['Live request'] ?? '' ), 'AI diagnostic (sandbox) ok: ' . json_encode( $labels ) );
pct_assert( 'ok' === ( $labels['Export access links'] ?? '' ), 'AI diagnostic reports export links' );
pct_assert( false === strpos( wp_json_encode( $d ), 'sk-super-secret-KEY' ), 'AI diagnostic leaks no key' );
PCT_Settings::set( 'ai_api_key', '' );
$d = pct_data( pct_rest( 'POST', '/admin/diagnose/ai' ) );
pct_assert( empty( $d['ok'] ) && 'error' === array_column( $d['checks'], 'status', 'label' )['API key'], 'missing AI key -> error check' );
PCT_Settings::set( 'ai_api_key', 'sk-super-secret-KEY' );
pct_assert( ! empty( pct_data( pct_rest( 'POST', '/admin/ai-test' ) )['ok'] ), 'legacy /admin/ai-test still works' );

PCT_Settings::set( 'image_provider', 'mock' );
$d = pct_data( pct_rest( 'POST', '/admin/diagnose/image' ) );
pct_assert( ! empty( $d['ok'] ) && 'skip' === array_column( $d['checks'], 'status', 'label' )['Live request'], 'image diagnostic (mock) ok, live skipped' );
PCT_Settings::set( 'image_provider', 'openai' );
PCT_Settings::set( 'image_api_key', 'img-secret-KEY' );
update_option( PCT_Vibe::OPTION, array_merge( PCT_Vibe::settings(), array( 'sandbox' => 0 ) ) );
$d = pct_data( pct_rest( 'POST', '/admin/diagnose/image' ) );
$labels = array_column( $d['checks'], 'status', 'label' );
pct_assert( empty( $d['ok'] ) && 'error' === ( $labels['Live request'] ?? '' ), 'image 401 -> live request error: ' . json_encode( $labels ) );
pct_assert( false === strpos( wp_json_encode( $d ), 'img-secret-KEY' ), 'image diagnostic leaks no key' );
$GLOBALS['http_handler'] = function ( $url ) { return false !== strpos( $url, '/models' ) ? pct_http_response( array( 'data' => array( array( 'id' => 'gpt-image-1' ) ) ) ) : pct_http_response( array() ); };
$d = pct_data( pct_rest( 'POST', '/admin/diagnose/image' ) );
pct_assert( ! empty( $d['ok'] ), 'image diagnostic ok when /models authenticates' );

$d = pct_data( pct_rest( 'POST', '/admin/sync' ) );
pct_assert( isset( $d['ok'], $d['status']['state'], $d['message'], $d['latest'] ), 'admin/sync returns status + latest' );

echo "== settings sanitizer\n";
$out = PCT_Settings::sanitize( array( 'tab' => 'ai', 'ai_attach_drive_links' => '1', 'ai_consent_city_db' => '1' ) );
pct_assert( 1 === (int) $out['ai_attach_drive_links'] && 0 === (int) $out['ai_attach_upload_links'], 'attach flags sanitized independently' );

echo "\nFAILS: " . ( $GLOBALS['fails'] ?? 0 ) . "\n";
