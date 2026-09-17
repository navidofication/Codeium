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
		register_rest_route( self::NS, '/admin/guides/(?P<id>\d+)/(?P<action>approve|revoke|remove)', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'admin_guide_action' ),
			'permission_callback' => array( __CLASS__, 'admin_permission' ),
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

	public static function guide_permission() {
		if ( ! PCT_Roles::is_approved_guide() ) {
			return new WP_Error( 'pct_guide_required', __( 'Only approved local guides can do this.', 'personalized-city-tour' ), array( 'status' => 403 ) );
		}
		return true;
	}

	public static function admin_permission() {
		return current_user_can( 'pct_manage' );
	}

	/* ---------------------------------------------------------------- */

	public static function cities() {
		$out = array();
		foreach ( PCT_Data::published_cities() as $c ) {
			$out[] = array(
				'id'      => $c['id'],
				'name'    => $c['name'],
				'country' => $c['country'],
				'lat'     => $c['lat'],
				'lng'     => $c['lng'],
				'zoom'    => $c['zoom'],
				'mymaps'  => $c['mymaps'],
			);
		}
		return rest_ensure_response( $out );
	}

	public static function form() {
		return rest_ensure_response( PCT_Form_Builder::get_schema() );
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
		$result = PCT_Tour_Generator::generate( $city_id, $form );
		if ( is_wp_error( $result ) ) {
			$result->add_data( array( 'status' => 422 ) );
			return $result;
		}
		$saved = array( 'id' => 0, 'token' => '' );
		if ( PCT_Settings::get( 'store_submissions' ) ) {
			$saved = PCT_Tours::save( $city_id, $form, $result );
			if ( is_wp_error( $saved ) ) {
				$saved = array( 'id' => 0, 'token' => '' );
			}
		}
		do_action( 'pct_tour_created', $saved['id'], $form, $result );
		return rest_ensure_response(
			array(
				'id'     => $saved['id'],
				'token'  => $saved['token'],
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
				'result' => $tour['result'],
			)
		);
	}

	/* ---------------------------------------------------------------- */

	public static function guide_status() {
		$uid = get_current_user_id();
		return rest_ensure_response(
			array(
				'logged_in'    => (bool) $uid,
				'is_guide'     => PCT_Roles::is_approved_guide(),
				'status'       => $uid ? PCT_Roles::guide_status( $uid ) : '',
				'can_apply'    => (bool) PCT_Settings::get( 'guide_registration' ),
				'display_name' => $uid ? wp_get_current_user()->display_name : '',
				'login_url'    => wp_login_url( self::current_url() ),
				'logout_url'   => wp_logout_url( self::current_url() ),
				'register_url' => get_option( 'users_can_register' ) ? wp_registration_url() : '',
				'cities'       => PCT_Post_Types::cities(),
				'categories'   => self::categories(),
				'can_publish'  => current_user_can( 'publish_pct_events' ),
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
		if ( ! $uid ) {
			$email = sanitize_email( $request->get_param( 'email' ) );
			$name  = sanitize_text_field( $request->get_param( 'name' ) );
			if ( ! is_email( $email ) || '' === $name ) {
				return new WP_Error( 'pct_invalid', __( 'Name and a valid email are required.', 'personalized-city-tour' ), array( 'status' => 400 ) );
			}
			if ( email_exists( $email ) ) {
				return new WP_Error( 'pct_exists', __( 'An account with this email already exists. Please log in and apply again.', 'personalized-city-tour' ), array( 'status' => 409 ) );
			}
			$login = sanitize_user( current( explode( '@', $email ) ), true );
			$base  = $login ? $login : 'guide';
			$i     = 1;
			while ( username_exists( $login ) || '' === $login ) {
				$login = $base . $i++;
			}
			$uid = wp_insert_user(
				array(
					'user_login'   => $login,
					'user_email'   => $email,
					'display_name' => $name,
					'first_name'   => $name,
					'user_pass'    => wp_generate_password( 20 ),
					'role'         => get_option( 'default_role', 'subscriber' ),
				)
			);
			if ( is_wp_error( $uid ) ) {
				return $uid;
			}
			wp_new_user_notification( $uid, null, 'user' );
		} elseif ( PCT_Roles::is_approved_guide( $uid ) ) {
			return rest_ensure_response( array( 'status' => PCT_Roles::STATUS_APPROVED ) );
		}

		PCT_Roles::apply( $uid );
		update_user_meta( $uid, '_pct_guide_city', absint( $request->get_param( 'city_id' ) ) );
		update_user_meta( $uid, '_pct_guide_note', sanitize_textarea_field( (string) $request->get_param( 'note' ) ) );
		update_user_meta( $uid, '_pct_guide_languages', sanitize_text_field( (string) $request->get_param( 'languages' ) ) );

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

		return rest_ensure_response( array( 'status' => PCT_Roles::STATUS_PENDING ) );
	}

	public static function guide_events() {
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
		$status  = current_user_can( 'publish_pct_events' ) ? 'publish' : 'pending';
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
			'_pct_city_id'        => $city_id,
			'_pct_organizer_name' => sanitize_text_field( (string) $request->get_param( 'organizer_name' ) ),
			'_pct_organizer_url'  => esc_url_raw( (string) $request->get_param( 'organizer_url' ) ),
			'_pct_event_url'      => esc_url_raw( (string) $request->get_param( 'event_url' ) ),
			'_pct_start'          => $start,
			'_pct_end'            => self::sanitize_datetime( $request->get_param( 'end' ) ),
			'_pct_venue'          => sanitize_text_field( (string) $request->get_param( 'venue' ) ),
			'_pct_address'        => sanitize_text_field( (string) $request->get_param( 'address' ) ),
			'_pct_lat'            => self::sanitize_coord( $request->get_param( 'lat' ), 90 ),
			'_pct_lng'            => self::sanitize_coord( $request->get_param( 'lng' ), 180 ),
			'_pct_price_tier'     => in_array( $request->get_param( 'price_tier' ), array_keys( PCT_Data::price_tiers() ), true ) ? $request->get_param( 'price_tier' ) : '',
			'_pct_accessibility'  => array_values( $acc ),
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
		if ( ! $post || PCT_Post_Types::EVENT !== $post->post_type || ! current_user_can( 'delete_post', $post->ID ) ) {
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
		if ( ! get_userdata( $uid ) ) {
			return new WP_Error( 'pct_no_user', __( 'User not found.', 'personalized-city-tour' ), array( 'status' => 404 ) );
		}
		call_user_func( array( 'PCT_Roles', $action ), $uid );
		return rest_ensure_response( array( 'status' => PCT_Roles::guide_status( $uid ) ) );
	}

	/* ---------------------------------------------------------------- */

	public static function sanitize_datetime( $value ) {
		$value = is_string( $value ) ? trim( str_replace( 'T', ' ', $value ) ) : '';
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
