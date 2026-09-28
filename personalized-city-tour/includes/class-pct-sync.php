<?php
/**
 * Save-triggered refresh of the "online data" used by the AI.
 *
 * Whenever a city, place or event is saved, trashed or deleted the city
 * database is marked dirty and a single debounced WP-Cron event rewrites the
 * city export (local snapshot in uploads/pct-exports, plus Google Drive when
 * connected). Failures are retried with back-off and recorded in a status
 * option that the settings page and diagnostics show. If WP-Cron does not run
 * (DISABLE_WP_CRON, low traffic) the refresh is executed lazily on the next
 * admin request or right before the next AI tour generation, so the AI never
 * receives links to a stale snapshot.
 *
 * Export files are served through signed, expiring links
 * (/pct/v1/exports/<file>?exp=…&sig=…) so the protected uploads folder never
 * has to be public.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PCT_Sync {

	const CRON_HOOK  = 'pct_sync_refresh';
	const STATUS_OPT = 'pct_sync_status';
	const LOCK       = 'pct_sync_lock';
	const DEBOUNCE   = 60;           // seconds to wait after the last save
	const OVERDUE    = 5 * 60;       // run inline when the cron is this late
	const LINK_TTL   = 24 * 3600;    // signed export links validity

	/** @var int[] Retry delays in seconds. */
	private static $backoff = array( 300, 900, 3600 );

	public static function init() {
		add_action( self::CRON_HOOK, array( __CLASS__, 'run' ) );
		foreach ( array( PCT_Post_Types::CITY, PCT_Post_Types::PLACE, PCT_Post_Types::EVENT ) as $type ) {
			add_action( 'save_post_' . $type, array( __CLASS__, 'on_save' ), 30, 3 );
		}
		add_action( 'deleted_post', array( __CLASS__, 'on_delete' ), 10, 2 );
		add_action( 'trashed_post', array( __CLASS__, 'on_status_change' ) );
		add_action( 'untrashed_post', array( __CLASS__, 'on_status_change' ) );
		add_action( 'admin_init', array( __CLASS__, 'maybe_run_overdue' ) );
		add_action( 'admin_post_pct_sync_run', array( __CLASS__, 'handle_run' ) );
	}

	/* ------------------------------------------------------------------ */
	/* Triggers                                                            */
	/* ------------------------------------------------------------------ */

	public static function on_save( $post_id, $post = null, $update = false ) {
		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || wp_is_post_revision( $post_id ) ) {
			return;
		}
		if ( $post && 'auto-draft' === $post->post_status ) {
			return;
		}
		self::mark_dirty( $post ? $post->post_type . ':' . $post_id : (string) $post_id );
	}

	public static function on_delete( $post_id, $post = null ) {
		$post = $post ? $post : get_post( $post_id );
		if ( $post && in_array( $post->post_type, array( PCT_Post_Types::CITY, PCT_Post_Types::PLACE, PCT_Post_Types::EVENT ), true ) ) {
			self::mark_dirty( 'deleted:' . $post_id );
		}
	}

	public static function on_status_change( $post_id ) {
		$post = get_post( $post_id );
		if ( $post && in_array( $post->post_type, array( PCT_Post_Types::CITY, PCT_Post_Types::PLACE, PCT_Post_Types::EVENT ), true ) ) {
			self::mark_dirty( 'status:' . $post_id );
		}
	}

	/**
	 * Remember that the export is stale and (re)schedule the debounced run.
	 */
	public static function mark_dirty( $reason = '' ) {
		$s = self::status();
		if ( empty( $s['dirty_since'] ) ) {
			$s['dirty_since'] = time();
		}
		$s['dirty']       = 1;
		$s['reason']      = (string) $reason;
		$s['attempts']    = 0;
		$s['state']       = 'pending';
		$s['next_run']    = time() + self::DEBOUNCE;
		self::save_status( $s );
		self::schedule( $s['next_run'] );
		PCT_Tour_Generator::flush_cache();
	}

	private static function schedule( $time ) {
		wp_clear_scheduled_hook( self::CRON_HOOK );
		wp_schedule_single_event( $time, self::CRON_HOOK );
	}

	/* ------------------------------------------------------------------ */
	/* Runner                                                              */
	/* ------------------------------------------------------------------ */

	/**
	 * Cron callback: rewrite the city export and record the outcome.
	 *
	 * @return array Status after the run.
	 */
	public static function run() {
		$s = self::status();
		if ( empty( $s['dirty'] ) ) {
			return $s;
		}
		if ( get_transient( self::LOCK ) ) {
			return $s;
		}
		set_transient( self::LOCK, 1, 10 * MINUTE_IN_SECONDS );

		$s['attempts'] = (int) $s['attempts'] + 1;
		$s['last_run'] = time();
		$types         = self::types();
		$results       = PCT_Drive::run_exports( $types );
		$errors        = array();
		foreach ( $results as $type => $r ) {
			if ( is_wp_error( $r ) ) {
				$errors[] = $type . ': ' . $r->get_error_message();
			} elseif ( ! empty( $r['drive_error'] ) ) {
				$errors[] = $type . ' (Drive): ' . $r['drive_error'];
			}
		}
		if ( empty( $errors ) && count( $results ) === count( $types ) ) {
			$s['dirty']       = 0;
			$s['dirty_since'] = 0;
			$s['state']       = 'ok';
			$s['last_ok']     = time();
			$s['last_error']  = '';
			$s['next_run']    = 0;
			$s['attempts']    = 0;
			wp_clear_scheduled_hook( self::CRON_HOOK );
		} else {
			$s['last_error'] = implode( ' | ', $errors ? $errors : array( __( 'Export did not run for every type.', 'personalized-city-tour' ) ) );
			$idx             = $s['attempts'] - 1;
			if ( isset( self::$backoff[ $idx ] ) ) {
				$s['state']    = 'pending';
				$s['next_run'] = time() + self::$backoff[ $idx ];
				self::schedule( $s['next_run'] );
			} else {
				$s['state']    = 'failed';
				$s['next_run'] = 0;
				wp_clear_scheduled_hook( self::CRON_HOOK );
			}
		}
		self::save_status( $s );
		delete_transient( self::LOCK );
		do_action( 'pct_sync_finished', $s, $results );
		return $s;
	}

	/**
	 * Export types refreshed on save: always the city database, plus every
	 * other enabled Drive export type so backups stay current too.
	 */
	public static function types() {
		$types = array( 'cities' );
		if ( PCT_Settings::get( 'drive_enabled' ) ) {
			$types = array_merge( $types, (array) PCT_Settings::get( 'drive_exports', array() ) );
		}
		return array_values( array_unique( array_intersect( $types, array_keys( PCT_Drive::export_types() ) ) ) );
	}

	/**
	 * Fallback when WP-Cron is disabled or late: run inline once overdue.
	 */
	public static function maybe_run_overdue() {
		$s = self::status();
		if ( empty( $s['dirty'] ) || 'failed' === $s['state'] ) {
			return false;
		}
		$cron_off = defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON;
		$late     = $s['next_run'] && time() > $s['next_run'] + ( $cron_off ? 0 : self::OVERDUE );
		if ( $late || ( ! wp_next_scheduled( self::CRON_HOOK ) && time() > $s['next_run'] ) ) {
			self::run();
			return true;
		}
		return false;
	}

	/**
	 * Called before an AI tour request: make sure the snapshot the AI will be
	 * pointed to is not stale. Only runs inline when the debounce has passed.
	 */
	public static function ensure_fresh() {
		$s = self::status();
		if ( empty( $s['dirty'] ) ) {
			return true;
		}
		if ( 'failed' === $s['state'] ) {
			return false;
		}
		if ( time() >= $s['next_run'] ) {
			$s = self::run();
		}
		return empty( $s['dirty'] );
	}

	/**
	 * Force an immediate refresh (manual button / REST).
	 */
	public static function run_now( $reason = 'manual' ) {
		self::mark_dirty( $reason );
		$s             = self::status();
		$s['next_run'] = time();
		$s['attempts'] = 0;
		self::save_status( $s );
		return self::run();
	}

	public static function handle_run() {
		check_admin_referer( 'pct_sync_run' );
		if ( ! current_user_can( 'pct_manage' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'personalized-city-tour' ) );
		}
		$s = self::run_now();
		wp_safe_redirect( add_query_arg( array( 'page' => 'pct-settings', 'tab' => 'drive', 'pct_msg' => 'ok' === $s['state'] ? 'sync_ok' : 'sync_error' ), admin_url( 'admin.php' ) ) );
		exit;
	}

	/* ------------------------------------------------------------------ */
	/* Status                                                              */
	/* ------------------------------------------------------------------ */

	public static function status() {
		return wp_parse_args(
			(array) get_option( self::STATUS_OPT, array() ),
			array(
				'dirty'       => 0,
				'dirty_since' => 0,
				'state'       => 'idle',   // idle | pending | ok | failed
				'reason'      => '',
				'attempts'    => 0,
				'next_run'    => 0,
				'last_run'    => 0,
				'last_ok'     => 0,
				'last_error'  => '',
			)
		);
	}

	private static function save_status( array $s ) {
		update_option( self::STATUS_OPT, $s, false );
	}

	/**
	 * Human readable one-liner for the settings page / diagnostics.
	 */
	public static function describe( ?array $s = null ) {
		$s = $s ? $s : self::status();
		switch ( $s['state'] ) {
			case 'pending':
				return sprintf(
					/* translators: 1: date/time, 2: attempt number */
					__( 'Changes waiting — next refresh at %1$s (attempt %2$d).', 'personalized-city-tour' ),
					$s['next_run'] ? wp_date( 'Y-m-d H:i', $s['next_run'] ) : '—',
					(int) $s['attempts'] + 1
				) . ( $s['last_error'] ? ' ' . $s['last_error'] : '' );
			case 'failed':
				/* translators: %s: error message */
				return sprintf( __( 'Refresh failed after %1$d attempts: %2$s', 'personalized-city-tour' ), (int) $s['attempts'], $s['last_error'] );
			case 'ok':
				/* translators: %s: date/time */
				return sprintf( __( 'Up to date — last refresh %s.', 'personalized-city-tour' ), wp_date( 'Y-m-d H:i', $s['last_ok'] ) );
			default:
				return __( 'No changes recorded since activation.', 'personalized-city-tour' );
		}
	}

	/* ------------------------------------------------------------------ */
	/* Access links for the AI                                             */
	/* ------------------------------------------------------------------ */

	/**
	 * Links to the newest exports for the AI payload, according to the two
	 * consent options. Links are only produced for files that really exist.
	 *
	 * @return array List of { type, label, source: drive|uploads, url, updated, records }.
	 */
	public static function ai_links() {
		$drive   = (bool) PCT_Settings::get( 'ai_attach_drive_links' );
		$uploads = (bool) PCT_Settings::get( 'ai_attach_upload_links' );
		if ( ! $drive && ! $uploads ) {
			return array();
		}
		self::ensure_fresh();
		$labels = PCT_Drive::export_types();
		$out    = array();
		foreach ( PCT_Drive::latest() as $type => $row ) {
			if ( ! in_array( $type, array( 'cities' ), true ) && ! in_array( $type, (array) PCT_Settings::get( 'drive_exports', array() ), true ) ) {
				continue;
			}
			$base = array(
				'type'    => $type,
				'label'   => isset( $labels[ $type ] ) ? $labels[ $type ] : $type,
				'updated' => gmdate( 'c', (int) $row['time'] ),
				'records' => (int) ( $row['records'] ?? 0 ),
			);
			if ( $drive && ! empty( $row['drive_id'] ) ) {
				$link = ! empty( $row['link'] ) ? $row['link'] : 'https://drive.google.com/uc?export=download&id=' . rawurlencode( $row['drive_id'] );
				$out[] = array_merge( $base, array( 'source' => 'drive', 'url' => $link, 'updated' => gmdate( 'c', (int) ( $row['drive_time'] ?? $row['time'] ) ) ) );
			}
			if ( $uploads && ! empty( $row['path'] ) && file_exists( $row['path'] ) ) {
				$out[] = array_merge( $base, array( 'source' => 'uploads', 'url' => self::signed_url( basename( $row['path'] ) ) ) );
			}
		}
		return $out;
	}

	/**
	 * Signed, expiring URL for one export file in uploads/pct-exports.
	 */
	public static function signed_url( $name, $ttl = self::LINK_TTL ) {
		$exp = time() + max( 60, (int) $ttl );
		return add_query_arg(
			array(
				'exp' => $exp,
				'sig' => self::signature( $name, $exp ),
			),
			rest_url( PCT_Rest::NS . '/exports/' . rawurlencode( $name ) )
		);
	}

	public static function signature( $name, $exp ) {
		return hash_hmac( 'sha256', $name . '|' . (int) $exp, wp_salt( 'auth' ) );
	}

	/**
	 * REST callback streaming a signed export file.
	 */
	public static function serve( WP_REST_Request $request ) {
		$name = (string) $request['name'];
		$exp  = (int) $request->get_param( 'exp' );
		$sig  = (string) $request->get_param( 'sig' );
		if ( ! preg_match( '/^pct-[a-z]+-\d{8}-\d{6}\.json$/', $name ) ) {
			return new WP_Error( 'pct_export_name', __( 'Invalid export name.', 'personalized-city-tour' ), array( 'status' => 400 ) );
		}
		if ( $exp < time() || ! hash_equals( self::signature( $name, $exp ), $sig ) ) {
			return new WP_Error( 'pct_export_sig', __( 'This export link is invalid or has expired.', 'personalized-city-tour' ), array( 'status' => 403 ) );
		}
		$path = PCT_Drive::local_dir() . '/' . $name;
		if ( ! file_exists( $path ) ) {
			return new WP_Error( 'pct_export_missing', __( 'Export file not found.', 'personalized-city-tour' ), array( 'status' => 404 ) );
		}
		$json = json_decode( (string) file_get_contents( $path ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		$res  = rest_ensure_response( is_array( $json ) ? $json : array() );
		$res->header( 'X-Robots-Tag', 'noindex, nofollow' );
		$res->header( 'Cache-Control', 'private, max-age=300' );
		return $res;
	}
}
