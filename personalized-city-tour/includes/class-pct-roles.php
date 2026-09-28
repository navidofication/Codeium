<?php
/**
 * Roles and capabilities.
 *
 * Local guides may create and edit their own city data entries and events.
 * Their submissions are held for review unless "auto publish" is enabled.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PCT_Roles {

	const GUIDE_ROLE  = 'pct_local_guide';
	const STATUS_META = '_pct_guide_status';

	const STATUS_PENDING  = 'pending';
	const STATUS_APPROVED = 'approved';
	const STATUS_REVOKED  = 'revoked';

	public static function init() {
		add_filter( 'map_meta_cap', array( __CLASS__, 'restrict_guide_publish' ), 10, 4 );
	}

	public static function guide_caps( $can_publish = false ) {
		$caps = array(
			'read'                       => true,
			'upload_files'               => true,
			'edit_pct_places'            => true,
			'edit_pct_place'             => true,
			'delete_pct_places'          => true,
			'delete_pct_place'           => true,
			'read_pct_place'             => true,
			'edit_pct_events'            => true,
			'edit_pct_event'             => true,
			'delete_pct_events'          => true,
			'delete_pct_event'           => true,
			'read_pct_event'             => true,
			'pct_submit_events'          => true,
		);
		if ( $can_publish ) {
			$caps['publish_pct_places'] = true;
			$caps['publish_pct_events'] = true;
		}
		return $caps;
	}

	public static function admin_caps() {
		return array(
			'edit_pct_places',
			'edit_pct_place',
			'edit_others_pct_places',
			'edit_published_pct_places',
			'edit_private_pct_places',
			'publish_pct_places',
			'delete_pct_places',
			'delete_pct_place',
			'delete_others_pct_places',
			'delete_published_pct_places',
			'delete_private_pct_places',
			'read_pct_place',
			'read_private_pct_places',
			'edit_pct_events',
			'edit_pct_event',
			'edit_others_pct_events',
			'edit_published_pct_events',
			'edit_private_pct_events',
			'publish_pct_events',
			'delete_pct_events',
			'delete_pct_event',
			'delete_others_pct_events',
			'delete_published_pct_events',
			'delete_private_pct_events',
			'read_pct_event',
			'read_private_pct_events',
			'pct_submit_events',
			'pct_manage',
		);
	}

	public static function add_roles() {
		$settings = PCT_Settings::get();
		remove_role( self::GUIDE_ROLE );
		add_role( self::GUIDE_ROLE, __( 'Local Guide', 'personalized-city-tour' ), self::guide_caps( ! empty( $settings['guide_auto_publish'] ) ) );

		foreach ( array( 'administrator', 'editor' ) as $role_name ) {
			$role = get_role( $role_name );
			if ( ! $role ) {
				continue;
			}
			foreach ( self::admin_caps() as $cap ) {
				if ( 'pct_manage' === $cap && 'editor' === $role_name ) {
					continue;
				}
				$role->add_cap( $cap );
			}
		}
	}

	/**
	 * Guides without publish caps can never publish (even via quick edit).
	 */
	public static function restrict_guide_publish( $caps, $cap, $user_id, $args ) {
		if ( in_array( $cap, array( 'publish_pct_places', 'publish_pct_events' ), true ) ) {
			$user = get_userdata( $user_id );
			if ( $user && in_array( self::GUIDE_ROLE, (array) $user->roles, true ) && self::STATUS_APPROVED !== self::guide_status( $user_id ) ) {
				return array( 'do_not_allow' );
			}
		}
		return $caps;
	}

	public static function guide_status( $user_id ) {
		return (string) get_user_meta( $user_id, self::STATUS_META, true );
	}

	public static function is_approved_guide( $user_id = 0 ) {
		$user_id = $user_id ? $user_id : get_current_user_id();
		if ( ! $user_id ) {
			return false;
		}
		if ( user_can( $user_id, 'pct_manage' ) ) {
			return true;
		}
		$user = get_userdata( $user_id );
		return $user && in_array( self::GUIDE_ROLE, (array) $user->roles, true ) && self::STATUS_APPROVED === self::guide_status( $user_id );
	}

	/**
	 * Mark an existing user as a pending guide (keeps current role).
	 */
	public static function apply( $user_id ) {
		update_user_meta( $user_id, self::STATUS_META, self::STATUS_PENDING );
		update_user_meta( $user_id, '_pct_guide_applied', time() );
	}

	public static function approve( $user_id ) {
		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return false;
		}
		if ( ! user_can( $user, 'pct_manage' ) ) {
			$user->set_role( self::GUIDE_ROLE );
		}
		update_user_meta( $user_id, self::STATUS_META, self::STATUS_APPROVED );
		do_action( 'pct_guide_approved', $user_id );
		return true;
	}

	public static function revoke( $user_id ) {
		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return false;
		}
		if ( in_array( self::GUIDE_ROLE, (array) $user->roles, true ) ) {
			$user->set_role( get_option( 'default_role', 'subscriber' ) );
		}
		update_user_meta( $user_id, self::STATUS_META, self::STATUS_REVOKED );
		do_action( 'pct_guide_revoked', $user_id );
		return true;
	}

	public static function remove( $user_id ) {
		self::revoke( $user_id );
		delete_user_meta( $user_id, self::STATUS_META );
		delete_user_meta( $user_id, '_pct_guide_applied' );
		delete_user_meta( $user_id, '_pct_guide_city' );
		delete_user_meta( $user_id, '_pct_guide_note' );
		return true;
	}

	/**
	 * Users that applied to be, or are, local guides.
	 */
	public static function guides( $status = '' ) {
		$args = array(
			'meta_key' => self::STATUS_META, // phpcs:ignore WordPress.DB.SlowDBQuery
			'orderby'  => 'registered',
			'order'    => 'DESC',
			'number'   => 200,
		);
		if ( $status ) {
			$args['meta_value'] = $status; // phpcs:ignore WordPress.DB.SlowDBQuery
		}
		return get_users( $args );
	}
}
