<?php
/**
 * Native Web Push notifications for administrator devices.
 *
 * The browser creates a PushSubscription and the plugin stores it per
 * administrator. Notifications are sent with VAPID authentication. Payloads
 * are intentionally empty; the service worker displays the fixed message.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PCT_Push {

	const PUBLIC_KEY = 'push_vapid_public';
	const PRIVATE_KEY = 'push_vapid_private';
	const META_KEY = '_pct_push_subscriptions';

	public static function init() {
		add_action( 'template_redirect', array( __CLASS__, 'service_worker' ), 0 );
		add_action( 'admin_post_pct_push_test', array( __CLASS__, 'handle_test' ) );
	}

	public static function ensure_keys() {
		$public  = (string) PCT_Settings::get( self::PUBLIC_KEY, '' );
		$private = PCT_Settings::secret( self::PRIVATE_KEY );
		if ( $public && $private ) {
			return true;
		}
		if ( ! function_exists( 'openssl_pkey_new' ) || ! function_exists( 'openssl_pkey_export' ) ) {
			return false;
		}
		$key = openssl_pkey_new( array(
			'private_key_type' => OPENSSL_KEYTYPE_EC,
			'curve_name'       => 'prime256v1',
		) );
		if ( false === $key ) {
			return false;
		}
		$private_pem = '';
		if ( ! openssl_pkey_export( $key, $private_pem ) ) {
			return false;
		}
		$details = openssl_pkey_get_details( $key );
		if ( ! is_array( $details ) || empty( $details['ec']['x'] ) || empty( $details['ec']['y'] ) ) {
			return false;
		}
		$raw_public = "\x04" . $details['ec']['x'] . $details['ec']['y'];
		$public_b64 = self::b64url( $raw_public );
		PCT_Settings::set( self::PRIVATE_KEY, $private_pem );
		PCT_Settings::set( self::PUBLIC_KEY, $public_b64 );
		return true;
	}

	public static function public_key() {
		if ( ! self::ensure_keys() ) {
			return '';
		}
		return (string) PCT_Settings::get( self::PUBLIC_KEY, '' );
	}

	public static function enabled_for_admin() {
		return is_user_logged_in() && current_user_can( 'pct_manage' ) && '' !== self::public_key();
	}

	public static function config() {
		$admin = self::enabled_for_admin();
		return array(
			'available'   => (bool) self::public_key(),
			'admin'       => $admin,
			'publicKey'   => self::public_key(),
			'swUrl'       => esc_url_raw( add_query_arg( 'pct_push_sw', '1', home_url( '/' ) ) ),
			'subscribeUrl'=> esc_url_raw( rest_url( PCT_Rest::NS . '/vibe/push/subscribe' ) ),
			'unsubscribeUrl' => esc_url_raw( rest_url( PCT_Rest::NS . '/vibe/push/subscribe' ) ),
			'nonce'       => wp_create_nonce( 'wp_rest' ),
		);
	}

	public static function subscribe( $user_id, $subscription ) {
		if ( ! $user_id || ! is_array( $subscription ) ) {
			return new WP_Error( 'pct_push_invalid', __( 'Invalid push subscription.', 'personalized-city-tour' ), array( 'status' => 400 ) );
		}
		$endpoint = esc_url_raw( (string) ( $subscription['endpoint'] ?? '' ) );
		$p256dh   = self::clean_b64url( $subscription['keys']['p256dh'] ?? '' );
		$auth     = self::clean_b64url( $subscription['keys']['auth'] ?? '' );
		if ( '' === $endpoint || '' === $p256dh || '' === $auth || ! wp_http_validate_url( $endpoint ) ) {
			return new WP_Error( 'pct_push_invalid', __( 'Invalid push subscription.', 'personalized-city-tour' ), array( 'status' => 400 ) );
		}
		$all = get_user_meta( $user_id, self::META_KEY, true );
		$all = is_array( $all ) ? $all : array();
		$all[ md5( $endpoint ) ] = array(
			'endpoint' => $endpoint,
			'keys'     => array( 'p256dh' => $p256dh, 'auth' => $auth ),
			'created'  => time(),
			'updated'  => time(),
		);
		// Keep the newest ten devices per administrator.
		if ( count( $all ) > 10 ) {
			uasort( $all, function ( $a, $b ) { return (int) ( $b['updated'] ?? 0 ) <=> (int) ( $a['updated'] ?? 0 ); } );
			$all = array_slice( $all, 0, 10, true );
		}
		update_user_meta( $user_id, self::META_KEY, $all );
		return array( 'subscribed' => true );
	}

	public static function unsubscribe( $user_id, $endpoint ) {
		$endpoint = esc_url_raw( (string) $endpoint );
		$all = get_user_meta( $user_id, self::META_KEY, true );
		$all = is_array( $all ) ? $all : array();
		unset( $all[ md5( $endpoint ) ] );
		update_user_meta( $user_id, self::META_KEY, $all );
		return array( 'subscribed' => false );
	}

	public static function notify_otp_request() {
		if ( ! self::ensure_keys() ) {
			return 0;
		}
		// Do not rely on WP_User_Query's `capability` argument here. Custom
		// capabilities can be stored differently depending on the site's roles,
		// so enumerate users and test the capability explicitly.
		$users = get_users( array(
			'fields' => 'ids',
			'number' => -1,
		) );
		$sent = 0;
		foreach ( (array) $users as $user_id ) {
			if ( ! user_can( (int) $user_id, 'pct_manage' ) ) {
				continue;
			}
			$sent += self::notify_user( (int) $user_id );
		}
		return $sent;
	}

	/** Send the fixed OTP-request notification to one administrator's devices. */
	public static function notify_user( $user_id, &$errors = null ) {
		$user_id = absint( $user_id );
		if ( ! $user_id || ! user_can( $user_id, 'pct_manage' ) || ! self::ensure_keys() ) {
			if ( is_array( $errors ) ) {
				$errors[] = 'missing_push_keys_or_permission';
			}
			return 0;
		}
		$subs = get_user_meta( $user_id, self::META_KEY, true );
		$subs = is_array( $subs ) ? $subs : array();
		$sent = 0;
		foreach ( $subs as $hash => $sub ) {
			$res = self::send( $sub );
			if ( is_wp_error( $res ) ) {
				if ( is_array( $errors ) ) {
					$errors[] = array( 'code' => $res->get_error_code(), 'message' => $res->get_error_message() );
				}
				if ( in_array( (int) $res->get_error_code(), array( 404, 410 ), true ) ) {
					unset( $subs[ $hash ] );
				}
				continue;
			}
			$sent++;
		}
		update_user_meta( $user_id, self::META_KEY, $subs );
		return $sent;
	}

	public static function handle_test() {
		check_admin_referer( 'pct_push_test' );
		if ( ! current_user_can( 'pct_manage' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'personalized-city-tour' ) );
		}
		$errors = array();
		$count = self::notify_user( get_current_user_id(), $errors );
		if ( $errors ) {
			error_log( 'Personalized City Tour Web Push test failed: ' . wp_json_encode( $errors ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}
		$msg = $count > 0 ? 'push_test_ok' : 'push_test_error';
		set_transient( 'pct_push_test_last_' . get_current_user_id(), array( 'count' => $count, 'errors' => $errors, 'time' => time() ), 60 );
		wp_safe_redirect( add_query_arg( array( 'page' => 'pct-vibe', 'pct_msg' => $msg ), admin_url( 'admin.php' ) ) );
		exit;
	}

	private static function send( $sub ) {
		$endpoint = (string) ( $sub['endpoint'] ?? '' );
		if ( '' === $endpoint ) {
			return new WP_Error( 'pct_push_invalid' );
		}
		$private = PCT_Settings::secret( self::PRIVATE_KEY );
		$public  = (string) PCT_Settings::get( self::PUBLIC_KEY, '' );
		if ( '' === $private || '' === $public ) {
			return new WP_Error( 'pct_push_keys' );
		}
		$aud = self::origin( $endpoint );
		if ( '' === $aud ) {
			return new WP_Error( 'pct_push_endpoint' );
		}
		$subject = 'mailto:' . sanitize_email( get_option( 'admin_email' ) );
		$jwt = self::jwt( $private, $aud, $subject );
		if ( is_wp_error( $jwt ) ) {
			return $jwt;
		}
		$res = wp_remote_post( $endpoint, array(
			'timeout' => 12,
			'headers' => array(
			'TTL'           => '60',
			'Urgency'       => 'high',
			'Authorization' => 'vapid t=' . $jwt . ', k=' . $public,
		),
			'body'    => '',
			'httpversion' => '1.1',
		) );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$code = (int) wp_remote_retrieve_response_code( $res );
		if ( $code >= 200 && $code < 300 ) {
			return true;
		}
		if ( in_array( $code, array( 404, 410 ), true ) ) {
			return new WP_Error( $code, 'Push subscription expired.' );
		}
				$body = trim( wp_remote_retrieve_body( $res ) );
		$detail = 'Push service rejected the request (HTTP ' . $code . ').';
		if ( $body !== '' ) {
			$decoded = json_decode( $body, true );
			if ( is_array( $decoded ) ) {
				$detail .= ' ' . sanitize_text_field( (string) ( $decoded['error'] ?? $decoded['message'] ?? wp_json_encode( $decoded ) ) );
			} else {
				$detail .= ' ' . sanitize_text_field( wp_trim_words( $body, 30, '…' ) );
			}
		}
		return new WP_Error( 'pct_push_http_' . $code, $detail );
	}

	private static function origin( $url ) {
		$p = wp_parse_url( $url );
		if ( empty( $p['scheme'] ) || empty( $p['host'] ) ) {
			return '';
		}
		return strtolower( $p['scheme'] . '://' . $p['host'] . ( isset( $p['port'] ) ? ':' . (int) $p['port'] : '' ) );
	}

	private static function jwt( $private_pem, $aud, $sub ) {
		$header  = self::b64url( wp_json_encode( array( 'typ' => 'JWT', 'alg' => 'ES256' ) ) );
		$payload = self::b64url( wp_json_encode( array( 'aud' => $aud, 'exp' => time() + 12 * HOUR_IN_SECONDS, 'sub' => $sub ) ) );
		$data = $header . '.' . $payload;
		$sig = '';
		if ( ! openssl_sign( $data, $sig, $private_pem, OPENSSL_ALGO_SHA256 ) ) {
			return new WP_Error( 'pct_push_sign', __( 'Could not sign the push notification.', 'personalized-city-tour' ) );
		}
		$raw = self::der_to_raw_signature( $sig );
		if ( false === $raw ) {
			return new WP_Error( 'pct_push_sign', __( 'Could not sign the push notification.', 'personalized-city-tour' ) );
		}
		return $data . '.' . self::b64url( $raw );
	}

	private static function der_to_raw_signature( $der ) {
		if ( strlen( $der ) < 8 || "\x30" !== $der[0] ) {
			return false;
		}
		$pos = 2;
		if ( "\x02" !== $der[ $pos ] ) { return false; }
		$len = ord( $der[ $pos + 1 ] );
		$pos += 2;
		$r = substr( $der, $pos, $len );
		$pos += $len;
		if ( "\x02" !== ( $der[ $pos ] ?? '' ) ) { return false; }
		$len = ord( $der[ $pos + 1 ] );
		$pos += 2;
		$s = substr( $der, $pos, $len );
		$r = ltrim( $r, "\x00" );
		$s = ltrim( $s, "\x00" );
		return str_pad( $r, 32, "\x00", STR_PAD_LEFT ) . str_pad( $s, 32, "\x00", STR_PAD_LEFT );
	}

	private static function b64url( $raw ) {
		return rtrim( strtr( base64_encode( (string) $raw ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}

	private static function clean_b64url( $value ) {
		$value = preg_replace( '/[^A-Za-z0-9_-]/', '', (string) $value );
		return $value;
	}

	public static function service_worker() {
		if ( ! isset( $_GET['pct_push_sw'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}
		header( 'Content-Type: application/javascript; charset=utf-8' );
		header( 'Cache-Control: no-cache, no-store, must-revalidate' );
		$target = admin_url( 'admin.php?page=pct-vibe#pct-sms-outbox' );
		$target = wp_json_encode( esc_url_raw( $target ) );
		echo "self.addEventListener('push',function(){self.registration.showNotification('درخواست تولید عکس آمده است',{body:'درخواست تولید عکس آمده است',icon:" . wp_json_encode( get_site_icon_url( 192 ) ?: '' ) . ",badge:" . wp_json_encode( get_site_icon_url( 192 ) ?: '' ) . ",data:{url:" . $target . "},tag:'pct-vibe-otp',renotify:true,vibrate:[200,100,200]});});";
		echo "self.addEventListener('notificationclick',function(e){e.notification.close();var u=(e.notification.data&&e.notification.data.url)||" . wp_json_encode( home_url( '/' ) ) . ";e.waitUntil(clients.matchAll({type:'window',includeUncontrolled:true}).then(function(cs){for(var i=0;i<cs.length;i++){if('focus' in cs[i]){cs[i].navigate(u);return cs[i].focus();}}return clients.openWindow(u);}));});";
		exit;
	}
}
