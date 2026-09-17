<?php
/**
 * Local guide management screen (define, approve, revoke, remove).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PCT_Guides {

	public static function init() {
		add_action( 'admin_post_pct_guide_action', array( __CLASS__, 'handle_action' ) );
		add_action( 'admin_post_pct_guide_add', array( __CLASS__, 'handle_add' ) );
		add_action( 'pct_guide_approved', array( __CLASS__, 'notify_approved' ) );
	}

	public static function handle_action() {
		check_admin_referer( 'pct_guide_action' );
		if ( ! current_user_can( 'pct_manage' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'personalized-city-tour' ) );
		}
		$uid    = absint( $_POST['user_id'] ?? 0 );
		$action = sanitize_key( $_POST['guide_action'] ?? '' );
		if ( $uid && in_array( $action, array( 'approve', 'revoke', 'remove' ), true ) && ! user_can( $uid, 'pct_manage' ) ) {
			call_user_func( array( 'PCT_Roles', $action ), $uid );
			$msg = $action;
		} else {
			$msg = 'error';
		}
		wp_safe_redirect( add_query_arg( array( 'page' => 'pct-guides', 'pct_msg' => $msg ), admin_url( 'admin.php' ) ) );
		exit;
	}

	public static function handle_add() {
		check_admin_referer( 'pct_guide_add' );
		if ( ! current_user_can( 'pct_manage' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'personalized-city-tour' ) );
		}
		$lookup = sanitize_text_field( wp_unslash( $_POST['user'] ?? '' ) );
		$user   = is_email( $lookup ) ? get_user_by( 'email', $lookup ) : get_user_by( 'login', $lookup );
		if ( ! $user && is_numeric( $lookup ) ) {
			$user = get_user_by( 'id', (int) $lookup );
		}
		if ( ! $user ) {
			$msg = 'nouser';
		} elseif ( user_can( $user, 'pct_manage' ) ) {
			$msg = 'isadmin';
		} else {
			PCT_Roles::apply( $user->ID );
			update_user_meta( $user->ID, '_pct_guide_city', absint( $_POST['city_id'] ?? 0 ) );
			if ( ! empty( $_POST['approve_now'] ) ) {
				PCT_Roles::approve( $user->ID );
			}
			$msg = 'added';
		}
		wp_safe_redirect( add_query_arg( array( 'page' => 'pct-guides', 'pct_msg' => $msg ), admin_url( 'admin.php' ) ) );
		exit;
	}

	public static function notify_approved( $user_id ) {
		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return;
		}
		wp_mail(
			$user->user_email,
			/* translators: %s: site name */
			sprintf( __( '[%s] You are now a local guide', 'personalized-city-tour' ), get_bloginfo( 'name' ) ),
			sprintf(
				/* translators: 1: display name, 2: login url */
				__( "Hi %1\$s,\n\nYour local guide account has been approved. You can now register events and city data entries.\n\nLog in: %2\$s", 'personalized-city-tour' ),
				$user->display_name,
				wp_login_url()
			)
		);
	}

	public static function render() {
		$guides   = PCT_Roles::guides();
		$cities   = PCT_Post_Types::cities();
		$messages = array(
			'approve' => __( 'Guide approved.', 'personalized-city-tour' ),
			'revoke'  => __( 'Guide access revoked.', 'personalized-city-tour' ),
			'remove'  => __( 'Guide removed.', 'personalized-city-tour' ),
			'added'   => __( 'User added as local guide.', 'personalized-city-tour' ),
			'nouser'  => __( 'No user found with that email or username.', 'personalized-city-tour' ),
			'isadmin' => __( 'Administrators already have full access.', 'personalized-city-tour' ),
			'error'   => __( 'Invalid request.', 'personalized-city-tour' ),
		);
		$msg      = isset( $_GET['pct_msg'] ) ? sanitize_key( $_GET['pct_msg'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		include PCT_DIR . 'admin/views/guides.php';
	}
}
