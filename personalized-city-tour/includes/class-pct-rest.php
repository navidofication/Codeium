<?php
/**
 * REST API: pct/v1
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PCT_Rest {

	const NS = 'pct/v1';

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
		add_filter( 'rest_pre_dispatch', array( __CLASS__, 'apply_language' ), 10, 3 );
	}

	/**
	 * Honour ?pct_lang= / X-PCT-LANG for our own routes so all translated
	 * strings in the response use the visitor's language.
	 */
	public static function apply_language( $result, $server, $request ) {
		if ( 0 === strpos( (string) $request->get_route(), '/' . self::NS ) ) {
			self::$request = $request;
			// PCT public REST calls are intentionally usable by anonymous visitors.
			// Never let page/REST caches serve a stale cookie-auth nonce or response.
			nocache_headers();
			$lang = $request->get_param( 'pct_lang' );
			if ( ! $lang ) {
				$lang = $request->get_header( 'x-pct-lang' );
			}
			if ( $lang ) {
				PCT_I18n::set_current( $lang );
			}
		}
		return $result;
	}

	public static function routes() {
		register_rest_route( self::NS, '/cities', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'cities' ),
			'permission_callback' => array( __CLASS__, 'visitor_permission' ),
		) );

		register_rest_route( self::NS, '/form', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'form' ),
			'permission_callback' => array( __CLASS__, 'visitor_permission' ),
		) );

		register_rest_route( self::NS, '/i18n', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'i18n' ),
			'permission_callback' => '__return_true',
		) );

		register_rest_route( self::NS, '/i18n/translate', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'translate' ),
			'permission_callback' => array( __CLASS__, 'visitor_permission' ),
		) );

		register_rest_route( self::NS, '/route', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'route' ),
			'permission_callback' => array( __CLASS__, 'visitor_permission' ),
		) );

		register_rest_route( self::NS, '/tours', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'create_tour' ),
			'permission_callback' => array( __CLASS__, 'visitor_permission' ),
			'args'                => array(
				'city_id' => array( 'required' => true, 'type' => 'integer' ),
				'form'    => array( 'required' => true, 'type' => 'object' ),
			),
		) );

		register_rest_route( self::NS, '/tours/(?P<id>\d+)', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'get_tour' ),
			'permission_callback' => array( __CLASS__, 'visitor_permission' ),
		) );

		register_rest_route( self::NS, '/guide/status', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'guide_status' ),
			'permission_callback' => '__return_true',
		) );

		register_rest_route( self::NS, '/guide/apply', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'guide_apply' ),
			'permission_callback' => '__return_true',
		) );

		register_rest_route( self::NS, '/guide/events', array(
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'guide_events' ),
				'permission_callback' => array( __CLASS__, 'guide_permission' ),
			),
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'guide_create_event' ),
				'permission_callback' => array( __CLASS__, 'guide_permission' ),
			),
		) );

		register_rest_route( self::NS, '/guide/events/(?P<id>\d+)', array(
			'methods'             => 'DELETE',
			'callback'            => array( __CLASS__, 'guide_delete_event' ),
			'permission_callback' => array( __CLASS__, 'guide_permission' ),
		) );

		register_rest_route( self::NS, '/geocode', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'geocode' ),
			'permission_callback' => array( __CLASS__, 'guide_permission' ),
		) );

		/* My Trip, My Vibe */
		register_rest_route( self::NS, '/vibe/otp', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'vibe_otp' ),
			'permission_callback' => array( __CLASS__, 'visitor_permission' ),
		) );
		register_rest_route( self::NS, '/vibe/push/subscribe', array(
			'methods' => array( 'GET', 'POST', 'DELETE' ),
			'callback' => array( __CLASS__, 'vibe_push_subscribe' ),
			'permission_callback' => array( __CLASS__, 'push_permission' ),
		) );
		register_rest_route( self::NS, '/vibe/otp/verify', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'vibe_otp_verify' ),
			'permission_callback' => array( __CLASS__, 'visitor_permission' ),
		) );
		register_rest_route( self::NS, '/vibe/requests', array(
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'vibe_list' ),
				'permission_callback' => array( __CLASS__, 'visitor_permission' ),
			),
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'vibe_create' ),
				'permission_callback' => array( __CLASS__, 'visitor_permission' ),
			),
		) );
		register_rest_route( self::NS, '/vibe/requests/(?P<id>\d+)', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'vibe_status' ),
			'permission_callback' => array( __CLASS__, 'visitor_permission' ),
		) );

		/* Admin */
		register_rest_route( self::NS, '/admin/form-schema', array(
			array(
				'methods'             => 'GET',
				'callback'            => function () {
					return rest_ensure_response( PCT_Form_Builder::get_schema() );
				},
				'permission_callback' => array( __CLASS__, 'admin_permission' ),
			),
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'save_schema' ),
				'permission_callback' => array( __CLASS__, 'admin_permission' ),
			),
		) );
		register_rest_route( self::NS, '/admin/form-schema/reset', array(
			'methods'             => 'POST',
			'callback'            => function () {
				return rest_ensure_response( PCT_Form_Builder::reset_schema() );
			},
			'permission_callback' => array( __CLASS__, 'admin_permission' ),
		) );
		register_rest_route( self::NS, '/admin/ai-test', array(
			'methods'             => 'POST',
			'callback'            => function () {
				$r = PCT_AI::test();
				if ( is_wp_error( $r ) ) {
					return $r;
				}
				return rest_ensure_response( array( 'ok' => true ) );
			},
			'permission_callback' => array( __CLASS__, 'admin_permission' ),
		) );
		register_rest_route( self::NS, '/admin/diagnose/(?P<target>ai|drive|image)', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'admin_diagnose' ),
			'permission_callback' => array( __CLASS__, 'admin_permission' ),
		) );
		register_rest_route( self::NS, '/admin/sync', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'admin_sync' ),
			'permission_callback' => array( __CLASS__, 'admin_permission' ),
		) );
		register_rest_route( self::NS, '/exports/(?P<name>[A-Za-z0-9._-]+)', array(
			'methods'             => 'GET',
			'callback'            => array( 'PCT_Sync', 'serve' ),
			'permission_callback' => '__return_true',
		) );
		register_rest_route( self::NS, '/admin/guides/(?P<id>\d+)/(?P<action>approve|revoke|remove)', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'admin_guide_action' ),
			'permission_callback' => array( __CLASS__, 'admin_permission' ),
		) );
		register_rest_route( self::NS, '/admin/ai-logs', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'admin_ai_logs' ),
			'permission_callback' => array( __CLASS__, 'admin_permission' ),
		) );
		register_rest_route( self::NS, '/admin/ai-logs/(?P<id>\d+)', array(
			'methods'             => 'GET',
			'callback'            => function ( WP_REST_Request $r ) {
				$row = PCT_AI_Log::get( absint( $r['id'] ) );
				return $row ? rest_ensure_response( $row ) : new WP_Error( 'pct_not_found', __( 'Log entry not found.', 'personalized-city-tour' ), array( 'status' => 404 ) );
			},
			'permission_callback' => array( __CLASS__, 'admin_permission' ),
		) );
		register_rest_route( self::NS, '/admin/analytics/(?P<id>\d+)', array(
			'methods'             => 'GET',
			'callback'            => function ( WP_REST_Request $r ) {
				$job = PCT_Analytics::get_job( absint( $r['id'] ) );
				return $job ? rest_ensure_response( $job ) : new WP_Error( 'pct_not_found', __( 'Report not found.', 'personalized-city-tour' ), array( 'status' => 404 ) );
			},
			'permission_callback' => function () {
				return PCT_Analytics::can_access();
			},
		) );
		register_rest_route( self::NS, '/admin/active-city', array(
			'methods'             => 'POST',
			'callback'            => function ( WP_REST_Request $r ) {
				update_user_meta( get_current_user_id(), '_pct_active_city', absint( $r->get_param( 'city_id' ) ) );
				return rest_ensure_response( array( 'ok' => true ) );
			},
			'permission_callback' => function () {
				return current_user_can( 'edit_pct_places' );
			},
		) );
	}

	/* ---------------------------------------------------------------- */

	public static function visitor_permission() {
		if ( ! PCT_Settings::visitor_allowed() ) {
			return new WP_Error( 'pct_login_required', __( 'Please log in to use the city tour planner.', 'personalized-city-tour' ), array( 'status' => 401 ) );
		}
		return true;
	}

	public static function guide_permission( $request = null ) {
		if ( PCT_Roles::is_approved_guide() ) {
			return true;
		}
		$profile = PCT_Guide_Profiles::current( $request instanceof WP_REST_Request ? $request : self::$request );
		if ( $profile && PCT_Guide_Profiles::STATUS_REVOKED !== $profile['status'] ) {
			return true;
		}
		return new WP_Error( 'pct_guide_required', __( 'Please register as a local guide first.', 'personalized-city-tour' ), array( 'status' => 403 ) );
	}

	/** @var WP_REST_Request|null Current request (set by rest_pre_dispatch for permission callbacks). */
	private static $request = null;

	public static function admin_permission() {
		return current_user_can( 'pct_manage' );
	}

	/**
	 * Diagnostics for the three external connections. Always returns 200 with
	 * a check list so the UI can render partial results; "ok" is false when
	 * any check has status "error".
	 */
	public static function admin_diagnose( WP_REST_Request $request ) {
		$target = (string) $request['target'];
		switch ( $target ) {
			case 'drive':
				$checks = PCT_Drive::diagnose();
				break;
			case 'image':
				$checks = PCT_Vibe::diagnose();
				break;
			default:
				$checks = PCT_AI::diagnose();
		}
		$ok = ! in_array( 'error', array_column( $checks, 'status' ), true );
		return rest_ensure_response( array( 'ok' => $ok, 'target' => $target, 'checks' => $checks, 'time' => current_time( 'mysql' ) ) );
	}

	/**
	 * Run the save-triggered export refresh now and return its status.
	 */
	public static function admin_sync() {
		$s = PCT_Sync::run_now();
		return rest_ensure_response( array( 'ok' => 'ok' === $s['state'], 'status' => $s, 'message' => PCT_Sync::describe( $s ), 'latest' => PCT_Drive::latest() ) );
	}

	/* ---------------------------------------------------------------- */

	public static function cities() {
		$out    = array();
		$public = (bool) PCT_Settings::get( 'show_mymaps_public' );
		foreach ( PCT_Data::published_cities() as $c ) {
			$out[] = array(
				'id'        => $c['id'],
				'name'      => $c['name'],
				'country'   => $c['country'],
				'lat'       => $c['lat'],
				'lng'       => $c['lng'],
				'zoom'      => $c['zoom'],
				'mymaps'    => $public ? $c['mymaps'] : array(),
				'channels'  => $c['channels'],
				'gmaps_url' => $c['gmaps_url'],
				'ar_url'    => $c['ar_url'],
				'ar_label'  => $c['ar_label'],
			);
		}
		return rest_ensure_response( $out );
	}

	public static function form() {
		return rest_ensure_response( PCT_Form_Builder::get_schema() );
	}

	/**
	 * Everything the frontend needs to switch language instantly:
	 * UI strings, the (translated) form schema, categories and option labels.
	 */
	public static function i18n( WP_REST_Request $request ) {
		$lang = PCT_I18n::set_current( $request->get_param( 'lang' ) ? $request->get_param( 'lang' ) : PCT_I18n::current() );
		return rest_ensure_response(
			array(
				'lang'          => $lang,
				'rtl'           => PCT_I18n::is_rtl( $lang ),
				'calendar'      => PCT_I18n::calendar_for( $lang ),
				'strings'       => PCT_Frontend::strings(),
				'form'          => PCT_Settings::visitor_allowed() ? PCT_Form_Builder::get_schema() : null,
				'categories'    => self::categories(),
				'vibe'          => PCT_Vibe::enabled() ? array(
					'fee'      => PCT_Vibe::settings()['fee_text'],
					'vibes'    => PCT_Vibe::localized_options( 'vibes', $lang ),
					'styles'   => PCT_Vibe::localized_options( 'styles', $lang ),
					'subjects' => PCT_Vibe::localized_options( 'subjects', $lang ),
				) : null,
			)
		);
	}

	/**
	 * Optional online translation of dynamic texts (AI output, city data).
	 */
	public static function translate( WP_REST_Request $request ) {
		if ( ! PCT_I18n::online_available() ) {
			return new WP_Error( 'pct_no_translation', __( 'Online translation is not enabled.', 'personalized-city-tour' ), array( 'status' => 404 ) );
		}
		$limit = self::rate_limit( 'translate', 30, 10 * MINUTE_IN_SECONDS );
		if ( is_wp_error( $limit ) ) {
			return $limit;
		}
		$texts = array_slice( array_map( 'sanitize_textarea_field', (array) $request->get_param( 'texts' ) ), 0, 60 );
		$out   = PCT_I18n::translate_texts( $texts, PCT_I18n::normalize( $request->get_param( 'target' ) ), sanitize_key( $request->get_param( 'source' ) ?: 'auto' ) );
		if ( is_wp_error( $out ) ) {
			return $out;
		}
		return rest_ensure_response( array( 'texts' => $out ) );
	}

	/**
	 * Server-side routing proxy (keeps Mapbox/Google keys off the client).
	 */
	public static function route( WP_REST_Request $request ) {
		$limit = self::rate_limit( 'route', 120, 10 * MINUTE_IN_SECONDS );
		if ( is_wp_error( $limit ) ) {
			return $limit;
		}
		$points = array();
		foreach ( array_slice( (array) $request->get_param( 'points' ), 0, 25 ) as $p ) {
			$lat = self::sanitize_coord( is_array( $p ) ? ( $p['lat'] ?? ( $p[0] ?? null ) ) : null, 90 );
			$lng = self::sanitize_coord( is_array( $p ) ? ( $p['lng'] ?? ( $p[1] ?? null ) ) : null, 180 );
			if ( '' !== $lat && '' !== $lng ) {
				$points[] = array( (float) $lat, (float) $lng );
			}
		}
		if ( count( $points ) < 2 ) {
			return new WP_Error( 'pct_invalid', __( 'At least two points are required.', 'personalized-city-tour' ), array( 'status' => 400 ) );
		}
		$mode = PCT_Maps::normalize_travel_mode( $request->get_param( 'mode' ) );
		if ( '' === $mode ) {
			return new WP_Error( 'pct_route_mode_unsupported', __( 'This travel mode is not supported by the configured routing integration.', 'personalized-city-tour' ), array( 'status' => 422 ) );
		}
		$res  = PCT_Maps::route( $points, $mode );
		if ( is_wp_error( $res ) ) {
			$data = (array) $res->get_error_data();
			if ( empty( $data['status'] ) ) {
				$data['status'] = 502;
			}
			$res->add_data( $data );
			return $res;
		}
		return rest_ensure_response( $res );
	}

	public static function create_tour( WP_REST_Request $request ) {
		$limit = self::rate_limit( 'tour', 6, 10 * MINUTE_IN_SECONDS );
		if ( is_wp_error( $limit ) ) {
			return $limit;
		}
		$city_id = absint( $request->get_param( 'city_id' ) );
		$raw     = (array) $request->get_param( 'form' );
		$schema  = PCT_Form_Builder::get_schema();
		$form    = PCT_Form_Builder::validate_submission( $schema, $raw );
		if ( is_wp_error( $form ) ) {
			$form->add_data( array_merge( (array) $form->get_error_data(), array( 'status' => 400 ) ) );
			return $form;
		}
		$saved = array( 'id' => 0, 'token' => '', 'url' => '' );
		if ( PCT_Settings::get( 'store_submissions' ) ) {
			// Persist the validated form BEFORE the AI call. This is the authoritative
			// submission record and also becomes part of the next forms export.
			$pre = PCT_Tours::save_form_submission( $city_id, $form );
			if ( ! is_wp_error( $pre ) ) {
				$saved = $pre;
			}
		}
		$result = PCT_Tour_Generator::generate( $city_id, $form, array( 'lang' => PCT_I18n::current() ) );
		if ( is_wp_error( $result ) ) {
			$result->add_data( array( 'status' => 422 ) );
			if ( $saved['id'] ) {
				// Keep the form even when AI fails, so it is not lost.
				PCT_Drive::queue_exports( array( 'forms' ) );
			}
			return $result;
		}
		if ( $saved['id'] ) {
			$saved['url'] = PCT_Tours::attach_result( $saved['id'], $result, $form );
			PCT_Drive::queue_exports( array( 'forms', 'tours' ) );
		} elseif ( PCT_Settings::get( 'store_submissions' ) ) {
			// Rare database-write failure: fall back to the legacy atomic save.
			$legacy = PCT_Tours::save( $city_id, $form, $result );
			if ( ! is_wp_error( $legacy ) ) {
				$saved = $legacy;
			}
		}
		do_action( 'pct_tour_created', $saved['id'], $form, $result );
		return rest_ensure_response(
			array(
				'id'     => $saved['id'],
				'token'  => $saved['token'],
				'url'    => $saved['url'],
				'result' => $result,
			)
		);
	}

	public static function get_tour( WP_REST_Request $request ) {
		$tour = PCT_Tours::get( absint( $request['id'] ), (string) $request->get_param( 'token' ) );
		if ( ! $tour ) {
			return new WP_Error( 'pct_not_found', __( 'Tour not found.', 'personalized-city-tour' ), array( 'status' => 404 ) );
		}
		return rest_ensure_response(
			array(
				'id'     => $tour['id'],
				'token'  => $tour['token'],
				'url'    => $tour['url'],
				'result' => $tour['result'],
			)
		);
	}

	/* ---------------------------------------------------------------- */

	public static function guide_status( WP_REST_Request $request ) {
		$uid     = get_current_user_id();
		$profile = PCT_Guide_Profiles::current( $request );
		$status  = $uid ? PCT_Roles::guide_status( $uid ) : '';
		if ( $profile ) {
			$status = $profile['status'];
		}
		return rest_ensure_response(
			array(
				'logged_in'      => (bool) $uid,
				'is_guide'       => PCT_Roles::is_approved_guide() || ( $profile && PCT_Guide_Profiles::STATUS_REVOKED !== $profile['status'] ),
				'registered'     => (bool) $profile,
				'verified'       => PCT_Roles::is_approved_guide() || ( $profile && PCT_Guide_Profiles::STATUS_APPROVED === $profile['status'] ),
				'status'         => $status,
				'can_apply'      => (bool) PCT_Settings::get( 'guide_registration' ),
				'require_mobile' => (bool) PCT_Settings::get( 'guide_require_mobile', 1 ),
				'display_name'   => $profile ? $profile['name'] : ( $uid ? wp_get_current_user()->display_name : '' ),
				'logout_url'     => $uid ? wp_logout_url( self::current_url() ) : '',
				'cities'         => PCT_Post_Types::cities(),
				'categories'     => self::categories(),
				'can_publish'    => current_user_can( 'publish_pct_events' ),
			)
		);
	}

	private static function categories() {
		$terms = get_terms( array( 'taxonomy' => PCT_Post_Types::CATEGORY, 'hide_empty' => false ) );
		$out   = array();
		if ( ! is_wp_error( $terms ) ) {
			foreach ( $terms as $t ) {
				$out[ $t->slug ] = $t->name;
			}
		}
		return $out;
	}

	private static function current_url() {
		return isset( $_SERVER['HTTP_REFERER'] ) ? esc_url_raw( wp_unslash( $_SERVER['HTTP_REFERER'] ) ) : home_url( '/' );
	}

	public static function guide_apply( WP_REST_Request $request ) {
		if ( ! PCT_Settings::get( 'guide_registration' ) ) {
			return new WP_Error( 'pct_closed', __( 'Local guide registration is currently closed.', 'personalized-city-tour' ), array( 'status' => 403 ) );
		}
		$limit = self::rate_limit( 'apply', 5, HOUR_IN_SECONDS );
		if ( is_wp_error( $limit ) ) {
			return $limit;
		}

		$uid = get_current_user_id();
		if ( ! $uid || ! user_can( $uid, 'edit_pct_events' ) ) {
			// No-login registration: name + mobile, stored as a guide profile.
			$created = PCT_Guide_Profiles::create(
				array(
					'name'      => $request->get_param( 'name' ),
					'mobile'    => $request->get_param( 'mobile' ),
					'email'     => $request->get_param( 'email' ),
					'city_id'   => $request->get_param( 'city_id' ),
					'languages' => $request->get_param( 'languages' ),
					'note'      => $request->get_param( 'note' ),
					'lang'      => PCT_I18n::current(),
				)
			);
			if ( is_wp_error( $created ) ) {
				return $created;
			}
			return rest_ensure_response( $created );
		}
		if ( PCT_Roles::is_approved_guide( $uid ) ) {
			return rest_ensure_response( array( 'status' => PCT_Roles::STATUS_APPROVED, 'token' => '' ) );
		}

		PCT_Roles::apply( $uid );
		update_user_meta( $uid, '_pct_guide_city', absint( $request->get_param( 'city_id' ) ) );
		update_user_meta( $uid, '_pct_guide_note', sanitize_textarea_field( (string) $request->get_param( 'note' ) ) );
		update_user_meta( $uid, '_pct_guide_languages', sanitize_text_field( (string) $request->get_param( 'languages' ) ) );
		update_user_meta( $uid, '_pct_guide_mobile', PCT_SMS::normalize_mobile( $request->get_param( 'mobile' ) ) );

		if ( PCT_Settings::get( 'notify_admin_guides' ) ) {
			$user = get_userdata( $uid );
			wp_mail(
				get_option( 'admin_email' ),
				/* translators: %s: site name */
				sprintf( __( '[%s] New local guide application', 'personalized-city-tour' ), get_bloginfo( 'name' ) ),
				sprintf(
					/* translators: 1: display name, 2: email, 3: admin url */
					__( "%1\$s (%2\$s) applied to become a local guide.\n\nReview: %3\$s", 'personalized-city-tour' ),
					$user->display_name,
					$user->user_email,
					admin_url( 'admin.php?page=pct-guides' )
				)
			);
		}
		do_action( 'pct_guide_applied', $uid );

		return rest_ensure_response( array( 'status' => PCT_Roles::STATUS_PENDING, 'token' => '' ) );
	}

	public static function guide_events( WP_REST_Request $request ) {
		$profile = PCT_Guide_Profiles::current( $request );
		if ( $profile && ! PCT_Roles::is_approved_guide() ) {
			$posts = PCT_Guide_Profiles::events( $profile['id'] );
		} else {
			$posts = get_posts(
				array(
					'post_type'      => PCT_Post_Types::EVENT,
					'post_status'    => array( 'publish', 'pending', 'draft' ),
					'author'         => get_current_user_id(),
					'posts_per_page' => 50,
					'orderby'        => 'date',
					'order'          => 'DESC',
				)
			);
		}
		$out   = array();
		foreach ( $posts as $p ) {
			$out[] = array(
				'id'     => $p->ID,
				'title'  => $p->post_title,
				'status' => $p->post_status,
				'start'  => get_post_meta( $p->ID, '_pct_start', true ),
				'city'   => get_the_title( (int) get_post_meta( $p->ID, '_pct_city_id', true ) ),
			);
		}
		return rest_ensure_response( $out );
	}

	public static function guide_create_event( WP_REST_Request $request ) {
		$title   = sanitize_text_field( $request->get_param( 'title' ) );
		$city_id = absint( $request->get_param( 'city_id' ) );
		$start   = self::sanitize_datetime( $request->get_param( 'start' ) );
		if ( '' === $title || ! $city_id || ! get_post( $city_id ) || '' === $start ) {
			return new WP_Error( 'pct_invalid', __( 'Title, city and start date are required.', 'personalized-city-tour' ), array( 'status' => 400 ) );
		}
		$profile = PCT_Roles::is_approved_guide() ? null : PCT_Guide_Profiles::current( $request );
		$mobile  = PCT_SMS::normalize_mobile( $request->get_param( 'organizer_mobile' ) );
		if ( $profile && '' === $mobile ) {
			$mobile = $profile['mobile'];
		}
		if ( '' === $mobile && PCT_Settings::get( 'guide_require_mobile', 1 ) ) {
			return new WP_Error( 'pct_invalid', __( 'The organizer mobile number is required.', 'personalized-city-tour' ), array( 'status' => 400 ) );
		}
		// Events from unverified / no-login guides always wait for review.
		$status = ( ! $profile && current_user_can( 'publish_pct_events' ) ) ? 'publish' : 'pending';
		if ( $profile && PCT_Guide_Profiles::STATUS_APPROVED === $profile['status'] && PCT_Settings::get( 'guide_auto_publish' ) ) {
			$status = 'publish';
		}
		$post_id = wp_insert_post(
			array(
				'post_type'    => PCT_Post_Types::EVENT,
				'post_status'  => $status,
				'post_title'   => $title,
				'post_content' => sanitize_textarea_field( (string) $request->get_param( 'description' ) ),
				'post_author'  => get_current_user_id(),
			),
			true
		);
		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}
		$acc = array_intersect( (array) $request->get_param( 'accessibility' ), array_keys( PCT_Data::accessibility_options() ) );
		$meta = array(
			'_pct_city_id'          => $city_id,
			'_pct_organizer_name'   => sanitize_text_field( (string) $request->get_param( 'organizer_name' ) ),
			'_pct_organizer_mobile' => $mobile,
			'_pct_organizer_url'    => esc_url_raw( (string) $request->get_param( 'organizer_url' ) ),
			'_pct_event_url'        => esc_url_raw( (string) $request->get_param( 'event_url' ) ),
			'_pct_gmaps_url'        => esc_url_raw( (string) $request->get_param( 'gmaps_url' ) ),
			'_pct_socials'          => PCT_Meta_Boxes::sanitize_links( $request->get_param( 'socials' ) ),
			'_pct_start'            => $start,
			'_pct_end'              => self::sanitize_datetime( $request->get_param( 'end' ) ),
			'_pct_venue'            => sanitize_text_field( (string) $request->get_param( 'venue' ) ),
			'_pct_address'          => sanitize_text_field( (string) $request->get_param( 'address' ) ),
			'_pct_lat'              => self::sanitize_coord( $request->get_param( 'lat' ), 90 ),
			'_pct_lng'              => self::sanitize_coord( $request->get_param( 'lng' ), 180 ),
			'_pct_price_tier'       => in_array( $request->get_param( 'price_tier' ), array_keys( PCT_Data::price_tiers() ), true ) ? $request->get_param( 'price_tier' ) : '',
			'_pct_accessibility'    => array_values( $acc ),
			'_pct_guide_profile'    => $profile ? (int) $profile['id'] : 0,
		);
		foreach ( $meta as $k => $v ) {
			update_post_meta( $post_id, $k, $v );
		}
		$cats = array_map( 'sanitize_key', (array) $request->get_param( 'categories' ) );
		if ( $cats ) {
			wp_set_object_terms( $post_id, $cats, PCT_Post_Types::CATEGORY );
		}
		do_action( 'pct_guide_event_submitted', $post_id, get_current_user_id() );

		return rest_ensure_response(
			array(
				'id'     => $post_id,
				'status' => $status,
			)
		);
	}

	public static function guide_delete_event( WP_REST_Request $request ) {
		$post = get_post( absint( $request['id'] ) );
		if ( ! $post || PCT_Post_Types::EVENT !== $post->post_type ) {
			return new WP_Error( 'pct_forbidden', __( 'You cannot delete this event.', 'personalized-city-tour' ), array( 'status' => 403 ) );
		}
		$profile = PCT_Guide_Profiles::current( $request );
		$owns    = $profile && (int) get_post_meta( $post->ID, '_pct_guide_profile', true ) === (int) $profile['id'];
		if ( ! $owns && ! current_user_can( 'delete_post', $post->ID ) ) {
			return new WP_Error( 'pct_forbidden', __( 'You cannot delete this event.', 'personalized-city-tour' ), array( 'status' => 403 ) );
		}
		wp_trash_post( $post->ID );
		return rest_ensure_response( array( 'ok' => true ) );
	}

	/**
	 * Address lookup through Nominatim (server side so the policy-required UA is set).
	 */
	public static function geocode( WP_REST_Request $request ) {
		$q = sanitize_text_field( $request->get_param( 'q' ) );
		if ( strlen( $q ) < 3 ) {
			return rest_ensure_response( array() );
		}
		$key = 'pct_geo_' . md5( $q );
		$hit = get_transient( $key );
		if ( false !== $hit ) {
			return rest_ensure_response( $hit );
		}
		$res = wp_remote_get(
			add_query_arg( array( 'q' => rawurlencode( $q ), 'format' => 'json', 'limit' => 5 ), 'https://nominatim.openstreetmap.org/search' ),
			array( 'timeout' => 10, 'headers' => array( 'User-Agent' => 'PersonalizedCityTour/' . PCT_VERSION . ' (' . home_url() . ')' ) )
		);
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$out = array();
		foreach ( (array) json_decode( wp_remote_retrieve_body( $res ), true ) as $row ) {
			if ( ! isset( $row['lat'], $row['lon'] ) ) {
				continue;
			}
			$out[] = array(
				'label' => $row['display_name'] ?? '',
				'lat'   => (float) $row['lat'],
				'lng'   => (float) $row['lon'],
			);
		}
		set_transient( $key, $out, DAY_IN_SECONDS );
		return rest_ensure_response( $out );
	}

	/* ---------------------------------------------------------------- */

	public static function vibe_otp( WP_REST_Request $request ) {
		$limit = self::rate_limit( 'otp', 5, 15 * MINUTE_IN_SECONDS );
		if ( is_wp_error( $limit ) ) {
			return $limit;
		}
		if ( ! PCT_Vibe::enabled() ) {
			return new WP_Error( 'pct_disabled', __( 'This service is not available right now.', 'personalized-city-tour' ), array( 'status' => 403 ) );
		}
		$out = PCT_Vibe::otp_request( $request->get_param( 'mobile' ) );
		return is_wp_error( $out ) ? $out : rest_ensure_response( $out );
	}

	public static function push_permission() {
		if ( ! is_user_logged_in() || ! current_user_can( 'pct_manage' ) ) {
			return new WP_Error( 'pct_push_forbidden', __( 'Only an administrator can enable browser notifications.', 'personalized-city-tour' ), array( 'status' => 403 ) );
		}
		return true;
	}

	public static function vibe_push_subscribe( WP_REST_Request $request ) {
		if ( ! is_user_logged_in() || ! current_user_can( 'pct_manage' ) ) {
			return new WP_Error( 'pct_push_forbidden', __( 'Only an administrator can enable browser notifications.', 'personalized-city-tour' ), array( 'status' => 403 ) );
		}
		if ( 'GET' === $request->get_method() ) {
			$subs = get_user_meta( get_current_user_id(), PCT_Push::META_KEY, true );
			return rest_ensure_response( array( 'count' => is_array( $subs ) ? count( $subs ) : 0 ) );
		}
		if ( 'DELETE' === $request->get_method() ) {
			return rest_ensure_response( PCT_Push::unsubscribe( get_current_user_id(), $request->get_param( 'endpoint' ) ) );
		}
		return rest_ensure_response( PCT_Push::subscribe( get_current_user_id(), $request->get_json_params() ) );
	}

	public static function vibe_otp_verify( WP_REST_Request $request ) {
		$limit = self::rate_limit( 'otp_verify', 15, 15 * MINUTE_IN_SECONDS );
		if ( is_wp_error( $limit ) ) {
			return $limit;
		}
		$out = PCT_Vibe::otp_verify( $request->get_param( 'mobile' ), $request->get_param( 'code' ) );
		return is_wp_error( $out ) ? $out : rest_ensure_response( $out );
	}

	private static function vibe_token( WP_REST_Request $request ) {
		$token = (string) $request->get_header( 'x-pct-visitor' );
		if ( '' === $token ) {
			$token = (string) $request->get_param( 'visitor_token' );
		}
		return $token;
	}

	private static function vibe_visitor( WP_REST_Request $request ) {
		$mobile = PCT_Vibe::visitor_mobile( self::vibe_token( $request ) );
		if ( '' === $mobile ) {
			return new WP_Error( 'pct_otp_required', __( 'Please verify your mobile number first.', 'personalized-city-tour' ), array( 'status' => 401 ) );
		}
		return $mobile;
	}

	private static function vibe_create_visitor( WP_REST_Request $request ) {
		$token = self::vibe_token( $request );
		$mobile = PCT_Vibe::visitor_mobile( $token );
		if ( '' === $mobile || ! PCT_Vibe::visitor_token_can_create( $token ) ) {
			return new WP_Error( 'pct_otp_required', __( 'Please request and verify a new OTP before creating another image.', 'personalized-city-tour' ), array( 'status' => 401 ) );
		}
		return array( 'mobile' => $mobile, 'token' => $token );
	}

	public static function vibe_create( WP_REST_Request $request ) {
		$visitor = self::vibe_create_visitor( $request );
		if ( is_wp_error( $visitor ) ) {
			return $visitor;
		}
		$mobile = $visitor['mobile'];
		$limit = self::rate_limit( 'vibe', 5, HOUR_IN_SECONDS );
		if ( is_wp_error( $limit ) ) {
			return $limit;
		}
		$files = $request->get_file_params();
		$data  = array(
			'mobile'  => $mobile,
			'name'    => $request->get_param( 'name' ),
			'email'   => $request->get_param( 'email' ),
			'city_id' => $request->get_param( 'city_id' ),
			'city'    => $request->get_param( 'city' ),
			'vibe'    => $request->get_param( 'vibe' ),
			'style'   => $request->get_param( 'style' ),
			'subject' => $request->get_param( 'subject' ),
			'notes'   => $request->get_param( 'notes' ),
			'lang'    => PCT_I18n::current(),
		);
		$r = PCT_Vibe::create( $data, isset( $files['photo'] ) ? (array) $files['photo'] : array() );
		if ( is_wp_error( $r ) ) {
			return $r;
		}
		// A verified OTP authorises exactly one new image request. Keep the
		// visitor token alive for viewing that request, but consume its
		// creation permission so a second image requires a fresh OTP.
		PCT_Vibe::consume_visitor_token( $visitor['token'] );
		return rest_ensure_response( PCT_Vibe::public_view( $r ) );
	}

	public static function vibe_list( WP_REST_Request $request ) {
		$mobile = self::vibe_visitor( $request );
		if ( is_wp_error( $mobile ) ) {
			return $mobile;
		}
		$out = array();
		foreach ( PCT_Vibe::all( 200 ) as $r ) {
			if ( $r['mobile'] === $mobile ) {
				$out[] = PCT_Vibe::public_view( $r );
			}
		}
		return rest_ensure_response( $out );
	}

	public static function vibe_status( WP_REST_Request $request ) {
		$r = PCT_Vibe::get( absint( $request['id'] ) );
		if ( ! $r ) {
			return new WP_Error( 'pct_not_found', __( 'Request not found.', 'personalized-city-tour' ), array( 'status' => 404 ) );
		}
		$key    = (string) $request->get_param( 'k' );
		$mobile = self::vibe_visitor( $request );
		$owner  = ( ! is_wp_error( $mobile ) && $mobile === $r['mobile'] ) || ( $key && hash_equals( $r['token'], $key ) ) || current_user_can( 'pct_manage' );
		if ( ! $owner ) {
			return new WP_Error( 'pct_forbidden', __( 'Not allowed.', 'personalized-city-tour' ), array( 'status' => 403 ) );
		}
		return rest_ensure_response( PCT_Vibe::public_view( $r ) );
	}

	/* ---------------------------------------------------------------- */

	public static function save_schema( WP_REST_Request $request ) {
		$schema = $request->get_json_params();
		$saved  = PCT_Form_Builder::save_schema( $schema );
		if ( is_wp_error( $saved ) ) {
			$saved->add_data( array( 'status' => 400 ) );
			return $saved;
		}
		return rest_ensure_response( $saved );
	}

	public static function admin_guide_action( WP_REST_Request $request ) {
		$uid    = absint( $request['id'] );
		$action = $request['action'];
		if ( 'profile' === $request->get_param( 'type' ) ) {
			if ( ! PCT_Guide_Profiles::get( $uid ) ) {
				return new WP_Error( 'pct_no_user', __( 'Registration not found.', 'personalized-city-tour' ), array( 'status' => 404 ) );
			}
			if ( 'remove' === $action ) {
				PCT_Guide_Profiles::remove( $uid );
				return rest_ensure_response( array( 'status' => 'removed' ) );
			}
			PCT_Guide_Profiles::set_status( $uid, 'approve' === $action ? PCT_Guide_Profiles::STATUS_APPROVED : PCT_Guide_Profiles::STATUS_REVOKED, (bool) $request->get_param( 'publish_events' ) );
			return rest_ensure_response( array( 'status' => PCT_Guide_Profiles::get( $uid )['status'] ) );
		}
		if ( ! get_userdata( $uid ) ) {
			return new WP_Error( 'pct_no_user', __( 'User not found.', 'personalized-city-tour' ), array( 'status' => 404 ) );
		}
		call_user_func( array( 'PCT_Roles', $action ), $uid );
		return rest_ensure_response( array( 'status' => PCT_Roles::guide_status( $uid ) ) );
	}

	public static function admin_ai_logs( WP_REST_Request $request ) {
		return rest_ensure_response(
			PCT_AI_Log::query(
				array(
					'kind'     => $request->get_param( 'kind' ),
					'status'   => $request->get_param( 'status' ),
					'page'     => $request->get_param( 'page' ),
					'per_page' => $request->get_param( 'per_page' ),
				)
			)
		);
	}

	/* ---------------------------------------------------------------- */

	public static function sanitize_datetime( $value ) {
		$value = is_string( $value ) ? trim( str_replace( 'T', ' ', $value ) ) : '';
		// Accept Jalali dates (1404/06/30 10:30) from the Persian date picker.
		if ( preg_match( '/^(\d{4}[\/-]\d{1,2}[\/-]\d{1,2})( \d{2}:\d{2})?$/', $value, $m ) ) {
			$g = PCT_I18n::parse_date( $m[1] );
			if ( '' !== $g ) {
				$value = $g . ( isset( $m[2] ) ? $m[2] : '' );
			}
		}
		if ( preg_match( '/^\d{4}-\d{2}-\d{2}( \d{2}:\d{2})?$/', $value ) ) {
			return strlen( $value ) === 10 ? $value . ' 00:00' : $value;
		}
		return '';
	}

	public static function sanitize_coord( $value, $max ) {
		if ( null === $value || '' === $value || ! is_numeric( $value ) ) {
			return '';
		}
		$v = (float) $value;
		return ( $v >= -$max && $v <= $max ) ? $v : '';
	}

	private static function rate_limit( $bucket, $max, $window ) {
		if ( current_user_can( 'pct_manage' ) ) {
			return true;
		}
		$ip    = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : 'unknown';
		$key   = 'pct_rl_' . $bucket . '_' . md5( $ip . get_current_user_id() );
		$count = (int) get_transient( $key );
		if ( $count >= $max ) {
			return new WP_Error( 'pct_rate_limited', __( 'Too many requests. Please try again in a few minutes.', 'personalized-city-tour' ), array( 'status' => 429 ) );
		}
		set_transient( $key, $count + 1, $window );
		return true;
	}
}
