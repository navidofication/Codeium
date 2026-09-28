<?php
/**
 * SMS / notification abstraction used by the guide workflow and "My Trip, My Vibe".
 *
 * Providers (settings: sms_provider):
 *   manual  – messages are queued in an admin outbox so the administrator can
 *             send them from their own phone (default, no credentials needed)
 *   email   – the text is delivered by email when the recipient has an address
 *   webhook – POST {to, message, sender} JSON to a bulk-SMS gateway endpoint
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PCT_SMS {

	const OUTBOX_OPT = 'pct_sms_outbox';

	public static function init() {
		add_action( 'admin_post_pct_sms_done', array( __CLASS__, 'handle_done' ) );
	}

	public static function providers() {
		return array(
			'manual'  => __( 'Manual — queue for the administrator\'s phone', 'personalized-city-tour' ),
			'email'   => __( 'Email only', 'personalized-city-tour' ),
			'webhook' => __( 'Bulk SMS gateway (JSON webhook)', 'personalized-city-tour' ),
		);
	}

	public static function normalize_mobile( $mobile ) {
		$mobile = strtr( (string) $mobile, array( '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9' ) );
		$mobile = preg_replace( '/[^0-9+]/', '', $mobile );
		$digits = preg_replace( '/\D/', '', $mobile );
		if ( strlen( $digits ) < 7 ) {
			return '';
		}
		if ( 0 === strpos( $mobile, '00' ) ) {
			return '+' . substr( $digits, 2 );
		}
		if ( 0 === strpos( $mobile, '+' ) ) {
			return '+' . $digits;
		}
		$cc = preg_replace( '/\D/', '', (string) PCT_Settings::get( 'sms_country_code', '' ) );
		if ( '' === $cc ) {
			return $digits;
		}
		if ( 0 === strpos( $digits, '0' ) ) {
			return '+' . $cc . ltrim( $digits, '0' );
		}
		if ( 0 === strpos( $digits, $cc ) && strlen( $digits ) > strlen( $cc ) + 6 ) {
			return '+' . $digits;
		}
		return '+' . $cc . $digits;
	}

	/**
	 * Send a message. Returns true, 'queued' or WP_Error.
	 */
	public static function send( $mobile, $message, $email = '', $context = '' ) {
		$mobile   = self::normalize_mobile( $mobile );
		$provider = PCT_Settings::get( 'sms_provider', 'manual' );
		$message  = wp_strip_all_tags( (string) $message );

		if ( 'webhook' === $provider && $mobile ) {
			$endpoint = (string) PCT_Settings::get( 'sms_endpoint' );
			if ( '' === $endpoint ) {
				return new WP_Error( 'pct_sms_config', __( 'SMS gateway endpoint is not configured.', 'personalized-city-tour' ) );
			}
			$res = wp_remote_post(
				$endpoint,
				array(
					'timeout' => 20,
					'headers' => array(
						'Content-Type'  => 'application/json',
						'Authorization' => 'Bearer ' . PCT_Settings::secret( 'sms_api_key' ),
					),
					'body'    => wp_json_encode( array( 'to' => $mobile, 'message' => $message, 'sender' => PCT_Settings::get( 'sms_sender' ) ) ),
				)
			);
			if ( is_wp_error( $res ) ) {
				return $res;
			}
			$code = wp_remote_retrieve_response_code( $res );
			if ( $code < 200 || $code >= 300 ) {
				/* translators: %s: HTTP status */
				return new WP_Error( 'pct_sms_http', sprintf( __( 'SMS gateway returned HTTP %s.', 'personalized-city-tour' ), $code ) );
			}
			return true;
		}

		$sent = false;
		if ( $email && is_email( $email ) ) {
			$sent = wp_mail( $email, wp_specialchars_decode( get_bloginfo( 'name' ) ), $message );
		}
		if ( 'email' === $provider ) {
			return $sent ? true : new WP_Error( 'pct_sms_email', __( 'No email address available for this recipient.', 'personalized-city-tour' ) );
		}

		// Manual: queue so the administrator can send it from their own phone.
		if ( $mobile ) {
			$outbox   = (array) get_option( self::OUTBOX_OPT, array() );
			$outbox[] = array(
				'id'      => wp_generate_password( 8, false, false ),
				'time'    => current_time( 'mysql' ),
				'to'      => $mobile,
				'message' => $message,
				'context' => (string) $context,
				'done'    => 0,
			);
			update_option( self::OUTBOX_OPT, array_slice( $outbox, -200 ), false );
			return 'queued';
		}
		return $sent ? true : new WP_Error( 'pct_sms_none', __( 'No delivery channel available.', 'personalized-city-tour' ) );
	}

	public static function outbox( $pending_only = true ) {
		$rows = (array) get_option( self::OUTBOX_OPT, array() );
		if ( $pending_only ) {
			$rows = array_filter( $rows, function ( $r ) { return empty( $r['done'] ); } );
		}
		return array_reverse( array_values( $rows ) );
	}

	public static function mark_done( $id ) {
		$rows = (array) get_option( self::OUTBOX_OPT, array() );
		foreach ( $rows as &$r ) {
			if ( $r['id'] === $id ) {
				$r['done'] = 1;
			}
		}
		unset( $r );
		update_option( self::OUTBOX_OPT, $rows, false );
	}

	public static function handle_done() {
		check_admin_referer( 'pct_sms_done' );
		if ( ! current_user_can( 'pct_manage' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'personalized-city-tour' ) );
		}
		self::mark_done( sanitize_text_field( wp_unslash( $_POST['sms_id'] ?? '' ) ) );
		$back = isset( $_POST['back'] ) ? sanitize_key( $_POST['back'] ) : 'pct-vibe';
		wp_safe_redirect( add_query_arg( array( 'page' => $back, 'pct_msg' => 'sms_done' ), admin_url( 'admin.php' ) ) );
		exit;
	}
}
