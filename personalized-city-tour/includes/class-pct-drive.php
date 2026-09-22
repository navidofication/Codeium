<?php
/**
 * Optional Google Drive connector: OAuth 2.0 (offline refresh token), folder
 * mapping per export type, scheduled exports via WP Cron and retention.
 *
 * Nothing is uploaded unless the connector is configured by the admin. In
 * sandbox mode exports are written to wp-content/uploads/pct-exports instead.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PCT_Drive {

	const CRON_HOOK  = 'pct_drive_export';
	const STATE_OPT  = 'pct_drive_state';
	const LOG_OPT    = 'pct_drive_log';
	const SCOPE      = 'https://www.googleapis.com/auth/drive.file';
	const AUTH_URL   = 'https://accounts.google.com/o/oauth2/v2/auth';
	const TOKEN_URL  = 'https://oauth2.googleapis.com/token';
	const API_URL    = 'https://www.googleapis.com/drive/v3';
	const UPLOAD_URL = 'https://www.googleapis.com/upload/drive/v3/files?uploadType=multipart&fields=id,name';

	public static function init() {
		add_action( self::CRON_HOOK, array( __CLASS__, 'run_scheduled' ) );
		add_action( 'admin_post_pct_drive_connect', array( __CLASS__, 'handle_connect' ) );
		add_action( 'admin_post_pct_drive_callback', array( __CLASS__, 'handle_callback' ) );
		add_action( 'admin_post_nopriv_pct_drive_callback', array( __CLASS__, 'handle_callback' ) );
		add_action( 'admin_post_pct_drive_disconnect', array( __CLASS__, 'handle_disconnect' ) );
		add_action( 'admin_post_pct_drive_run', array( __CLASS__, 'handle_run' ) );
	}

	public static function export_types() {
		return array(
			'cities'   => __( 'City database (cities, places, events)', 'personalized-city-tour' ),
			'forms'    => __( 'Submitted travel preference forms', 'personalized-city-tour' ),
			'tours'    => __( 'Generated tours', 'personalized-city-tour' ),
			'sessions' => __( 'Visitor sessions / audit summaries', 'personalized-city-tour' ),
		);
	}

	public static function schedules() {
		return array(
			'none'       => __( 'Manual only', 'personalized-city-tour' ),
			'hourly'     => __( 'Hourly', 'personalized-city-tour' ),
			'twicedaily' => __( 'Twice daily', 'personalized-city-tour' ),
			'daily'      => __( 'Daily', 'personalized-city-tour' ),
			'weekly'     => __( 'Weekly', 'personalized-city-tour' ),
		);
	}

	public static function redirect_uri() {
		return admin_url( 'admin-post.php?action=pct_drive_callback' );
	}

	public static function configured() {
		return '' !== (string) PCT_Settings::get( 'drive_client_id' ) && PCT_Settings::has_secret( 'drive_client_secret' );
	}

	public static function connected() {
		return self::configured() && PCT_Settings::has_secret( 'drive_refresh_token' );
	}

	public static function sandbox() {
		return (bool) PCT_Settings::get( 'drive_sandbox', 1 );
	}

	/**
	 * (Re)schedule the export cron according to settings.
	 */
	public static function reschedule( $settings = null ) {
		wp_clear_scheduled_hook( self::CRON_HOOK );
		$schedule = PCT_Settings::get( 'drive_schedule', 'none' );
		if ( 'none' === $schedule || ! PCT_Settings::get( 'drive_enabled' ) ) {
			return;
		}
		if ( ! isset( wp_get_schedules()[ $schedule ] ) ) {
			$schedule = 'daily';
		}
		wp_schedule_event( time() + MINUTE_IN_SECONDS, $schedule, self::CRON_HOOK );
	}

	/* ------------------------------------------------------------------ */
	/* OAuth                                                               */
	/* ------------------------------------------------------------------ */

	public static function handle_connect() {
		check_admin_referer( 'pct_drive_connect' );
		if ( ! current_user_can( 'pct_manage' ) || ! self::configured() ) {
			wp_die( esc_html__( 'Configure the client ID and secret first.', 'personalized-city-tour' ) );
		}
		$state = wp_generate_password( 24, false, false );
		set_transient( 'pct_drive_oauth_' . $state, get_current_user_id(), 15 * MINUTE_IN_SECONDS );
		$url = add_query_arg(
			array(
				'client_id'     => PCT_Settings::get( 'drive_client_id' ),
				'redirect_uri'  => self::redirect_uri(),
				'response_type' => 'code',
				'scope'         => self::SCOPE,
				'access_type'   => 'offline',
				'prompt'        => 'consent',
				'state'         => $state,
			),
			self::AUTH_URL
		);
		wp_redirect( $url ); // phpcs:ignore WordPress.Security.SafeRedirect
		exit;
	}

	public static function handle_callback() {
		$state = isset( $_GET['state'] ) ? sanitize_text_field( wp_unslash( $_GET['state'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$code  = isset( $_GET['code'] ) ? sanitize_text_field( wp_unslash( $_GET['code'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$uid   = $state ? (int) get_transient( 'pct_drive_oauth_' . $state ) : 0;
		delete_transient( 'pct_drive_oauth_' . $state );
		$msg = 'drive_error';
		if ( $uid && $code && user_can( $uid, 'pct_manage' ) ) {
			$res = wp_remote_post(
				self::TOKEN_URL,
				array(
					'timeout' => 20,
					'body'    => array(
						'code'          => $code,
						'client_id'     => PCT_Settings::get( 'drive_client_id' ),
						'client_secret' => PCT_Settings::secret( 'drive_client_secret' ),
						'redirect_uri'  => self::redirect_uri(),
						'grant_type'    => 'authorization_code',
					),
				)
			);
			$data = is_wp_error( $res ) ? array() : json_decode( wp_remote_retrieve_body( $res ), true );
			if ( ! empty( $data['refresh_token'] ) ) {
				PCT_Settings::set( 'drive_refresh_token', $data['refresh_token'] );
				self::set_state( array( 'access_token' => $data['access_token'] ?? '', 'expires' => time() + (int) ( $data['expires_in'] ?? 0 ) - 60 ) );
				$msg = 'drive_connected';
			}
		}
		wp_safe_redirect( add_query_arg( array( 'page' => 'pct-settings', 'tab' => 'drive', 'pct_msg' => $msg ), admin_url( 'admin.php' ) ) );
		exit;
	}

	public static function handle_disconnect() {
		check_admin_referer( 'pct_drive_disconnect' );
		if ( ! current_user_can( 'pct_manage' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'personalized-city-tour' ) );
		}
		PCT_Settings::set( 'drive_refresh_token', '' );
		delete_option( self::STATE_OPT );
		wp_safe_redirect( add_query_arg( array( 'page' => 'pct-settings', 'tab' => 'drive', 'pct_msg' => 'drive_disconnected' ), admin_url( 'admin.php' ) ) );
		exit;
	}

	public static function handle_run() {
		check_admin_referer( 'pct_drive_run' );
		if ( ! current_user_can( 'pct_manage' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'personalized-city-tour' ) );
		}
		$results = self::run_exports();
		$failed  = array_filter( $results, 'is_wp_error' );
		wp_safe_redirect( add_query_arg( array( 'page' => 'pct-settings', 'tab' => 'drive', 'pct_msg' => $failed ? 'drive_partial' : 'drive_exported' ), admin_url( 'admin.php' ) ) );
		exit;
	}

	private static function set_state( array $state ) {
		update_option( self::STATE_OPT, array_merge( (array) get_option( self::STATE_OPT, array() ), $state ), false );
	}

	/**
	 * Valid access token, refreshing when needed.
	 */
	public static function access_token() {
		$state = (array) get_option( self::STATE_OPT, array() );
		if ( ! empty( $state['access_token'] ) && ! empty( $state['expires'] ) && $state['expires'] > time() ) {
			return $state['access_token'];
		}
		if ( ! self::connected() ) {
			return new WP_Error( 'pct_drive_not_connected', __( 'Google Drive is not connected.', 'personalized-city-tour' ) );
		}
		$res = wp_remote_post(
			self::TOKEN_URL,
			array(
				'timeout' => 20,
				'body'    => array(
					'refresh_token' => PCT_Settings::secret( 'drive_refresh_token' ),
					'client_id'     => PCT_Settings::get( 'drive_client_id' ),
					'client_secret' => PCT_Settings::secret( 'drive_client_secret' ),
					'grant_type'    => 'refresh_token',
				),
			)
		);
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$data = json_decode( wp_remote_retrieve_body( $res ), true );
		if ( empty( $data['access_token'] ) ) {
			return new WP_Error( 'pct_drive_token', isset( $data['error_description'] ) ? $data['error_description'] : __( 'Could not refresh the Google Drive token.', 'personalized-city-tour' ) );
		}
		self::set_state( array( 'access_token' => $data['access_token'], 'expires' => time() + (int) ( $data['expires_in'] ?? 3600 ) - 60 ) );
		return $data['access_token'];
	}

	/* ------------------------------------------------------------------ */
	/* Exports                                                             */
	/* ------------------------------------------------------------------ */

	public static function run_scheduled() {
		if ( PCT_Settings::get( 'drive_enabled' ) ) {
			self::run_exports();
		}
	}

	/**
	 * Export every enabled type. Returns [ type => file info | WP_Error ].
	 */
	public static function run_exports( array $types = array() ) {
		$enabled = $types ? $types : (array) PCT_Settings::get( 'drive_exports', array() );
		$results = array();
		$log     = (array) get_option( self::LOG_OPT, array() );
		foreach ( $enabled as $type ) {
			if ( ! isset( self::export_types()[ $type ] ) ) {
				continue;
			}
			$payload = self::build_export( $type );
			$name    = sprintf( 'pct-%s-%s.json', $type, current_time( 'Ymd-His' ) );
			$result  = self::store( $type, $name, wp_json_encode( $payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT ), 'application/json' );
			$results[ $type ] = $result;
			$log[] = array(
				'time'   => current_time( 'mysql' ),
				'type'   => $type,
				'file'   => $name,
				'status' => is_wp_error( $result ) ? 'error' : ( self::sandbox() ? 'sandbox' : 'uploaded' ),
				'detail' => is_wp_error( $result ) ? $result->get_error_message() : ( $result['id'] ?? '' ),
			);
		}
		update_option( self::LOG_OPT, array_slice( $log, -50 ), false );
		self::apply_retention();
		return $results;
	}

	public static function log() {
		return array_reverse( (array) get_option( self::LOG_OPT, array() ) );
	}

	public static function build_export( $type ) {
		$out = array(
			'plugin'    => 'personalized-city-tour',
			'version'   => PCT_VERSION,
			'type'      => $type,
			'exported'  => current_time( 'mysql' ),
			'site'      => home_url(),
			'records'   => array(),
		);
		switch ( $type ) {
			case 'cities':
				foreach ( PCT_Data::published_cities() as $city ) {
					$full           = PCT_Data::city( $city['id'] );
					$full['places'] = PCT_Data::places( $city['id'] );
					$full['events'] = PCT_Data::events( $city['id'] );
					$out['records'][] = $full;
				}
				break;
			case 'forms':
			case 'tours':
				$posts = get_posts( array( 'post_type' => PCT_Post_Types::TOUR, 'post_status' => 'any', 'numberposts' => 500, 'orderby' => 'date', 'order' => 'DESC' ) );
				foreach ( $posts as $p ) {
					$row = array(
						'id'      => $p->ID,
						'title'   => $p->post_title,
						'date'    => $p->post_date,
						'city_id' => (int) get_post_meta( $p->ID, '_pct_city_id', true ),
					);
					if ( 'forms' === $type ) {
						$row['form'] = get_post_meta( $p->ID, '_pct_form', true );
					} else {
						$row['tour'] = get_post_meta( $p->ID, '_pct_result', true );
					}
					$out['records'][] = $row;
				}
				break;
			case 'sessions':
				$out['records'] = array(
					'ai_stats'   => PCT_AI_Log::stats( 30 ),
					'recent_ai'  => PCT_AI_Log::query( array( 'per_page' => 200 ) )['items'],
					'tour_count' => (int) wp_count_posts( PCT_Post_Types::TOUR )->private,
				);
				break;
		}
		return apply_filters( 'pct_drive_export', $out, $type );
	}

	/**
	 * Upload (or store locally in sandbox mode).
	 */
	public static function store( $type, $name, $content, $mime ) {
		if ( self::sandbox() || ! self::connected() ) {
			$upload = wp_upload_dir();
			$dir    = trailingslashit( $upload['basedir'] ) . 'pct-exports';
			if ( ! wp_mkdir_p( $dir ) ) {
				return new WP_Error( 'pct_drive_dir', __( 'Could not create the export directory.', 'personalized-city-tour' ) );
			}
			if ( ! file_exists( $dir . '/index.html' ) ) {
				file_put_contents( $dir . '/index.html', '' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			}
			if ( ! file_exists( $dir . '/.htaccess' ) ) {
				file_put_contents( $dir . '/.htaccess', "Require all denied\nDeny from all\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			}
			$path = $dir . '/' . sanitize_file_name( $name );
			if ( false === file_put_contents( $path, $content ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions
				return new WP_Error( 'pct_drive_write', __( 'Could not write the export file.', 'personalized-city-tour' ) );
			}
			return array( 'id' => $path, 'name' => $name, 'sandbox' => true );
		}

		$token = self::access_token();
		if ( is_wp_error( $token ) ) {
			return $token;
		}
		$folders = (array) PCT_Settings::get( 'drive_folders', array() );
		$meta    = array( 'name' => $name, 'mimeType' => $mime );
		if ( ! empty( $folders[ $type ] ) ) {
			$meta['parents'] = array( $folders[ $type ] );
		}
		$boundary = 'pct' . wp_generate_password( 16, false, false );
		$body     = "--$boundary\r\nContent-Type: application/json; charset=UTF-8\r\n\r\n" . wp_json_encode( $meta )
			. "\r\n--$boundary\r\nContent-Type: $mime\r\n\r\n" . $content . "\r\n--$boundary--";
		$res = wp_remote_post(
			self::UPLOAD_URL,
			array(
				'timeout' => 60,
				'headers' => array(
					'Authorization' => 'Bearer ' . $token,
					'Content-Type'  => 'multipart/related; boundary=' . $boundary,
				),
				'body'    => $body,
			)
		);
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$data = json_decode( wp_remote_retrieve_body( $res ), true );
		if ( empty( $data['id'] ) ) {
			return new WP_Error( 'pct_drive_upload', isset( $data['error']['message'] ) ? $data['error']['message'] : __( 'Upload failed.', 'personalized-city-tour' ) );
		}
		return array( 'id' => $data['id'], 'name' => $data['name'] ?? $name, 'sandbox' => false );
	}

	/**
	 * Delete exports older than the retention period (Drive files created by the
	 * plugin, or local sandbox files).
	 */
	public static function apply_retention() {
		$days = (int) PCT_Settings::get( 'drive_retention_days', 90 );
		if ( $days <= 0 ) {
			return;
		}
		$cutoff = time() - $days * DAY_IN_SECONDS;
		$upload = wp_upload_dir();
		$dir    = trailingslashit( $upload['basedir'] ) . 'pct-exports';
		if ( is_dir( $dir ) ) {
			foreach ( glob( $dir . '/pct-*.json' ) ?: array() as $file ) {
				if ( filemtime( $file ) < $cutoff ) {
					wp_delete_file( $file );
				}
			}
		}
		if ( self::sandbox() || ! self::connected() ) {
			return;
		}
		$token = self::access_token();
		if ( is_wp_error( $token ) ) {
			return;
		}
		$q   = "name contains 'pct-' and createdTime < '" . gmdate( 'c', $cutoff ) . "' and trashed = false";
		$res = wp_remote_get( add_query_arg( array( 'q' => $q, 'fields' => 'files(id,name)', 'pageSize' => 100 ), self::API_URL . '/files' ), array( 'timeout' => 20, 'headers' => array( 'Authorization' => 'Bearer ' . $token ) ) );
		if ( is_wp_error( $res ) ) {
			return;
		}
		$data = json_decode( wp_remote_retrieve_body( $res ), true );
		foreach ( (array) ( $data['files'] ?? array() ) as $file ) {
			wp_remote_request( self::API_URL . '/files/' . rawurlencode( $file['id'] ), array( 'method' => 'DELETE', 'timeout' => 20, 'headers' => array( 'Authorization' => 'Bearer ' . $token ) ) );
		}
	}
}
