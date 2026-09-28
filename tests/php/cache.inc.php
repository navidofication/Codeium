<?php
/* Included from t-flows.php: AI plan cache + batching window. */
echo "== AI cache + batching window (sandbox)\n";
function pct_rest_tour( $method, $path, $body ) {
	foreach ( array_keys( $GLOBALS['transients'] ) as $k ) {
		if ( 0 === strpos( $k, 'pct_rl_tour_' ) ) {
			unset( $GLOBALS['transients'][ $k ] );
		}
	}
	return pct_rest( $method, $path, $body );
}
$tour_keys = function ( $locks = false ) {
	return array_values( array_filter( array_keys( $GLOBALS['transients'] ), function ( $k ) use ( $locks ) {
		return 0 === strpos( $k, 'pct_tour_' ) && ( $locks === ( '_lock' === substr( $k, -5 ) ) );
	} ) );
};
$cached_logs = function () {
	return (int) ( PCT_AI_Log::query( array( 'status' => 'cached' ) )['total'] ?? 0 );
};
PCT_Settings::set( 'ai_provider', 'openai' );
PCT_Settings::set( 'ai_sandbox', 1 );
PCT_Settings::set( 'ai_consent_city_db', 1 );
PCT_Settings::set( 'ai_cache_ttl', 60 );
PCT_Settings::set( 'ai_batch_window', 5 );
$GLOBALS['transients'] = array_filter( $GLOBALS['transients'], function ( $k ) { return 0 !== strpos( $k, 'pct_tour_' ) && 0 !== strpos( $k, 'pct_rl_tour_' ); }, ARRAY_FILTER_USE_KEY );

$c1 = pct_data( pct_rest_tour( 'POST', '/tours', array( 'city_id' => 1, 'form' => $answers ) ) );
pct_assert( 1 === count( $tour_keys() ), 'first AI plan stored in cache' );
pct_assert( 0 === count( $tour_keys( true ) ), 'batch lock released after call' );
pct_assert( 'sandbox' === ( $c1['result']['provider'] ?? '' ), 'provider recorded: ' . ( $c1['result']['provider'] ?? '' ) );

$n_logs   = $cached_logs();
$answers2 = $answers;
$answers2['name'] = 'Somebody Else';
$c2 = pct_data( pct_rest_tour( 'POST', '/tours', array( 'city_id' => 1, 'form' => $answers2 ) ) );
pct_assert( $cached_logs() === $n_logs + 1, 'same answers, different name -> cache hit logged' );
pct_assert( false !== strpos( $c2['result']['title'] ?? '', 'Somebody' ), 'cached plan still personalised: ' . ( $c2['result']['title'] ?? '' ) );

$answers3 = $answers;
$answers3['budget_tier'] = 'low' === ( $answers['budget_tier'] ?? '' ) ? 'high' : 'low';
pct_data( pct_rest_tour( 'POST', '/tours', array( 'city_id' => 1, 'form' => $answers3 ) ) );
pct_assert( $cached_logs() === $n_logs + 1, 'different answers -> no cache hit' );
pct_assert( 2 === count( $tour_keys() ), 'second payload cached separately' );

// Simulate an in-flight identical request: lock held, no cached answer yet.
$lockkey = $tour_keys()[0] . '_lock';
foreach ( $tour_keys() as $k ) {
	unset( $GLOBALS['transients'][ $k ] );
}
PCT_Settings::set( 'ai_batch_window', 1 );
$GLOBALS['transients'][ $lockkey ] = 1;
$t0 = microtime( true );
$c4 = pct_data( pct_rest_tour( 'POST', '/tours', array( 'city_id' => 1, 'form' => $answers ) ) );
$dt = microtime( true ) - $t0;
pct_assert( ! empty( $c4['result']['days'] ) && $dt >= 0.9, sprintf( 'in-flight duplicate waited %.1fs then fell back to rules (provider=%s)', $dt, $c4['result']['provider'] ?? '' ) );
pct_assert( 'rules' === ( $c4['result']['provider'] ?? '' ), 'no second provider call during batching window' );
unset( $GLOBALS['transients'][ $lockkey ] );

pct_data( pct_rest_tour( 'POST', '/tours', array( 'city_id' => 1, 'form' => $answers ) ) );
$n_logs = $cached_logs();
PCT_Tour_Generator::flush_cache();
pct_data( pct_rest_tour( 'POST', '/tours', array( 'city_id' => 1, 'form' => $answers ) ) );
pct_assert( $cached_logs() === $n_logs, 'flush_cache (generation bump) invalidates cached plans' );
PCT_Settings::set( 'ai_provider', 'none' );
PCT_Settings::set( 'ai_sandbox', 0 );
PCT_Settings::set( 'ai_consent_city_db', 0 );
