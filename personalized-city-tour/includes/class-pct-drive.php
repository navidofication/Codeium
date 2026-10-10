<?php
/**
 * Optional Google Drive connector: OAuth 2.0 (offline refresh token), folder
 * mapping per export type, scheduled exports via WP Cron and retention.
 *
 * Every export is first written to wp-content/uploads/pct-exports (protected
 * by .htaccess, served only through signed links) and then uploaded to Drive
 * when the connector is connected and sandbox mode is off.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PCT_Drive {

	const CRON_HOOK  = 'pct_drive_export';
	const QUEUE_HOOK = 'pct_drive_export_deferred';
	const QUEUE_OPT  = 'pct_drive_deferred_queue';
	const STATE_OPT  = 'pct_drive_state';
	const LOG_OPT    = 'pct_drive_log';
	const LATEST_OPT = 'pct_drive_latest';
	const OAUTH_OPT  = 'pct_drive_oauth_last';
	const REFRESH_OPT = 'pct_drive_refresh_token';
	const SCOPE      = 'https://www.googleapis.com/auth/drive.file';
	const AUTH_URL   = 'https://accounts.google.com/o/oauth2/v2/auth';
	const TOKEN_URL  = 'https://oauth2.googleapis.com/token';
	const API_URL    = 'https://www.googleapis.com/drive/v3';
	const UPLOAD_URL = 'https://www.googleapis.com/upload/drive/v3/files?uploadType=multipart&fields=id,name,webViewLink';

	public static function init() {
		add_action( self::CRON_HOOK, array( __CLASS__, 'run_scheduled' ) );
		add_action( self::QUEUE_HOOK, array( __CLASS__, 'run_queued' ), 10, 1 );
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
		if ( ! self::configured() ) {
			return false;
		}
		if ( self::has_readable_refresh_token() ) {
			return true;
		}
		// A freshly completed OAuth exchange can still be used until the
		// short-lived access token expires. This keeps an immediate export
		// usable while persistent refresh-token storage is being verified.
		$state = (array) get_option( self::STATE_OPT, array() );
		return ! empty( $state['access_token'] ) && ! empty( $state['expires'] ) && (int) $state['expires'] > time();
	}

	public static function connection_status() {
		$state = (array) get_option( self::STATE_OPT, array() );
		$refresh = self::has_readable_refresh_token();
		$access = ! empty( $state['access_token'] );
		$expires = isset( $state['expires'] ) ? (int) $state['expires'] : 0;
		return array(
			'configured'     => self::configured(),
			'refresh_token'  => $refresh,
			'access_token'   => $access,
			'access_expires' => $expires,
			'connected'      => self::connected(),
		);
	}

	private static function refresh_token() {
		$stored = get_option( self::REFRESH_OPT, '' );
		if ( '' !== (string) $stored ) {
			$token = PCT_Secrets::decrypt( (string) $stored );
			if ( '' !== $token ) {
				return $token;
			}
		}
		return PCT_Settings::secret( 'drive_refresh_token' );
	}

	private static function has_readable_refresh_token() {
		return '' !== (string) self::refresh_token();
	}

	private static function save_refresh_token( $token ) {
		$token = trim( (string) $token );
		if ( '' === $token ) {
			return new WP_Error( 'pct_drive_empty_refresh', __( 'Google returned an empty refresh token.', 'personalized-city-tour' ) );
		}
		$encrypted = PCT_Secrets::encrypt( $token );
		if ( '' === $encrypted ) {
			return new WP_Error( 'pct_drive_encrypt_refresh', __( 'The refresh token could not be encrypted for storage.', 'personalized-city-tour' ) );
		}
		$updated = update_option( self::REFRESH_OPT, $encrypted, false );
		$roundtrip = PCT_Secrets::decrypt( (string) get_option( self::REFRESH_OPT, '' ) );
		if ( '' === $roundtrip || ! hash_equals( $token, (string) $roundtrip ) ) {
			return new WP_Error( 'pct_drive_refresh_storage', __( 'The refresh token could not be verified after saving it to the WordPress database.', 'personalized-city-tour' ) );
		}
		// Keep the original settings location synchronized for compatibility.
		PCT_Settings::set( 'drive_refresh_token', $token );
		return true;
	}

	private static function folder_id( $value ) {
		$value = trim( (string) $value );
		if ( '' === $value ) {
			return '';
		}
		if ( preg_match( '#/folders/([A-Za-z0-9_-]+)#', $value, $m ) ) {
			return $m[1];
		}
		if ( preg_match( '#[?&]id=([A-Za-z0-9_-]+)#', $value, $m ) ) {
			return $m[1];
		}
		$value = preg_replace( '/[?#].*$/', '', $value );
		return preg_match( '/^[A-Za-z0-9_-]+$/', $value ) ? $value : '';
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
				'include_granted_scopes' => 'true',
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
		$error = isset( $_GET['error'] ) ? sanitize_text_field( wp_unslash( $_GET['error'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$error_description = isset( $_GET['error_description'] ) ? sanitize_text_field( wp_unslash( $_GET['error_description'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$uid   = $state ? (int) get_transient( 'pct_drive_oauth_' . $state ) : 0;
		delete_transient( 'pct_drive_oauth_' . $state );
		$msg = 'drive_error';
		if ( $error ) {
			self::oauth_log( 'error', 'Google authorization was denied or interrupted: ' . $error . ( $error_description ? ' — ' . $error_description : '' ) );
		} elseif ( ! $uid || ! $code || ! user_can( $uid, 'pct_manage' ) ) {
			self::oauth_log( 'error', 'OAuth callback was rejected: invalid or expired state, missing code, or insufficient permission.' );
		} else {
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
			if ( is_wp_error( $res ) ) {
				self::oauth_log( 'error', 'WordPress could not contact Google token endpoint: ' . $res->get_error_message() );
			} else {
				$http = (int) wp_remote_retrieve_response_code( $res );
				$body = wp_remote_retrieve_body( $res );
				$data = json_decode( $body, true );
				if ( $http < 200 || $http >= 300 || ! is_array( $data ) ) {
					$detail = is_array( $data ) ? (string) ( $data['error_description'] ?? $data['error'] ?? '' ) : '';
					self::oauth_log( 'error', 'Google token exchange failed (HTTP ' . $http . ')' . ( $detail ? ': ' . $detail : '.' ) );
				} elseif ( empty( $data['access_token'] ) ) {
					self::oauth_log( 'error', 'Google token exchange returned no access token.' );
				} elseif ( ! empty( $data['refresh_token'] ) ) {
					$save = self::save_refresh_token( $data['refresh_token'] );
					self::set_state( array( 'access_token' => $data['access_token'], 'expires' => time() + (int) ( $data['expires_in'] ?? 3600 ) - 60 ) );
					if ( is_wp_error( $save ) ) {
						self::oauth_log( 'error', 'Google authorization succeeded, but persistent refresh-token storage failed: ' . $save->get_error_message() . ' The current access token is temporary.' );
						$msg = 'drive_error';
					} else {
						self::oauth_log( 'success', 'Google authorization completed and the refresh token was saved and verified in persistent storage.' );
						$msg = 'drive_connected';
					}
				} elseif ( self::connected() ) {
					// Google may omit refresh_token on subsequent authorizations. Preserve the existing one.
					self::set_state( array( 'access_token' => $data['access_token'], 'expires' => time() + (int) ( $data['expires_in'] ?? 3600 ) - 60 ) );
					self::oauth_log( 'success', 'Google authorization completed; existing refresh token was preserved because Google did not return a new one.' );
					$msg = 'drive_connected';
				} else {
					self::oauth_log( 'error', 'Google returned an access token but no refresh token. Re-authorize with consent and make sure this Google account is a test user if the OAuth app is in Testing mode.' );
				}
			}
		}
		wp_safe_redirect( add_query_arg( array( 'page' => 'pct-settings', 'tab' => 'drive', 'pct_msg' => $msg ), admin_url( 'admin.php' ) ) );
		exit;
	}

	private static function oauth_log( $status, $detail ) {
		$log = (array) get_option( self::LOG_OPT, array() );
		$log[] = array( 'time' => current_time( 'mysql' ), 'type' => 'oauth', 'file' => 'Google OAuth', 'status' => $status, 'detail' => wp_strip_all_tags( (string) $detail ) );
		update_option( self::LOG_OPT, array_slice( $log, -50 ), false );
		update_option( self::OAUTH_OPT, array( 'time' => time(), 'status' => $status, 'detail' => wp_strip_all_tags( (string) $detail ) ), false );
	}

	public static function handle_disconnect() {
		check_admin_referer( 'pct_drive_disconnect' );
		if ( ! current_user_can( 'pct_manage' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'personalized-city-tour' ) );
		}
		PCT_Settings::set( 'drive_refresh_token', '' );
		delete_option( self::REFRESH_OPT );
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
		$refresh = self::refresh_token();
		if ( '' === $refresh ) {
			return new WP_Error( 'pct_drive_not_connected', __( 'Google Drive is not connected: no readable refresh token is available. Reconnect Google Drive.', 'personalized-city-tour' ) );
		}
		$args = array(
			'timeout' => 20,
			'body'    => array(
				'refresh_token' => $refresh,
				'client_id'     => PCT_Settings::get( 'drive_client_id' ),
				'client_secret' => PCT_Settings::secret( 'drive_client_secret' ),
				'grant_type'    => 'refresh_token',
			),
		);
		$res  = wp_remote_post( self::TOKEN_URL, $args );
		if ( is_wp_error( $res ) || (int) wp_remote_retrieve_response_code( $res ) >= 500 ) {
			usleep( 500000 );
			$res = wp_remote_post( self::TOKEN_URL, $args );
		}
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$data = json_decode( wp_remote_retrieve_body( $res ), true );
		if ( empty( $data['access_token'] ) ) {
			$detail = isset( $data['error_description'] ) ? $data['error_description'] : ( isset( $data['error'] ) ? (string) $data['error'] : '' );
			if ( 'invalid_grant' === ( $data['error'] ?? '' ) ) {
				$detail = __( 'The refresh token was revoked or expired — disconnect and connect Google Drive again.', 'personalized-city-tour' );
			}
			return new WP_Error( 'pct_drive_token', $detail ? $detail : __( 'Could not refresh the Google Drive token.', 'personalized-city-tour' ) );
		}
		self::set_state( array( 'access_token' => $data['access_token'], 'expires' => time() + (int) ( $data['expires_in'] ?? 3600 ) - 60 ) );
		return $data['access_token'];
	}

	/* ------------------------------------------------------------------ */
	/* Exports                                                             */
	/* ------------------------------------------------------------------ */

	/**
	 * Queue one-off exports so visitor-facing requests never wait for Google
	 * Drive. The export itself still uses the existing run_exports() pipeline,
	 * preserving local snapshots, Drive upload behavior and admin logs.
	 *
	 * WordPress runs scheduled callbacks through WP-Cron after the originating
	 * request is able to return; this keeps slow Drive/token/network work out
	 * of the public Tour Generator response.
	 */
	public static function queue_exports( array $types = array(), $delay = 5 ) {
		$allowed = array_keys( self::export_types() );
		$types = array_values( array_unique( array_intersect( $allowed, array_map( 'sanitize_key', $types ) ) ) );
		if ( empty( $types ) ) {
			return false;
		}

		// Merge jobs into one small queue record. This avoids spawning a separate
		// full export for every visitor while still guaranteeing that all types
		// requested before the worker runs are included.
		$queued = (array) get_option( self::QUEUE_OPT, array() );
		$queued = array_values( array_unique( array_merge( $queued, $types ) ) );
		update_option( self::QUEUE_OPT, $queued, false );

		$next = wp_next_scheduled( self::QUEUE_HOOK );
		if ( ! $next ) {
			$delay = max( 1, min( 300, absint( $delay ) ) );
			$result = wp_schedule_single_event( time() + $delay, self::QUEUE_HOOK, array(), true );
			if ( is_wp_error( $result ) ) {
				self::oauth_log( 'error', 'Deferred export could not be queued: ' . $result->get_error_message() );
				return false;
			}
		}
		return true;
	}

	public static function run_queued() {
		$types = (array) get_option( self::QUEUE_OPT, array() );
		delete_option( self::QUEUE_OPT );
		if ( empty( $types ) ) {
			return;
		}
		self::run_exports( $types );
	}

	public static function run_scheduled() {
		if ( PCT_Settings::get( 'drive_enabled' ) ) {
			self::run_exports();
		}
	}

	/**
	 * Local directory for export snapshots (always written, also when Drive is
	 * connected, so the newest export is available for the AI link option).
	 */
	public static function local_dir() {
		$upload = wp_upload_dir();
		return trailingslashit( $upload['basedir'] ) . 'pct-exports';
	}

	/**
	 * Export every enabled type. Returns [ type => file info | WP_Error ].
	 * File info: { id, name, path, local (bool), drive_id, link }.
	 */
	public static function run_exports( array $types = array() ) {
		$enabled = $types ? $types : (array) PCT_Settings::get( 'drive_exports', array() );
		$results = array();
		$log     = (array) get_option( self::LOG_OPT, array() );
		$latest  = (array) get_option( self::LATEST_OPT, array() );
		if ( ! self::sandbox() && ! self::connected() ) {
			foreach ( $enabled as $type ) {
				if ( ! isset( self::export_types()[ $type ] ) ) {
					continue;
				}
				$error = new WP_Error( 'pct_drive_not_connected', __( 'Google Drive is not connected. Connect it first, or enable Sandbox mode for a local-only test.', 'personalized-city-tour' ) );
				$results[ $type ] = $error;
				$log[] = array( 'time' => current_time( 'mysql' ), 'type' => $type, 'file' => '', 'status' => 'error', 'detail' => $error->get_error_message() );
			}
			update_option( self::LOG_OPT, array_slice( $log, -50 ), false );
			return $results;
		}
		foreach ( $enabled as $type ) {
			if ( ! isset( self::export_types()[ $type ] ) ) {
				continue;
			}
			$payload = self::build_export( $type );
			$name    = sprintf( 'pct-%s-%s.json', $type, current_time( 'Ymd-His' ) );
			$result  = self::store( $type, $name, wp_json_encode( $payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT ), 'application/json' );
			$results[ $type ] = $result;
			$status           = 'error';
			if ( ! is_wp_error( $result ) ) {
				$status   = $result['drive_id'] ? 'uploaded' : ( $result['drive_error'] ? 'upload_failed' : ( self::sandbox() ? 'sandbox' : 'local' ) );
				$previous = isset( $latest[ $type ] ) ? $latest[ $type ] : array();
				$latest[ $type ] = array(
					'name'     => $result['name'],
					'path'     => $result['path'],
					'drive_id' => $result['drive_id'] ? $result['drive_id'] : '',
					'link'     => $result['drive_id'] ? $result['link'] : '',
					'time'     => time(),
					'records'  => is_array( $payload['records'] ) ? count( $payload['records'] ) : 0,
				);
				if ( ! $result['drive_id'] && ! empty( $previous['drive_id'] ) ) {
					// Keep pointing at the last file that really reached Drive, marked with its own age.
					$latest[ $type ]['drive_id']   = $previous['drive_id'];
					$latest[ $type ]['link']       = $previous['link'];
					$latest[ $type ]['drive_time'] = isset( $previous['drive_time'] ) ? $previous['drive_time'] : $previous['time'];
				} elseif ( $result['drive_id'] ) {
					$latest[ $type ]['drive_time'] = time();
				}
			}
			$log[] = array(
				'time'   => current_time( 'mysql' ),
				'type'   => $type,
				'file'   => $name,
				'status' => $status,
				'detail' => is_wp_error( $result ) ? $result->get_error_message() : ( $result['drive_id'] ? $result['drive_id'] : ( $result['drive_error'] ? $result['drive_error'] : $result['path'] ) ),
			);
		}
		update_option( self::LOG_OPT, array_slice( $log, -50 ), false );
		update_option( self::LATEST_OPT, $latest, false );
		self::apply_retention();
		return $results;
	}

	public static function log() {
		return array_reverse( (array) get_option( self::LOG_OPT, array() ) );
	}

	/**
	 * Newest successful export per type (from the last runs), verified against
	 * the file system so stale entries are never offered as links.
	 */
	public static function latest( $type = null ) {
		$latest = (array) get_option( self::LATEST_OPT, array() );
		foreach ( $latest as $t => $row ) {
			if ( empty( $row['path'] ) || ! file_exists( $row['path'] ) ) {
				$latest[ $t ]['path'] = '';
			}
			if ( empty( $latest[ $t ]['path'] ) && empty( $row['drive_id'] ) ) {
				unset( $latest[ $t ] );
			}
		}
		if ( null !== $type ) {
			return isset( $latest[ $type ] ) ? $latest[ $type ] : null;
		}
		return $latest;
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
		return apply_filters( 'pct_drive_export_payload', $out, $type );
	}

	/**
	 * Write the local snapshot, then upload to Drive when connected and not in
	 * sandbox mode. The local copy is kept even when the upload fails so that
	 * the newest export is always available on the server.
	 */
	public static function store( $type, $name, $content, $mime ) {
		$path = self::write_local( $name, $content );
		if ( is_wp_error( $path ) ) {
			return $path;
		}
		$result = array(
			'id'          => $path,
			'name'        => $name,
			'path'        => $path,
			'local'       => true,
			'drive_id'    => '',
			'link'        => '',
			'drive_error' => '',
		);
		if ( self::sandbox() ) {
			return $result;
		}
		if ( ! self::connected() ) {
			return new WP_Error( 'pct_drive_not_connected', __( 'The export was written locally, but Google Drive is not connected, so it was not uploaded.', 'personalized-city-tour' ), array( 'path' => $path ) );
		}
		$drive = self::upload( $type, $name, $content, $mime );
		if ( is_wp_error( $drive ) ) {
			$data = $drive->get_error_data();
			if ( ! is_array( $data ) ) {
				$data = array();
			}
			$data['path'] = $path;
			return new WP_Error( $drive->get_error_code(), $drive->get_error_message(), $data );
		}
		$result['id']       = $drive['id'];
		$result['drive_id'] = $drive['id'];
		$result['link']     = $drive['link'];
		return $result;
	}

	/**
	 * Write one file into the protected uploads/pct-exports directory.
	 *
	 * @return string|WP_Error Absolute path.
	 */
	public static function write_local( $name, $content ) {
		$dir = self::local_dir();
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
		return $path;
	}

	/**
	 * Multipart upload with one retry on transient failures.
	 *
	 * @return array|WP_Error { id, name, link }
	 */
	private static function upload( $type, $name, $content, $mime ) {
		$folders = (array) PCT_Settings::get( 'drive_folders', array() );
		$meta    = array( 'name' => $name, 'mimeType' => $mime );
		if ( ! empty( $folders[ $type ] ) ) {
			$folder = self::folder_id( $folders[ $type ] );
			if ( $folder ) {
				$meta['parents'] = array( $folder );
			}
		}
		$boundary = 'pct' . wp_generate_password( 16, false, false );
		$body     = "--$boundary\r\nContent-Type: application/json; charset=UTF-8\r\n\r\n" . wp_json_encode( $meta )
			. "\r\n--$boundary\r\nContent-Type: $mime\r\n\r\n" . $content . "\r\n--$boundary--";
		$data = self::api(
			'POST',
			self::UPLOAD_URL,
			array(
				'timeout' => 60,
				'headers' => array( 'Content-Type' => 'multipart/related; boundary=' . $boundary ),
				'body'    => $body,
			)
		);
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		if ( empty( $data['id'] ) ) {
			return new WP_Error( 'pct_drive_upload', __( 'Upload failed: Google Drive returned no file id.', 'personalized-city-tour' ) );
		}
		$link = (string) ( $data['webViewLink'] ?? '' );
		if ( PCT_Settings::get( 'ai_attach_drive_links' ) ) {
			$shared = self::share_public( $data['id'] );
			if ( ! is_wp_error( $shared ) && ! empty( $shared['link'] ) ) {
				$link = $shared['link'];
			}
		}
		return array( 'id' => $data['id'], 'name' => $data['name'] ?? $name, 'link' => $link );
	}

	/**
	 * Share a Drive file as "anyone with the link → viewer" and return the
	 * direct download link the AI can fetch.
	 *
	 * @return array|WP_Error { link }
	 */
	public static function share_public( $file_id ) {
		$perm = self::api(
			'POST',
			self::API_URL . '/files/' . rawurlencode( $file_id ) . '/permissions',
			array(
				'timeout' => 20,
				'headers' => array( 'Content-Type' => 'application/json' ),
				'body'    => wp_json_encode( array( 'role' => 'reader', 'type' => 'anyone' ) ),
			)
		);
		if ( is_wp_error( $perm ) ) {
			return $perm;
		}
		$info = self::api( 'GET', add_query_arg( 'fields', 'id,webViewLink,webContentLink', self::API_URL . '/files/' . rawurlencode( $file_id ) ), array( 'timeout' => 20 ) );
		if ( is_wp_error( $info ) ) {
			return $info;
		}
		$link = (string) ( $info['webContentLink'] ?? $info['webViewLink'] ?? '' );
		return array( 'link' => $link ? $link : 'https://drive.google.com/uc?export=download&id=' . rawurlencode( $file_id ) );
	}

	/**
	 * Read a JSON export directly from Google Drive when the local snapshot
	 * is unavailable. The Drive export is owned by the connected account and
	 * is never made public just for AI access.
	 *
	 * @return array|WP_Error
	 */
	public static function download_json( $file_id ) {
		$file_id = trim( (string) $file_id );
		if ( '' === $file_id ) {
			return new WP_Error( 'pct_drive_empty_file', __( 'Google Drive file id is empty.', 'personalized-city-tour' ) );
		}
		$data = self::api( 'GET', add_query_arg( 'alt', 'media', self::API_URL . '/files/' . rawurlencode( $file_id ) ), array( 'timeout' => 30 ) );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		return is_array( $data ) ? $data : new WP_Error( 'pct_drive_invalid_json', __( 'The Google Drive export did not contain valid JSON.', 'personalized-city-tour' ) );
	}

	/**
	 * Authenticated Drive API call returning decoded JSON. Retries once on
	 * network errors, 429 and 5xx; refreshes the token once on 401.
	 *
	 * @return array|WP_Error
	 */
	public static function api( $method, $url, array $args = array() ) {
		$token = self::access_token();
		if ( is_wp_error( $token ) ) {
			return $token;
		}
		$last = null;
		for ( $attempt = 1; $attempt <= 3; $attempt++ ) {
			$req                             = $args;
			$req['method']                   = $method;
			$req['headers']                  = isset( $args['headers'] ) ? $args['headers'] : array();
			$req['headers']['Authorization'] = 'Bearer ' . $token;
			$req['timeout']                  = isset( $args['timeout'] ) ? $args['timeout'] : 20;
			$res                             = wp_remote_request( $url, $req );
			if ( is_wp_error( $res ) ) {
				$last = $res;
				if ( $attempt < 3 ) {
					usleep( 400000 * $attempt );
					continue;
				}
				break;
			}
			$code = (int) wp_remote_retrieve_response_code( $res );
			$body = wp_remote_retrieve_body( $res );
			$data = json_decode( $body, true );
			if ( $code >= 200 && $code < 300 ) {
				return is_array( $data ) ? $data : array( 'raw' => $body );
			}
			$msg  = isset( $data['error']['message'] ) ? $data['error']['message'] : wp_remote_retrieve_response_message( $res );
			/* translators: 1: HTTP status, 2: Google error message */
			$last = new WP_Error( 'pct_drive_http', sprintf( __( 'Google Drive returned HTTP %1$s: %2$s', 'personalized-city-tour' ), $code, $msg ), array( 'status' => $code, 'google_message' => $msg, 'phase' => 'drive_api' ) );
			if ( 401 === $code && 1 === $attempt ) {
				update_option( self::STATE_OPT, array_merge( (array) get_option( self::STATE_OPT, array() ), array( 'access_token' => '', 'expires' => 0 ) ), false );
				$token = self::access_token();
				if ( is_wp_error( $token ) ) {
					return $token;
				}
				continue;
			}
			if ( ( 429 === $code || $code >= 500 ) && $attempt < 3 ) {
				usleep( 400000 * $attempt );
				continue;
			}
			break;
		}
		return $last;
	}

	/**
	 * Connection diagnostics for the settings page. Never returns secrets.
	 *
	 * @return array List of { label, status: ok|warn|error|skip, detail }.
	 */
	public static function diagnose() {
		$checks   = array();
		$check    = function ( $label, $status, $detail = '' ) use ( &$checks ) {
			$checks[] = array( 'label' => $label, 'status' => $status, 'detail' => (string) $detail );
		};
		$enabled  = (bool) PCT_Settings::get( 'drive_enabled' );
		$sandbox  = self::sandbox();
		$check( __( 'Connector enabled', 'personalized-city-tour' ), $enabled ? 'ok' : 'warn', $enabled ? '' : __( 'Scheduled exports are off; manual runs and save-triggered snapshots still work.', 'personalized-city-tour' ) );
		$check( __( 'Client ID & secret', 'personalized-city-tour' ), self::configured() ? 'ok' : ( $sandbox ? 'warn' : 'error' ), self::configured() ? '' : __( 'Enter the OAuth client ID and secret from Google Cloud Console.', 'personalized-city-tour' ) );
		$check( __( 'Redirect URI', 'personalized-city-tour' ), 'ok', self::redirect_uri() );

		$dir = self::local_dir();
		$ok  = wp_mkdir_p( $dir ) && is_writable( $dir );
		$check( __( 'Local export folder writable', 'personalized-city-tour' ), $ok ? 'ok' : 'error', $dir );

		$cron_off = defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON;
		$next     = wp_next_scheduled( self::CRON_HOOK );
		$check(
			__( 'Scheduled export', 'personalized-city-tour' ),
			$next ? ( $cron_off ? 'warn' : 'ok' ) : 'skip',
			$next ? sprintf( /* translators: %s: date */ __( 'Next run %s', 'personalized-city-tour' ), wp_date( 'Y-m-d H:i', $next ) ) . ( $cron_off ? ' — ' . __( 'DISABLE_WP_CRON is set; make sure a system cron calls wp-cron.php.', 'personalized-city-tour' ) : '' ) : __( 'No schedule (manual only).', 'personalized-city-tour' )
		);

		$sync = PCT_Sync::status();
		$check(
			__( 'Save-triggered refresh', 'personalized-city-tour' ),
			'failed' === $sync['state'] ? 'error' : ( 'pending' === $sync['state'] ? 'warn' : 'ok' ),
			PCT_Sync::describe( $sync )
		);

		$state = self::connection_status();
		if ( $state['refresh_token'] ) {
			$check( __( 'Refresh token storage', 'personalized-city-tour' ), 'ok', __( 'A readable refresh token is stored persistently.', 'personalized-city-tour' ) );
		} elseif ( $state['access_token'] && $state['access_expires'] > time() ) {
			$check( __( 'Refresh token storage', 'personalized-city-tour' ), 'warn', __( 'No readable persistent refresh token is available; the current access token is temporary. Reconnect Google Drive.', 'personalized-city-tour' ) );
		} else {
			$check( __( 'Refresh token storage', 'personalized-city-tour' ), 'error', __( 'No readable persistent refresh token is available. Reconnect Google Drive.', 'personalized-city-tour' ) );
		}

		if ( $sandbox ) {
			$check( __( 'Sandbox mode', 'personalized-city-tour' ), 'warn', __( 'Exports are written locally only; disable sandbox to upload to Google Drive.', 'personalized-city-tour' ) );
			return $checks;
		}
		if ( ! self::connected() ) {
			$check( __( 'Google account', 'personalized-city-tour' ), 'error', __( 'Not connected — click "Connect Google Drive".', 'personalized-city-tour' ) );
			return $checks;
		}
		$token = self::access_token();
		if ( is_wp_error( $token ) ) {
			$check( __( 'OAuth token refresh', 'personalized-city-tour' ), 'error', $token->get_error_message() );
			return $checks;
		}
		$check( __( 'OAuth token refresh', 'personalized-city-tour' ), 'ok' );

		$about = self::api( 'GET', add_query_arg( 'fields', 'user(emailAddress),storageQuota(limit,usage)', self::API_URL . '/about' ), array( 'timeout' => 15 ) );
		if ( is_wp_error( $about ) ) {
			$check( __( 'Drive API reachable', 'personalized-city-tour' ), 'error', $about->get_error_message() );
			return $checks;
		}
		$email = (string) ( $about['user']['emailAddress'] ?? '' );
		$quota = isset( $about['storageQuota']['usage'], $about['storageQuota']['limit'] ) && (float) $about['storageQuota']['limit'] > 0
			? sprintf( '%s / %s', size_format( (float) $about['storageQuota']['usage'] ), size_format( (float) $about['storageQuota']['limit'] ) )
			: '';
		$check( __( 'Drive API reachable', 'personalized-city-tour' ), 'ok', trim( ( $email ? self::mask_email( $email ) : '' ) . ( $quota ? ' · ' . $quota : '' ) ) );

		foreach ( (array) PCT_Settings::get( 'drive_folders', array() ) as $type => $folder ) {
			if ( '' === (string) $folder ) {
				continue;
			}
			$label = sprintf( /* translators: %s: export type */ __( 'Folder for "%s"', 'personalized-city-tour' ), self::export_types()[ $type ] ?? $type );
			$folder_id = self::folder_id( $folder );
			if ( '' === $folder_id ) {
				$check( $label, 'error', __( 'The folder value is not a valid Google Drive folder ID or folder URL.', 'personalized-city-tour' ) );
				continue;
			}
			$info  = self::api( 'GET', add_query_arg( 'fields', 'id,name,mimeType,trashed', self::API_URL . '/files/' . rawurlencode( $folder_id ) ), array( 'timeout' => 15 ) );
			if ( is_wp_error( $info ) ) {
				$check( $label, 'error', $info->get_error_message() );
			} elseif ( ! empty( $info['trashed'] ) || 'application/vnd.google-apps.folder' !== ( $info['mimeType'] ?? '' ) ) {
				$check( $label, 'error', __( 'The ID does not point to an existing Drive folder.', 'personalized-city-tour' ) );
			} else {
				$check( $label, 'ok', (string) $info['name'] );
			}
		}
		$latest = self::latest();
		$check( __( 'Last successful export', 'personalized-city-tour' ), $latest ? 'ok' : 'warn', $latest ? wp_date( 'Y-m-d H:i', max( array_column( $latest, 'time' ) ) ) : __( 'No export has run yet.', 'personalized-city-tour' ) );
		return $checks;
	}

	public static function mask_email( $email ) {
		$parts = explode( '@', $email, 2 );
		if ( 2 !== count( $parts ) ) {
			return '***';
		}
		return substr( $parts[0], 0, 2 ) . '***@' . $parts[1];
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
		$dir    = self::local_dir();
		$keep   = array_filter( array_column( self::latest(), 'path' ) );
		if ( is_dir( $dir ) ) {
			foreach ( glob( $dir . '/pct-*.json' ) ?: array() as $file ) {
				if ( filemtime( $file ) < $cutoff && ! in_array( $file, $keep, true ) ) {
					wp_delete_file( $file );
				}
			}
		}
		if ( self::sandbox() || ! self::connected() ) {
			return;
		}
		$q   = "name contains 'pct-' and createdTime < '" . gmdate( 'c', $cutoff ) . "' and trashed = false";
		$data = self::api( 'GET', add_query_arg( array( 'q' => $q, 'fields' => 'files(id,name)', 'pageSize' => 100 ), self::API_URL . '/files' ), array( 'timeout' => 20 ) );
		if ( is_wp_error( $data ) ) {
			return;
		}
		$keep_ids = array_filter( array_column( self::latest(), 'drive_id' ) );
		foreach ( (array) ( $data['files'] ?? array() ) as $file ) {
			if ( in_array( $file['id'], $keep_ids, true ) ) {
				continue;
			}
			self::api( 'DELETE', self::API_URL . '/files/' . rawurlencode( $file['id'] ), array( 'timeout' => 20 ) );
		}
	}
}
