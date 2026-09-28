<?php
/**
 * Center for Statistics, Information & Analysis.
 *
 * Analysis requests are stored as private pct_report posts and processed by a
 * WP Cron queue. Each job aggregates plugin data (tours, forms, city data, AI
 * usage) and either asks the configured AI provider for a structured analysis
 * (validated against PCT_Schema::analysis_schema()) or builds a deterministic
 * report. Results can be exported as PNG (client side), DOCX, PDF and XLSX.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PCT_Analytics {

	const CPT       = 'pct_report';
	const CRON_HOOK = 'pct_analytics_run';

	public static function init() {
		add_action( 'init', array( __CLASS__, 'register' ), 6 );
		add_action( self::CRON_HOOK, array( __CLASS__, 'run_queue' ) );
		add_action( 'admin_post_pct_analytics_request', array( __CLASS__, 'handle_request' ) );
		add_action( 'admin_post_pct_analytics_export', array( __CLASS__, 'handle_export' ) );
		add_action( 'admin_post_pct_analytics_delete', array( __CLASS__, 'handle_delete' ) );
		add_action( 'admin_post_pct_analytics_run_now', array( __CLASS__, 'handle_run_now' ) );
	}

	public static function register() {
		register_post_type(
			self::CPT,
			array(
				'labels'          => array( 'name' => __( 'Analysis reports', 'personalized-city-tour' ), 'singular_name' => __( 'Analysis report', 'personalized-city-tour' ) ),
				'public'          => false,
				'show_ui'         => false,
				'supports'        => array( 'title' ),
				'capability_type' => 'post',
				'map_meta_cap'    => true,
			)
		);
	}

	/**
	 * Reports live in the posts table; nothing to create, but keep the
	 * activation contract explicit and make sure the queue is scheduled.
	 */
	public static function install() {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + 5 * MINUTE_IN_SECONDS, 'hourly', self::CRON_HOOK );
		}
	}

	/* ------------------------------------------------------------------ */
	/* Access                                                              */
	/* ------------------------------------------------------------------ */

	public static function can_access( $user_id = 0 ) {
		$user = $user_id ? get_userdata( $user_id ) : wp_get_current_user();
		if ( ! $user || ! $user->exists() ) {
			return false;
		}
		if ( user_can( $user, 'pct_manage' ) ) {
			return true;
		}
		$roles = (array) PCT_Settings::get( 'analytics_roles', array( 'administrator' ) );
		return (bool) array_intersect( $roles, (array) $user->roles );
	}

	public static function audit( $action, $detail = '' ) {
		PCT_AI_Log::log(
			array(
				'kind'     => 'audit',
				'provider' => 'analytics',
				'status'   => 'ok',
				'title'    => $action,
				'error'    => $detail ? wp_json_encode( $detail, JSON_UNESCAPED_UNICODE ) : '',
				'force'    => true,
			)
		);
	}

	/* ------------------------------------------------------------------ */
	/* Dataset                                                             */
	/* ------------------------------------------------------------------ */

	/**
	 * Aggregated, anonymous dataset for a scope.
	 *
	 * @param array $scope city_id, days, fields (form field ids to break down).
	 */
	public static function dataset( array $scope ) {
		$days    = max( 1, min( 730, (int) ( $scope['days'] ?? 90 ) ) );
		$city_id = (int) ( $scope['city_id'] ?? 0 );
		$schema  = PCT_Form_Builder::get_schema();
		$map     = PCT_Form_Builder::field_map( $schema );
		$fields  = array_values( array_filter( (array) ( $scope['fields'] ?? array() ), array( __CLASS__, 'is_aggregatable_field' ) ) );
		$since   = gmdate( 'Y-m-d 00:00:00', current_time( 'timestamp' ) - $days * DAY_IN_SECONDS ); // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp

		$args = array(
			'post_type'      => PCT_Post_Types::TOUR,
			'post_status'    => 'any',
			'posts_per_page' => 2000,
			'date_query'     => array( array( 'after' => $since ) ),
			'fields'         => 'ids',
		);
		if ( $city_id ) {
			$args['meta_key']   = '_pct_city_id'; // phpcs:ignore WordPress.DB.SlowDBQuery
			$args['meta_value'] = $city_id; // phpcs:ignore WordPress.DB.SlowDBQuery
		}
		$tour_ids = get_posts( $args );

		$by_city  = array();
		$by_day   = array();
		$by_lang  = array();
		$provider = array();
		$stops    = array();
		$dist     = array();
		foreach ( $fields as $f ) {
			$dist[ $f ] = array();
		}
		foreach ( $tour_ids as $id ) {
			$city = get_the_title( (int) get_post_meta( $id, '_pct_city_id', true ) );
			$by_city[ $city ] = ( $by_city[ $city ] ?? 0 ) + 1;
			$day = substr( get_post_field( 'post_date', $id ), 0, 10 );
			$by_day[ $day ] = ( $by_day[ $day ] ?? 0 ) + 1;
			$result = (array) get_post_meta( $id, '_pct_result', true );
			$form   = (array) get_post_meta( $id, '_pct_form', true );
			$lang   = (string) ( $result['lang'] ?? 'n/a' );
			$by_lang[ $lang ] = ( $by_lang[ $lang ] ?? 0 ) + 1;
			$prov = (string) ( $result['provider'] ?? 'rules' );
			$provider[ $prov ] = ( $provider[ $prov ] ?? 0 ) + 1;
			$n = 0;
			foreach ( (array) ( $result['days'] ?? array() ) as $d ) {
				$n += count( (array) ( $d['stops'] ?? array() ) );
			}
			$stops[] = $n;
			foreach ( $fields as $f ) {
				if ( ! isset( $form[ $f ] ) ) {
					continue;
				}
				foreach ( (array) $form[ $f ] as $v ) {
					$label = isset( $map[ $f ] ) ? PCT_Form_Builder::display_value( $map[ $f ], $v ) : (string) $v;
					$dist[ $f ][ $label ] = ( $dist[ $f ][ $label ] ?? 0 ) + 1;
				}
			}
		}
		ksort( $by_day );
		arsort( $by_city );

		$events = wp_count_posts( PCT_Post_Types::EVENT );
		$places = wp_count_posts( PCT_Post_Types::PLACE );

		return array(
			'scope'      => array( 'days' => $days, 'city' => $city_id ? get_the_title( $city_id ) : '', 'since' => $since ),
			'totals'     => array(
				'tours'            => count( $tour_ids ),
				'avg_stops'        => $stops ? round( array_sum( $stops ) / count( $stops ), 1 ) : 0,
				'cities'           => count( PCT_Post_Types::cities() ),
				'places'           => (int) $places->publish,
				'events_published' => (int) $events->publish,
				'events_pending'   => (int) $events->pending,
				'guides_pending'   => count( PCT_Guide_Profiles::all( PCT_Guide_Profiles::STATUS_PENDING ) ),
				'guides_approved'  => count( PCT_Guide_Profiles::all( PCT_Guide_Profiles::STATUS_APPROVED ) ) + count( PCT_Roles::guides( PCT_Roles::STATUS_APPROVED ) ),
			),
			'tours_by_city'     => $by_city,
			'tours_by_day'      => $by_day,
			'tours_by_language' => $by_lang,
			'tours_by_provider' => $provider,
			'form_fields'       => $dist,
			'ai'                => PCT_AI_Log::stats( $days ),
		);
	}

	/**
	 * Only closed-choice fields may be aggregated; identifiers, free text and dates are never broken down.
	 */
	public static function is_aggregatable_field( $field_id, array $map = array() ) {
		if ( in_array( $field_id, array( 'name', 'email', 'phone' ), true ) ) {
			return false;
		}
		if ( ! $map ) {
			$map = PCT_Form_Builder::field_map( PCT_Form_Builder::get_schema() );
		}
		if ( ! isset( $map[ $field_id ] ) ) {
			return false;
		}
		return ! in_array( $map[ $field_id ]['type'] ?? 'text', array( 'text', 'textarea', 'email', 'tel', 'date' ), true );
	}

	/* ------------------------------------------------------------------ */
	/* Jobs                                                                */
	/* ------------------------------------------------------------------ */

	public static function create_job( array $request ) {
		$title = sanitize_text_field( $request['title'] ?? '' );
		if ( '' === $title ) {
			$title = sprintf( __( 'Analysis %s', 'personalized-city-tour' ), current_time( 'Y-m-d H:i' ) );
		}
		$clean = array(
			'question' => sanitize_textarea_field( $request['question'] ?? '' ),
			'city_id'  => absint( $request['city_id'] ?? 0 ),
			'days'     => max( 1, min( 730, absint( $request['days'] ?? 90 ) ) ),
			'fields'   => array_values( array_filter( array_map( 'sanitize_key', (array) ( $request['fields'] ?? array() ) ) ) ),
			'use_ai'   => empty( $request['use_ai'] ) ? 0 : 1,
			'lang'     => PCT_I18n::normalize( $request['lang'] ?? PCT_I18n::current() ),
		);
		$id = wp_insert_post(
			array(
				'post_type'   => self::CPT,
				'post_status' => 'private',
				'post_title'  => $title,
				'post_author' => get_current_user_id(),
			),
			true
		);
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		update_post_meta( $id, '_pct_request', $clean );
		update_post_meta( $id, '_pct_status', 'queued' );
		self::audit( 'analysis_requested', array( 'id' => $id, 'title' => $title, 'use_ai' => $clean['use_ai'] ) );
		if ( ! wp_next_scheduled( self::CRON_HOOK ) || wp_next_scheduled( self::CRON_HOOK ) > time() + 120 ) {
			wp_schedule_single_event( time() + 10, self::CRON_HOOK );
		}
		return $id;
	}

	public static function get_job( $id ) {
		$post = get_post( (int) $id );
		if ( ! $post || self::CPT !== $post->post_type ) {
			return null;
		}
		return array(
			'id'       => $post->ID,
			'title'    => $post->post_title,
			'author'   => (int) $post->post_author,
			'created'  => $post->post_date,
			'status'   => (string) get_post_meta( $post->ID, '_pct_status', true ),
			'request'  => (array) get_post_meta( $post->ID, '_pct_request', true ),
			'result'   => (array) get_post_meta( $post->ID, '_pct_result', true ),
			'error'    => (string) get_post_meta( $post->ID, '_pct_error', true ),
			'finished' => (string) get_post_meta( $post->ID, '_pct_finished', true ),
		);
	}

	public static function jobs( $limit = 50 ) {
		$ids = get_posts( array( 'post_type' => self::CPT, 'post_status' => 'private', 'numberposts' => $limit, 'orderby' => 'date', 'order' => 'DESC', 'fields' => 'ids' ) );
		return array_filter( array_map( array( __CLASS__, 'get_job' ), $ids ) );
	}

	public static function run_queue() {
		$ids = get_posts(
			array(
				'post_type'   => self::CPT,
				'post_status' => 'private',
				'numberposts' => 3,
				'orderby'     => 'date',
				'order'       => 'ASC',
				'fields'      => 'ids',
				'meta_key'    => '_pct_status', // phpcs:ignore WordPress.DB.SlowDBQuery
				'meta_value'  => 'queued', // phpcs:ignore WordPress.DB.SlowDBQuery
			)
		);
		foreach ( $ids as $id ) {
			self::run_job( $id );
		}
		if ( count( $ids ) === 3 ) {
			wp_schedule_single_event( time() + 30, self::CRON_HOOK );
		}
		self::apply_retention();
	}

	public static function run_job( $id ) {
		$job = self::get_job( $id );
		if ( ! $job || 'queued' !== $job['status'] ) {
			return $job;
		}
		update_post_meta( $id, '_pct_status', 'running' );
		$dataset = self::dataset( $job['request'] );
		$result  = null;
		$source  = 'rules';

		if ( ! empty( $job['request']['use_ai'] ) && PCT_AI::enabled() ) {
			$ai = self::ai_report( $job, $dataset );
			if ( is_wp_error( $ai ) ) {
				update_post_meta( $id, '_pct_error', $ai->get_error_message() );
			} else {
				$result = $ai;
				$source = PCT_AI::sandbox() ? 'sandbox' : PCT_Settings::get( 'ai_provider' );
			}
		}
		if ( null === $result ) {
			$result = self::deterministic_report( $dataset, $job['request'] );
		}
		$result['source']   = $source;
		$result['dataset']  = $dataset;
		$result['finished'] = current_time( 'mysql' );
		update_post_meta( $id, '_pct_result', $result );
		update_post_meta( $id, '_pct_status', 'done' );
		update_post_meta( $id, '_pct_finished', $result['finished'] );
		self::audit( 'analysis_completed', array( 'id' => $id, 'source' => $source ) );
		return self::get_job( $id );
	}

	private static function ai_report( array $job, array $dataset ) {
		$lang   = $job['request']['lang'] ?? 'en';
		$system = 'You are a data analyst for a city tourism platform. Analyse the aggregated, anonymous statistics provided and answer the question. '
			. 'Return ONLY valid JSON: {"summary":"string","insights":["string"],"charts":[{"title":"string","type":"bar|line|pie","labels":["string"],"values":[number]}],"tables":[{"title":"string","columns":["string"],"rows":[["string"]]}]}. '
			. 'Write all texts in language "' . $lang . '".';
		$user   = wp_json_encode(
			array(
				'question' => $job['request']['question'] ? $job['request']['question'] : 'Summarise usage trends, most requested cities and traveller preferences.',
				'data'     => $dataset,
			),
			JSON_UNESCAPED_UNICODE
		);
		return PCT_AI::complete_json(
			$system,
			$user,
			array(
				'kind'            => 'analysis',
				'title'           => 'Analysis: ' . $job['title'],
				'schema'          => PCT_Schema::analysis_schema(),
				'skip_rate_limit' => true,
				'mock'            => function () use ( $dataset, $job ) {
					return self::deterministic_report( $dataset, $job['request'] );
				},
			)
		);
	}

	/**
	 * Report built without AI from the dataset.
	 */
	public static function deterministic_report( array $d, array $request = array() ) {
		$t        = $d['totals'];
		$top_city = $d['tours_by_city'] ? key( $d['tours_by_city'] ) : '';
		$summary  = sprintf(
			/* translators: 1: number of tours, 2: days, 3: average stops */
			__( '%1$d personalized tours were generated in the last %2$d days with an average of %3$s stops per tour.', 'personalized-city-tour' ),
			$t['tours'],
			$d['scope']['days'],
			$t['avg_stops']
		);
		if ( $top_city ) {
			/* translators: 1: city, 2: count */
			$summary .= ' ' . sprintf( __( 'The most requested city was %1$s (%2$d tours).', 'personalized-city-tour' ), $top_city, current( $d['tours_by_city'] ) );
		}
		$insights = array();
		/* translators: 1: published, 2: pending */
		$insights[] = sprintf( __( 'City database: %1$d published events, %2$d pending review.', 'personalized-city-tour' ), $t['events_published'], $t['events_pending'] );
		/* translators: 1: pending guides, 2: approved guides */
		$insights[] = sprintf( __( 'Local guides: %1$d awaiting verification, %2$d approved.', 'personalized-city-tour' ), $t['guides_pending'], $t['guides_approved'] );
		if ( ! empty( $d['ai']['total'] ) ) {
			/* translators: 1: AI calls, 2: invalid responses */
			$insights[] = sprintf( __( 'AI: %1$d requests, %2$d invalid responses.', 'personalized-city-tour' ), $d['ai']['total'], $d['ai']['invalid'] );
		}
		$charts = array();
		if ( $d['tours_by_day'] ) {
			$charts[] = array( 'title' => __( 'Tours per day', 'personalized-city-tour' ), 'type' => 'line', 'labels' => array_keys( $d['tours_by_day'] ), 'values' => array_values( $d['tours_by_day'] ) );
		}
		if ( $d['tours_by_city'] ) {
			$charts[] = array( 'title' => __( 'Tours by city', 'personalized-city-tour' ), 'type' => 'bar', 'labels' => array_keys( $d['tours_by_city'] ), 'values' => array_values( $d['tours_by_city'] ) );
		}
		if ( $d['tours_by_language'] ) {
			$charts[] = array( 'title' => __( 'Tours by language', 'personalized-city-tour' ), 'type' => 'pie', 'labels' => array_keys( $d['tours_by_language'] ), 'values' => array_values( $d['tours_by_language'] ) );
		}
		$tables = array(
			array(
				'title'   => __( 'Key figures', 'personalized-city-tour' ),
				'columns' => array( __( 'Metric', 'personalized-city-tour' ), __( 'Value', 'personalized-city-tour' ) ),
				'rows'    => array_map( function ( $k, $v ) { return array( (string) $k, (string) $v ); }, array_keys( $t ), array_values( $t ) ),
			),
		);
		foreach ( $d['form_fields'] as $field => $counts ) {
			if ( ! $counts ) {
				continue;
			}
			arsort( $counts );
			$charts[] = array( 'title' => $field, 'type' => 'bar', 'labels' => array_keys( $counts ), 'values' => array_values( $counts ) );
			$tables[] = array(
				'title'   => $field,
				'columns' => array( __( 'Answer', 'personalized-city-tour' ), __( 'Count', 'personalized-city-tour' ) ),
				'rows'    => array_map( function ( $k, $v ) { return array( (string) $k, (string) $v ); }, array_keys( $counts ), array_values( $counts ) ),
			);
		}
		return array( 'summary' => $summary, 'insights' => $insights, 'charts' => $charts, 'tables' => $tables );
	}

	public static function apply_retention() {
		$days = (int) PCT_Settings::get( 'analytics_retention_days', 365 );
		if ( $days <= 0 ) {
			return;
		}
		$old = get_posts( array( 'post_type' => self::CPT, 'post_status' => 'private', 'numberposts' => 50, 'fields' => 'ids', 'date_query' => array( array( 'before' => $days . ' days ago' ) ) ) );
		foreach ( $old as $id ) {
			wp_delete_post( $id, true );
		}
	}

	/* ------------------------------------------------------------------ */
	/* Admin handlers                                                       */
	/* ------------------------------------------------------------------ */

	private static function guard( $nonce ) {
		check_admin_referer( $nonce );
		if ( ! self::can_access() ) {
			wp_die( esc_html__( 'You do not have access to the analytics center.', 'personalized-city-tour' ) );
		}
	}

	private static function back( $msg, $extra = array() ) {
		wp_safe_redirect( add_query_arg( array_merge( array( 'page' => 'pct-analytics', 'pct_msg' => $msg ), $extra ), admin_url( 'admin.php' ) ) );
		exit;
	}

	public static function handle_request() {
		self::guard( 'pct_analytics_request' );
		$id = self::create_job( wp_unslash( $_POST ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		if ( is_wp_error( $id ) ) {
			self::back( 'error' );
		}
		if ( ! empty( $_POST['run_now'] ) ) {
			self::run_job( $id );
		}
		self::back( 'queued', array( 'report' => $id ) );
	}

	public static function handle_run_now() {
		self::guard( 'pct_analytics_run_now' );
		self::run_queue();
		self::back( 'ran' );
	}

	public static function handle_delete() {
		self::guard( 'pct_analytics_delete' );
		$id = absint( $_POST['report'] ?? 0 );
		if ( self::get_job( $id ) ) {
			wp_delete_post( $id, true );
			self::audit( 'analysis_deleted', array( 'id' => $id ) );
		}
		self::back( 'deleted' );
	}

	public static function handle_export() {
		self::guard( 'pct_analytics_export' );
		$id     = absint( $_GET['report'] ?? 0 );
		$format = sanitize_key( $_GET['format'] ?? 'xlsx' );
		$job    = self::get_job( $id );
		if ( ! $job || 'done' !== $job['status'] ) {
			self::back( 'error' );
		}
		self::audit( 'analysis_exported', array( 'id' => $id, 'format' => $format ) );
		PCT_Export::send( $job, $format );
		exit;
	}
}
