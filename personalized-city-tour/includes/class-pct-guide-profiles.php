<?php
/**
 * Local guide registrations without a WordPress account.
 *
 * A guide registers with name + mobile (email optional). The registration is
 * stored as a private pct_guide post with status pending and a device token is
 * returned to the browser. Events submitted with that token stay pending until
 * the administrator verifies the guide (typically by phone) and approves them.
 * Notifications never contain login links.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PCT_Guide_Profiles {

	const CPT             = 'pct_guide';
	const STATUS_PENDING  = 'pending';
	const STATUS_APPROVED = 'approved';
	const STATUS_REVOKED  = 'revoked';

	public static function init() {
		add_action( 'init', array( __CLASS__, 'register' ), 6 );
		add_action( 'admin_post_pct_profile_action', array( __CLASS__, 'handle_action' ) );
	}

	public static function register() {
		register_post_type(
			self::CPT,
			array(
				'labels'          => array( 'name' => __( 'Guide registrations', 'personalized-city-tour' ), 'singular_name' => __( 'Guide registration', 'personalized-city-tour' ) ),
				'public'          => false,
				'show_ui'         => false,
				'show_in_rest'    => false,
				'supports'        => array( 'title' ),
				'capability_type' => 'post',
				'map_meta_cap'    => true,
			)
		);
	}

	/**
	 * @return array|WP_Error { id, token, status }
	 */
	public static function create( array $data ) {
		$name   = sanitize_text_field( $data['name'] ?? '' );
		$mobile = PCT_SMS::normalize_mobile( $data['mobile'] ?? '' );
		$email  = sanitize_email( $data['email'] ?? '' );
		if ( '' === $name ) {
			return new WP_Error( 'pct_invalid', __( 'Please enter your name.', 'personalized-city-tour' ), array( 'status' => 400 ) );
		}
		if ( '' === $mobile && PCT_Settings::get( 'guide_require_mobile', 1 ) ) {
			return new WP_Error( 'pct_invalid', __( 'A valid mobile number is required so we can verify your registration.', 'personalized-city-tour' ), array( 'status' => 400 ) );
		}
		if ( $email && ! is_email( $email ) ) {
			$email = '';
		}

		$existing = $mobile ? self::by_mobile( $mobile ) : null;
		if ( $existing ) {
			// Same phone registering again: refresh the profile, keep the status, rotate the token.
			$post_id = $existing['id'];
		} else {
			$post_id = wp_insert_post(
				array(
					'post_type'   => self::CPT,
					'post_status' => 'private',
					'post_title'  => $name,
				),
				true
			);
			if ( is_wp_error( $post_id ) ) {
				return $post_id;
			}
			update_post_meta( $post_id, '_pct_status', self::STATUS_PENDING );
			update_post_meta( $post_id, '_pct_created', current_time( 'mysql' ) );
		}

		$token = wp_generate_password( 40, false, false );
		update_post_meta( $post_id, '_pct_token_hash', wp_hash( $token ) );
		update_post_meta( $post_id, '_pct_name', $name );
		update_post_meta( $post_id, '_pct_mobile', $mobile );
		update_post_meta( $post_id, '_pct_email', $email );
		update_post_meta( $post_id, '_pct_city_id', absint( $data['city_id'] ?? 0 ) );
		$languages = $data['languages'] ?? '';
		if ( is_array( $languages ) ) {
			$languages = implode( ', ', array_filter( array_map( 'sanitize_text_field', $languages ) ) );
		}
		update_post_meta( $post_id, '_pct_languages', sanitize_text_field( $languages ) );
		update_post_meta( $post_id, '_pct_note', sanitize_textarea_field( $data['note'] ?? '' ) );
		update_post_meta( $post_id, '_pct_lang', PCT_I18n::normalize( $data['lang'] ?? '' ) );
		wp_update_post( array( 'ID' => $post_id, 'post_title' => $name ) );

		$profile = self::get( $post_id );
		do_action( 'pct_guide_profile_registered', $profile, (bool) $existing );
		if ( ! $existing && PCT_Settings::get( 'notify_admin_guides' ) ) {
			wp_mail(
				get_option( 'admin_email' ),
				/* translators: %s: site name */
				sprintf( __( '[%s] New local guide registration', 'personalized-city-tour' ), get_bloginfo( 'name' ) ),
				sprintf(
					/* translators: 1: name, 2: mobile, 3: admin url */
					__( "%1\$s (%2\$s) registered as a local guide and is waiting for verification.\n\nReview: %3\$s", 'personalized-city-tour' ),
					$name,
					$mobile ? $mobile : $email,
					admin_url( 'admin.php?page=pct-guides' )
				)
			);
		}
		if ( ! $existing ) {
			PCT_SMS::send(
				$mobile,
				sprintf(
					/* translators: 1: name, 2: site name */
					__( 'Hello %1$s, your local guide registration at %2$s was received. We will call you to verify it; your events stay pending until then.', 'personalized-city-tour' ),
					$name,
					wp_specialchars_decode( get_bloginfo( 'name' ) )
				),
				$email,
				'guide_registered:' . $post_id
			);
		}

		return array(
			'id'     => $post_id,
			'token'  => $token,
			'status' => $profile['status'],
		);
	}

	public static function get( $id ) {
		$post = get_post( (int) $id );
		if ( ! $post || self::CPT !== $post->post_type ) {
			return null;
		}
		$status = (string) get_post_meta( $post->ID, '_pct_status', true );
		return array(
			'id'        => $post->ID,
			'name'      => (string) get_post_meta( $post->ID, '_pct_name', true ) ?: $post->post_title,
			'mobile'    => (string) get_post_meta( $post->ID, '_pct_mobile', true ),
			'email'     => (string) get_post_meta( $post->ID, '_pct_email', true ),
			'city_id'   => (int) get_post_meta( $post->ID, '_pct_city_id', true ),
			'languages' => (string) get_post_meta( $post->ID, '_pct_languages', true ),
			'note'      => (string) get_post_meta( $post->ID, '_pct_note', true ),
			'lang'      => (string) get_post_meta( $post->ID, '_pct_lang', true ),
			'status'    => $status ? $status : self::STATUS_PENDING,
			'verified'  => (string) get_post_meta( $post->ID, '_pct_verified_at', true ),
			'created'   => (string) get_post_meta( $post->ID, '_pct_created', true ),
			'events'    => self::event_count( $post->ID ),
		);
	}

	public static function by_token( $token ) {
		$token = (string) $token;
		if ( strlen( $token ) < 20 ) {
			return null;
		}
		$posts = get_posts(
			array(
				'post_type'   => self::CPT,
				'post_status' => 'private',
				'numberposts' => 1,
				'fields'      => 'ids',
				'meta_key'    => '_pct_token_hash', // phpcs:ignore WordPress.DB.SlowDBQuery
				'meta_value'  => wp_hash( $token ), // phpcs:ignore WordPress.DB.SlowDBQuery
			)
		);
		return $posts ? self::get( $posts[0] ) : null;
	}

	public static function by_mobile( $mobile ) {
		$posts = get_posts(
			array(
				'post_type'   => self::CPT,
				'post_status' => 'private',
				'numberposts' => 1,
				'fields'      => 'ids',
				'meta_key'    => '_pct_mobile', // phpcs:ignore WordPress.DB.SlowDBQuery
				'meta_value'  => $mobile, // phpcs:ignore WordPress.DB.SlowDBQuery
			)
		);
		return $posts ? self::get( $posts[0] ) : null;
	}

	/**
	 * Profile for the current request (X-PCT-Guide header or guide_token param).
	 */
	public static function current( ?WP_REST_Request $request = null ) {
		$token = '';
		if ( $request ) {
			$token = (string) $request->get_header( 'x-pct-guide' );
			if ( '' === $token ) {
				$token = (string) $request->get_param( 'guide_token' );
			}
		}
		return $token ? self::by_token( $token ) : null;
	}

	public static function all( $status = '' ) {
		$args = array(
			'post_type'   => self::CPT,
			'post_status' => 'private',
			'numberposts' => 200,
			'orderby'     => 'date',
			'order'       => 'DESC',
			'fields'      => 'ids',
		);
		if ( $status ) {
			$args['meta_key']   = '_pct_status'; // phpcs:ignore WordPress.DB.SlowDBQuery
			$args['meta_value'] = $status; // phpcs:ignore WordPress.DB.SlowDBQuery
		}
		return array_filter( array_map( array( __CLASS__, 'get' ), get_posts( $args ) ) );
	}

	public static function event_count( $profile_id ) {
		$posts = get_posts(
			array(
				'post_type'   => PCT_Post_Types::EVENT,
				'post_status' => array( 'publish', 'pending', 'draft' ),
				'numberposts' => -1,
				'fields'      => 'ids',
				'meta_key'    => '_pct_guide_profile', // phpcs:ignore WordPress.DB.SlowDBQuery
				'meta_value'  => (int) $profile_id, // phpcs:ignore WordPress.DB.SlowDBQuery
			)
		);
		return count( $posts );
	}

	public static function events( $profile_id ) {
		return get_posts(
			array(
				'post_type'   => PCT_Post_Types::EVENT,
				'post_status' => array( 'publish', 'pending', 'draft' ),
				'numberposts' => 50,
				'orderby'     => 'date',
				'order'       => 'DESC',
				'meta_key'    => '_pct_guide_profile', // phpcs:ignore WordPress.DB.SlowDBQuery
				'meta_value'  => (int) $profile_id, // phpcs:ignore WordPress.DB.SlowDBQuery
			)
		);
	}

	public static function set_status( $id, $status, $publish_events = false ) {
		$profile = self::get( $id );
		if ( ! $profile ) {
			return false;
		}
		update_post_meta( $id, '_pct_status', $status );
		if ( self::STATUS_APPROVED === $status ) {
			update_post_meta( $id, '_pct_verified_at', current_time( 'mysql' ) );
			update_post_meta( $id, '_pct_verified_by', get_current_user_id() );
			if ( $publish_events ) {
				foreach ( self::events( $id ) as $event ) {
					if ( 'pending' === $event->post_status ) {
						wp_update_post( array( 'ID' => $event->ID, 'post_status' => 'publish' ) );
					}
				}
			}
			self::notify( $profile, 'approved' );
		} elseif ( self::STATUS_REVOKED === $status ) {
			foreach ( self::events( $id ) as $event ) {
				if ( 'publish' === $event->post_status ) {
					wp_update_post( array( 'ID' => $event->ID, 'post_status' => 'pending' ) );
				}
			}
			self::notify( $profile, 'revoked' );
		}
		do_action( 'pct_guide_profile_status', $id, $status );
		return true;
	}

	public static function remove( $id ) {
		foreach ( self::events( $id ) as $event ) {
			wp_trash_post( $event->ID );
		}
		wp_delete_post( (int) $id, true );
	}

	private static function notify( array $profile, $what ) {
		$site = wp_specialchars_decode( get_bloginfo( 'name' ) );
		if ( 'approved' === $what ) {
			/* translators: 1: name, 2: site name */
			$msg = sprintf( __( 'Hello %1$s, your local guide registration at %2$s has been verified. Thank you — your events will be reviewed and published by the team.', 'personalized-city-tour' ), $profile['name'], $site );
		} else {
			/* translators: 1: name, 2: site name */
			$msg = sprintf( __( 'Hello %1$s, your local guide access at %2$s has been suspended. Please contact the site administrator.', 'personalized-city-tour' ), $profile['name'], $site );
		}
		PCT_SMS::send( $profile['mobile'], $msg, $profile['email'], 'guide_' . $what . ':' . $profile['id'] );
	}

	public static function handle_action() {
		check_admin_referer( 'pct_profile_action' );
		if ( ! current_user_can( 'pct_manage' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'personalized-city-tour' ) );
		}
		$id     = absint( $_POST['profile_id'] ?? 0 );
		$action = sanitize_key( $_POST['profile_action'] ?? '' );
		$msg    = 'error';
		if ( $id && self::get( $id ) ) {
			switch ( $action ) {
				case 'approve':
					self::set_status( $id, self::STATUS_APPROVED, ! empty( $_POST['publish_events'] ) );
					$msg = 'approve';
					break;
				case 'revoke':
					self::set_status( $id, self::STATUS_REVOKED );
					$msg = 'revoke';
					break;
				case 'remove':
					self::remove( $id );
					$msg = 'remove';
					break;
				case 'sms_done':
					PCT_SMS::mark_done( sanitize_text_field( wp_unslash( $_POST['sms_id'] ?? '' ) ) );
					$msg = 'sms_done';
					break;
			}
		} elseif ( 'sms_done' === $action ) {
			PCT_SMS::mark_done( sanitize_text_field( wp_unslash( $_POST['sms_id'] ?? '' ) ) );
			$msg = 'sms_done';
		}
		wp_safe_redirect( add_query_arg( array( 'page' => 'pct-guides', 'pct_msg' => $msg ), admin_url( 'admin.php' ) ) );
		exit;
	}
}
