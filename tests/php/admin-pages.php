<?php
/* Render every admin page as administrator; report PHP notices/warnings. */
require __DIR__ . '/wp-stubs.php';
if ( ! is_dir( dirname( __DIR__ ) . "/.out" ) ) { mkdir( dirname( __DIR__ ) . "/.out", 0777, true ); }
set_error_handler( function ( $no, $str, $file, $line ) {
	fwrite( STDERR, "  PHP[$no] $str @ " . basename( $file ) . ":$line\n" );
	foreach ( array_slice( debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS ), 1, 5 ) as $fr ) {
		fwrite( STDERR, '      <- ' . basename( $fr['file'] ?? '?' ) . ':' . ( $fr['line'] ?? '?' ) . ' ' . ( $fr['function'] ?? '' ) . "\n" );
	}
	$GLOBALS['fails']++;
	return true;
} );
pct_seed_user( 1, 'admin', array( 'administrator' ) );
wp_set_current_user( 1 );
pct_seed_city();
pct_boot( true );

$pages = array(
	'dashboard'    => array( 'PCT_Admin', 'render_dashboard' ),
	'guides'       => array( 'PCT_Guides', 'render' ),
	'form-builder' => array( 'PCT_Admin', 'render_form_builder' ),
	'planner'      => array( 'PCT_Admin', 'render_planner' ),
	'analytics'    => array( 'PCT_Admin', 'render_analytics' ),
	'vibe'         => array( 'PCT_Admin', 'render_vibe' ),
	'ai-log'       => array( 'PCT_Admin', 'render_ai_log' ),
	'settings'     => array( 'PCT_Admin', 'render_settings' ),
);

/* seed some data so views have rows */
PCT_AI_Log::log( array( 'kind' => 'tour', 'status' => 'ok', 'provider' => 'sandbox', 'model' => 'mock', 'title' => 'Jane | en | Testville', 'duration_ms' => 120, 'force' => true ) );
PCT_AI_Log::log( array( 'kind' => 'tour', 'status' => 'invalid', 'provider' => 'openai', 'model' => 'gpt-4o-mini', 'title' => 'Bad', 'error' => 'schema: days required', 'force' => true ) );
$profile = PCT_Guide_Profiles::create( array( 'name' => 'Ali', 'mobile' => '09121234567', 'email' => 'ali@example.test', 'city_id' => 1, 'languages' => array( 'fa', 'en' ), 'notes' => 'hi' ) );
echo 'guide profile: ' . ( is_wp_error( $profile ) ? 'ERR ' . $profile->get_error_message() : 'id=' . $profile['id'] ) . "\n";

foreach ( $pages as $slug => $cb ) {
	$_GET = array( 'page' => 'pct-' . $slug );
	if ( 'settings' === $slug ) {
		foreach ( array_keys( PCT_Settings::tabs() ) as $tab ) {
			$_GET['tab'] = $tab;
			$html = pct_render( $cb );
			pct_assert( strlen( $html ) > 500 && false === strpos( $html, 'EXIT:' ), "settings/$tab renders (" . strlen( $html ) . ' bytes)' );
			file_put_contents( dirname( __DIR__ ) . "/.out/admin-settings-$tab.html", $html );
		}
		continue;
	}
	$html = pct_render( $cb );
	pct_assert( strlen( $html ) > 300 && false === strpos( $html, 'EXIT:' ), "$slug renders (" . strlen( $html ) . ' bytes)' );
	file_put_contents( dirname( __DIR__ ) . "/.out/admin-$slug.html", $html );
}
/* ai log detail + filters */
$_GET = array( 'page' => 'pct-ai-log', 'entry' => 2, 'kind' => 'tour', 'status' => 'invalid' );
$html = pct_render( array( 'PCT_Admin', 'render_ai_log' ) );
pct_assert( false !== strpos( $html, 'schema: days required' ), 'ai-log entry detail shows error' );

/* analytics: create a job and render report */
$job = PCT_Analytics::create_job( array( 'title' => 'Test report', 'question' => 'Who visits?', 'period' => 30, 'city_id' => 1, 'fields' => array( 'group_type' ), 'use_ai' => 0 ) );
echo 'job: ' . ( is_wp_error( $job ) ? 'ERR ' . $job->get_error_message() : json_encode( $job ) ) . "\n";
if ( ! is_wp_error( $job ) ) {
	$run = PCT_Analytics::run_job( $job );
	echo 'run: ' . ( is_wp_error( $run ) ? 'ERR ' . $run->get_error_message() : json_encode( array_intersect_key( (array) $run, array( 'status' => 1, 'report_id' => 1 ) ) ) ) . "\n";
	$_GET = array( 'page' => 'pct-analytics', 'job' => $job );
	$html = pct_render( array( 'PCT_Admin', 'render_analytics' ) );
	pct_assert( false !== strpos( $html, 'Test report' ), 'analytics report page renders job title' );
	file_put_contents( dirname( __DIR__ ) . '/.out/admin-analytics-report.html', $html );
}
echo "\nFAILS: " . $GLOBALS['fails'] . "\n";
