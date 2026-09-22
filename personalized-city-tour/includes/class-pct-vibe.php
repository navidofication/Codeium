<?php
/**
 * "My Trip, My Vibe" – AI illustration requests.
 *
 * Flow: visitor verifies a mobile number with an OTP → uploads a photo and picks
 * vibe / style / subject → request is stored (pct_vibe, pending) → admin approves
 * → the image is generated (mock, OpenAI-compatible or custom endpoint) → the
 * result is delivered by SMS link, email or manually by the administrator.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PCT_Vibe {

	const CPT    = 'pct_vibe';
	const OPTION = 'pct_vibe_settings';
	const CRON   = 'pct_vibe_generate';

	public static function init() {
		add_action( 'init', array( __CLASS__, 'register' ), 6 );
		add_action( self::CRON, array( __CLASS__, 'generate' ) );
		add_action( 'admin_post_pct_vibe_settings', array( __CLASS__, 'handle_settings' ) );
		add_action( 'admin_post_pct_vibe_action', array( __CLASS__, 'handle_action' ) );
	}

	public static function register() {
		register_post_type(
			self::CPT,
			array(
				'labels'          => array( 'name' => __( 'Vibe requests', 'personalized-city-tour' ), 'singular_name' => __( 'Vibe request', 'personalized-city-tour' ) ),
				'public'          => false,
				'show_ui'         => false,
				'supports'        => array( 'title' ),
				'capability_type' => 'post',
				'map_meta_cap'    => true,
			)
		);
	}

	/* ------------------------------------------------------------------ */
	/* Settings                                                             */
	/* ------------------------------------------------------------------ */

	public static function defaults() {
		return array(
			'enabled'         => 1,
			'fee_text'        => __( 'This service costs a small fee that is settled with the administrator after approval.', 'personalized-city-tour' ),
			'vibes'           => "Calm & nostalgic\nEnergetic & colorful\nRomantic\nAdventurous\nFamily fun\nArtistic & dreamy",
			'styles'          => "Watercolor\nLine art\nComic / pop-art\nOil painting\nMinimal flat illustration\nPersian miniature",
			'subjects'        => "Me in front of a landmark\nMy travel group\nThe city skyline\nA street scene\nLocal food moment",
			'prompt_template' => "Create a {style} illustration of {subject} in {city}. Mood: {vibe}. Notes from the traveller: {notes}. Keep faces respectful and stylised; no text, no logos, no watermarks.",
			'require_approval' => 1,
			'delivery'        => 'sms',
			'otp_ttl'         => 10,
			'max_upload_mb'   => 8,
			'image_size'      => '1024x1024',
			'sandbox'         => 1,
		);
	}

	public static function settings() {
		return wp_parse_args( (array) get_option( self::OPTION, array() ), self::defaults() );
	}

	public static function options_list( $key ) {
		$lines = preg_split( '/\r\n|\r|\n/', (string) self::settings()[ $key ] );
		return array_values( array_filter( array_map( 'trim', $lines ) ) );
	}

	public static function enabled() {
		return (bool) self::settings()['enabled'];
	}

	public static function handle_settings() {
		check_admin_referer( 'pct_vibe_settings' );
		if ( ! current_user_can( 'pct_manage' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'personalized-city-tour' ) );
		}
		$in  = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$out = array(
			'enabled'          => empty( $in['enabled'] ) ? 0 : 1,
			'fee_text'         => sanitize_textarea_field( $in['fee_text'] ?? '' ),
			'vibes'            => sanitize_textarea_field( $in['vibes'] ?? '' ),
			'styles'           => sanitize_textarea_field( $in['styles'] ?? '' ),
			'subjects'         => sanitize_textarea_field( $in['subjects'] ?? '' ),
			'prompt_template'  => sanitize_textarea_field( $in['prompt_template'] ?? '' ),
			'require_approval' => empty( $in['require_approval'] ) ? 0 : 1,
			'delivery'         => in_array( $in['delivery'] ?? '', array( 'sms', 'email', 'manual' ), true ) ? $in['delivery'] : 'sms',
			'otp_ttl'          => max( 2, min( 60, absint( $in['otp_ttl'] ?? 10 ) ) ),
			'max_upload_mb'    => max( 1, min( 50, absint( $in['max_upload_mb'] ?? 8 ) ) ),
			'image_size'       => in_array( $in['image_size'] ?? '', array( '1024x1024', '1024x1536', '1536x1024', '512x512' ), true ) ? $in['image_size'] : '1024x1024',
			'sandbox'          => empty( $in['sandbox'] ) ? 0 : 1,
		);
		update_option( self::OPTION, $out, false );
		wp_safe_redirect( add_query_arg( array( 'page' => 'pct-vibe', 'pct_msg' => 'saved' ), admin_url( 'admin.php' ) ) );
		exit;
	}

	/* ------------------------------------------------------------------ */
	/* OTP                                                                  */
	/* ------------------------------------------------------------------ */

	private static function otp_key( $mobile ) {
		return 'pct_otp_' . md5( $mobile . wp_salt( 'auth' ) );
	}

	/**
	 * @return array|WP_Error { sent, channel, dev_code? }
	 */
	public static function otp_request( $mobile ) {
		$mobile = PCT_SMS::normalize_mobile( $mobile );
		if ( '' === $mobile ) {
			return new WP_Error( 'pct_invalid', __( 'Please enter a valid mobile number.', 'personalized-city-tour' ), array( 'status' => 400 ) );
		}
		$key  = self::otp_key( $mobile );
		$prev = get_transient( $key );
		if ( is_array( $prev ) && $prev['sent'] > time() - 60 ) {
			return new WP_Error( 'pct_otp_wait', __( 'A code was sent less than a minute ago. Please wait before requesting another.', 'personalized-city-tour' ), array( 'status' => 429 ) );
		}
		$code = (string) wp_rand( 100000, 999999 );
		$ttl  = (int) self::settings()['otp_ttl'] * MINUTE_IN_SECONDS;
		set_transient( $key, array( 'hash' => wp_hash( $code . $mobile ), 'sent' => time(), 'tries' => 0 ), $ttl );
		/* translators: 1: code, 2: site name */
		$msg = sprintf( __( '%1$s is your verification code for %2$s.', 'personalized-city-tour' ), $code, wp_specialchars_decode( get_bloginfo( 'name' ) ) );
		$res = PCT_SMS::send( $mobile, $msg, '', 'otp' );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$out = array( 'sent' => true, 'channel' => true === $res ? 'sms' : 'queued' );
		if ( self::settings()['sandbox'] ) {
			// Sandbox: expose the code so the flow can be tested without an SMS gateway.
			$out['dev_code'] = $code;
		}
		return $out;
	}

	/**
	 * @return array|WP_Error { token }
	 */
	public static function otp_verify( $mobile, $code ) {
		$mobile = PCT_SMS::normalize_mobile( $mobile );
		$code   = preg_replace( '/\D/', '', strtr( (string) $code, array( '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9' ) ) );
		$key    = self::otp_key( $mobile );
		$row    = get_transient( $key );
		if ( ! is_array( $row ) ) {
			return new WP_Error( 'pct_otp_expired', __( 'The code has expired. Please request a new one.', 'personalized-city-tour' ), array( 'status' => 400 ) );
		}
		if ( $row['tries'] >= 5 ) {
			delete_transient( $key );
			return new WP_Error( 'pct_otp_locked', __( 'Too many attempts. Please request a new code.', 'personalized-city-tour' ), array( 'status' => 429 ) );
		}
		if ( ! hash_equals( $row['hash'], wp_hash( $code . $mobile ) ) ) {
			$row['tries']++;
			set_transient( $key, $row, (int) self::settings()['otp_ttl'] * MINUTE_IN_SECONDS );
			return new WP_Error( 'pct_otp_wrong', __( 'Incorrect code.', 'personalized-city-tour' ), array( 'status' => 400 ) );
		}
		delete_transient( $key );
		$token = wp_generate_password( 40, false, false );
		set_transient( 'pct_vtok_' . wp_hash( $token ), $mobile, DAY_IN_SECONDS );
		return array( 'token' => $token, 'mobile' => $mobile );
	}

	public static function visitor_mobile( $token ) {
		$token = (string) $token;
		if ( strlen( $token ) < 20 ) {
			return '';
		}
		return (string) get_transient( 'pct_vtok_' . wp_hash( $token ) );
	}

	/* ------------------------------------------------------------------ */
	/* Requests                                                             */
	/* ------------------------------------------------------------------ */

	public static function statuses() {
		return array(
			'pending'    => __( 'Waiting for approval', 'personalized-city-tour' ),
			'approved'   => __( 'Approved — generating', 'personalized-city-tour' ),
			'generating' => __( 'Generating', 'personalized-city-tour' ),
			'done'       => __( 'Image ready', 'personalized-city-tour' ),
			'delivered'  => __( 'Delivered', 'personalized-city-tour' ),
			'rejected'   => __( 'Rejected', 'personalized-city-tour' ),
			'failed'     => __( 'Generation failed', 'personalized-city-tour' ),
		);
	}

	/**
	 * @param array $data  mobile, name, email, city_id, vibe, style, subject, notes, lang
	 * @param array $file  $_FILES['photo'] entry (optional).
	 */
	public static function create( array $data, array $file = array() ) {
		if ( ! self::enabled() ) {
			return new WP_Error( 'pct_disabled', __( 'This service is not available right now.', 'personalized-city-tour' ), array( 'status' => 403 ) );
		}
		$s      = self::settings();
		$mobile = PCT_SMS::normalize_mobile( $data['mobile'] ?? '' );
		$pick   = function ( $key, $list ) use ( $data ) {
			$v = sanitize_text_field( $data[ $key ] ?? '' );
			return in_array( $v, $list, true ) ? $v : ( $list ? $list[0] : '' );
		};
		$vibe    = $pick( 'vibe', self::options_list( 'vibes' ) );
		$style   = $pick( 'style', self::options_list( 'styles' ) );
		$subject = $pick( 'subject', self::options_list( 'subjects' ) );
		$name    = sanitize_text_field( $data['name'] ?? '' );
		$city    = PCT_Data::city( absint( $data['city_id'] ?? 0 ) );

		$post_id = wp_insert_post(
			array(
				'post_type'   => self::CPT,
				'post_status' => 'private',
				/* translators: 1: name or mobile, 2: vibe */
				'post_title'  => sprintf( __( '%1$s — %2$s', 'personalized-city-tour' ), $name ? $name : $mobile, $vibe ),
			),
			true
		);
		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}
		$meta = array(
			'_pct_status'  => 'pending',
			'_pct_mobile'  => $mobile,
			'_pct_name'    => $name,
			'_pct_email'   => sanitize_email( $data['email'] ?? '' ),
			'_pct_city_id' => $city ? $city['id'] : 0,
			'_pct_city'    => $city ? $city['name'] : sanitize_text_field( $data['city'] ?? '' ),
			'_pct_vibe'    => $vibe,
			'_pct_style'   => $style,
			'_pct_subject' => $subject,
			'_pct_notes'   => sanitize_textarea_field( $data['notes'] ?? '' ),
			'_pct_lang'    => PCT_I18n::normalize( $data['lang'] ?? '' ),
			'_pct_token'   => wp_generate_password( 24, false, false ),
			'_pct_created' => current_time( 'mysql' ),
		);
		foreach ( $meta as $k => $v ) {
			update_post_meta( $post_id, $k, $v );
		}

		if ( $file && ! empty( $file['tmp_name'] ) ) {
			$att = self::store_upload( $file, $post_id, (int) $s['max_upload_mb'] );
			if ( is_wp_error( $att ) ) {
				wp_delete_post( $post_id, true );
				return $att;
			}
			update_post_meta( $post_id, '_pct_photo_id', $att );
		}

		if ( PCT_Settings::get( 'admin_mobile' ) || get_option( 'admin_email' ) ) {
			PCT_SMS::send(
				PCT_Settings::get( 'admin_mobile' ),
				/* translators: 1: name, 2: vibe, 3: admin url */
				sprintf( __( 'New "My Trip, My Vibe" request from %1$s (%2$s). Review: %3$s', 'personalized-city-tour' ), $name ? $name : $mobile, $vibe, admin_url( 'admin.php?page=pct-vibe' ) ),
				get_option( 'admin_email' ),
				'vibe_new:' . $post_id
			);
		}

		if ( empty( $s['require_approval'] ) ) {
			self::approve( $post_id );
		}
		return self::get( $post_id );
	}

	private static function store_upload( array $file, $post_id, $max_mb ) {
		if ( ! function_exists( 'wp_handle_upload' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/image.php';
			require_once ABSPATH . 'wp-admin/includes/media.php';
		}
		if ( (int) $file['size'] > $max_mb * MB_IN_BYTES ) {
			/* translators: %d: megabytes */
			return new WP_Error( 'pct_too_large', sprintf( __( 'The photo is too large (max %d MB).', 'personalized-city-tour' ), $max_mb ), array( 'status' => 400 ) );
		}
		$check = wp_check_filetype_and_ext( $file['tmp_name'], $file['name'], array( 'jpg|jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp', 'heic' => 'image/heic' ) );
		if ( empty( $check['type'] ) ) {
			return new WP_Error( 'pct_bad_type', __( 'Please upload a JPG, PNG or WEBP photo.', 'personalized-city-tour' ), array( 'status' => 400 ) );
		}
		$upload = wp_handle_upload( $file, array( 'test_form' => false, 'mimes' => array( 'jpg|jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp', 'heic' => 'image/heic' ) ) );
		if ( isset( $upload['error'] ) ) {
			return new WP_Error( 'pct_upload', $upload['error'], array( 'status' => 400 ) );
		}
		$att = wp_insert_attachment(
			array(
				'post_mime_type' => $upload['type'],
				'post_title'     => 'vibe-photo-' . $post_id,
				'post_status'    => 'private',
				'post_parent'    => $post_id,
			),
			$upload['file'],
			$post_id
		);
		if ( is_wp_error( $att ) ) {
			return $att;
		}
		wp_update_attachment_metadata( $att, wp_generate_attachment_metadata( $att, $upload['file'] ) );
		return $att;
	}

	public static function get( $id ) {
		$post = get_post( (int) $id );
		if ( ! $post || self::CPT !== $post->post_type ) {
			return null;
		}
		$m = function ( $k ) use ( $post ) {
			return get_post_meta( $post->ID, '_pct_' . $k, true );
		};
		$image_id = (int) $m( 'image_id' );
		$photo_id = (int) $m( 'photo_id' );
		return array(
			'id'        => $post->ID,
			'title'     => $post->post_title,
			'status'    => (string) $m( 'status' ) ?: 'pending',
			'mobile'    => (string) $m( 'mobile' ),
			'name'      => (string) $m( 'name' ),
			'email'     => (string) $m( 'email' ),
			'city'      => (string) $m( 'city' ),
			'vibe'      => (string) $m( 'vibe' ),
			'style'     => (string) $m( 'style' ),
			'subject'   => (string) $m( 'subject' ),
			'notes'     => (string) $m( 'notes' ),
			'lang'      => (string) $m( 'lang' ),
			'token'     => (string) $m( 'token' ),
			'created'   => (string) $m( 'created' ),
			'error'     => (string) $m( 'error' ),
			'prompt'    => (string) $m( 'prompt' ),
			'photo_id'  => $photo_id,
			'photo_url' => $photo_id ? wp_get_attachment_url( $photo_id ) : '',
			'image_id'  => $image_id,
			'image_url' => $image_id ? wp_get_attachment_url( $image_id ) : '',
			'delivered' => (string) $m( 'delivered' ),
		);
	}

	public static function all( $limit = 100 ) {
		$ids = get_posts( array( 'post_type' => self::CPT, 'post_status' => 'private', 'numberposts' => $limit, 'orderby' => 'date', 'order' => 'DESC', 'fields' => 'ids' ) );
		return array_filter( array_map( array( __CLASS__, 'get' ), $ids ) );
	}

	/**
	 * Public view of a request for its owner (mobile verified or request token).
	 */
	public static function public_view( array $r ) {
		$ready = in_array( $r['status'], array( 'done', 'delivered' ), true );
		return array(
			'id'        => $r['id'],
			'status'    => $r['status'],
			'label'     => self::statuses()[ $r['status'] ] ?? $r['status'],
			'image_url' => $ready ? $r['image_url'] : '',
			'error'     => 'failed' === $r['status'] ? __( 'Image generation failed. The administrator has been notified.', 'personalized-city-tour' ) : '',
			'vibe'      => $r['vibe'],
			'style'     => $r['style'],
		);
	}

	public static function prompt( array $r ) {
		$tpl = (string) self::settings()['prompt_template'];
		return trim( str_replace(
			array( '{style}', '{subject}', '{city}', '{vibe}', '{notes}', '{name}' ),
			array( $r['style'], $r['subject'], $r['city'] ? $r['city'] : 'the city', $r['vibe'], $r['notes'] ? $r['notes'] : '-', $r['name'] ),
			$tpl
		) );
	}

	public static function approve( $id ) {
		update_post_meta( $id, '_pct_status', 'approved' );
		update_post_meta( $id, '_pct_approved_by', get_current_user_id() );
		if ( ! wp_next_scheduled( self::CRON, array( (int) $id ) ) ) {
			wp_schedule_single_event( time() + 5, self::CRON, array( (int) $id ) );
		}
	}

	public static function reject( $id, $reason = '' ) {
		$r = self::get( $id );
		update_post_meta( $id, '_pct_status', 'rejected' );
		update_post_meta( $id, '_pct_error', sanitize_text_field( $reason ) );
		if ( $r ) {
			PCT_SMS::send(
				$r['mobile'],
				/* translators: %s: site name */
				sprintf( __( 'Your "My Trip, My Vibe" request at %s could not be accepted.', 'personalized-city-tour' ), wp_specialchars_decode( get_bloginfo( 'name' ) ) ) . ( $reason ? ' ' . $reason : '' ),
				$r['email'],
				'vibe_rejected:' . $id
			);
		}
	}

	/**
	 * Cron callback: generate the image for an approved request.
	 */
	public static function generate( $id ) {
		$r = self::get( $id );
		if ( ! $r || ! in_array( $r['status'], array( 'approved', 'generating' ), true ) ) {
			return $r;
		}
		update_post_meta( $id, '_pct_status', 'generating' );
		$prompt = self::prompt( $r );
		update_post_meta( $id, '_pct_prompt', $prompt );

		$bin = self::request_image( $prompt, $r );
		if ( is_wp_error( $bin ) ) {
			update_post_meta( $id, '_pct_status', 'failed' );
			update_post_meta( $id, '_pct_error', $bin->get_error_message() );
			PCT_AI_Log::log( array( 'kind' => 'image', 'provider' => PCT_Settings::get( 'image_provider' ), 'status' => 'error', 'title' => $r['title'], 'error' => $bin->get_error_message(), 'request' => $prompt ) );
			return self::get( $id );
		}
		$att = self::save_image( $bin['data'], $bin['ext'], $id );
		if ( is_wp_error( $att ) ) {
			update_post_meta( $id, '_pct_status', 'failed' );
			update_post_meta( $id, '_pct_error', $att->get_error_message() );
			return self::get( $id );
		}
		update_post_meta( $id, '_pct_image_id', $att );
		update_post_meta( $id, '_pct_status', 'done' );
		PCT_AI_Log::log( array( 'kind' => 'image', 'provider' => $bin['provider'], 'status' => 'ok', 'title' => $r['title'], 'request' => $prompt, 'response' => wp_get_attachment_url( $att ) ) );
		self::deliver( $id );
		return self::get( $id );
	}

	/**
	 * @return array|WP_Error { data, ext, provider }
	 */
	private static function request_image( $prompt, array $r ) {
		$provider = (string) PCT_Settings::get( 'image_provider', 'mock' );
		$s        = self::settings();
		if ( 'mock' === $provider || $s['sandbox'] ) {
			return array( 'data' => self::mock_image( $r ), 'ext' => 'svg', 'provider' => 'sandbox' );
		}
		$key = PCT_Settings::secret( 'image_api_key' );
		if ( '' === $key ) {
			$key = PCT_Settings::ai_key();
		}
		$endpoint = 'openai' === $provider ? 'https://api.openai.com/v1/images/generations' : (string) PCT_Settings::get( 'image_endpoint' );
		if ( '' === $endpoint ) {
			return new WP_Error( 'pct_image_config', __( 'Image endpoint is not configured.', 'personalized-city-tour' ) );
		}
		$res = wp_remote_post(
			$endpoint,
			array(
				'timeout' => 180,
				'headers' => array( 'Content-Type' => 'application/json', 'Authorization' => 'Bearer ' . $key ),
				'body'    => wp_json_encode( array( 'model' => PCT_Settings::get( 'image_model' ), 'prompt' => $prompt, 'n' => 1, 'size' => $s['image_size'] ) ),
			)
		);
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$code = wp_remote_retrieve_response_code( $res );
		$body = json_decode( wp_remote_retrieve_body( $res ), true );
		if ( $code < 200 || $code >= 300 || ! is_array( $body ) ) {
			/* translators: %s: HTTP status */
			return new WP_Error( 'pct_image_http', sprintf( __( 'Image provider returned HTTP %s.', 'personalized-city-tour' ), $code ) . ( isset( $body['error']['message'] ) ? ' ' . $body['error']['message'] : '' ) );
		}
		$item = $body['data'][0] ?? array();
		if ( ! empty( $item['b64_json'] ) ) {
			$bin = base64_decode( $item['b64_json'] ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
			return $bin ? array( 'data' => $bin, 'ext' => 'png', 'provider' => $provider ) : new WP_Error( 'pct_image_decode', __( 'Could not decode the generated image.', 'personalized-city-tour' ) );
		}
		if ( ! empty( $item['url'] ) ) {
			$img = wp_remote_get( esc_url_raw( $item['url'] ), array( 'timeout' => 60 ) );
			if ( is_wp_error( $img ) ) {
				return $img;
			}
			return array( 'data' => wp_remote_retrieve_body( $img ), 'ext' => 'png', 'provider' => $provider );
		}
		return new WP_Error( 'pct_image_empty', __( 'The image provider returned no image.', 'personalized-city-tour' ) );
	}

	/**
	 * Sandbox artwork: a simple SVG with the chosen mood so the whole flow can be tested offline.
	 */
	public static function mock_image( array $r ) {
		$palette = array( '#c9d6ff', '#e2e2e2', '#f6d365', '#fda085', '#a1c4fd', '#c2e9fb', '#d4fc79', '#96e6a1' );
		$seed    = crc32( $r['vibe'] . $r['style'] );
		$a       = $palette[ $seed % count( $palette ) ];
		$b       = $palette[ ( $seed >> 3 ) % count( $palette ) ];
		$esc     = function ( $s ) {
			return htmlspecialchars( (string) $s, ENT_XML1 | ENT_QUOTES, 'UTF-8' );
		};
		$circles = '';
		for ( $i = 0; $i < 12; $i++ ) {
			$x  = ( ( $seed * ( $i + 3 ) ) % 900 ) + 60;
			$y  = ( ( $seed * ( $i + 7 ) ) % 700 ) + 60;
			$rr = ( ( $seed * ( $i + 11 ) ) % 90 ) + 30;
			$circles .= '<circle cx="' . $x . '" cy="' . $y . '" r="' . $rr . '" fill="#fff" fill-opacity="0.25"/>';
		}
		return '<?xml version="1.0" encoding="UTF-8"?><svg xmlns="http://www.w3.org/2000/svg" width="1024" height="1024" viewBox="0 0 1024 1024">'
			. '<defs><linearGradient id="g" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="' . $a . '"/><stop offset="1" stop-color="' . $b . '"/></linearGradient></defs>'
			. '<rect width="1024" height="1024" fill="url(#g)"/>' . $circles
			. '<text x="512" y="470" text-anchor="middle" font-family="Georgia,serif" font-size="56" fill="#333">' . $esc( $r['city'] ? $r['city'] : 'My Trip' ) . '</text>'
			. '<text x="512" y="540" text-anchor="middle" font-family="Helvetica,Arial,sans-serif" font-size="30" fill="#444">' . $esc( $r['vibe'] . ' · ' . $r['style'] ) . '</text>'
			. '<text x="512" y="590" text-anchor="middle" font-family="Helvetica,Arial,sans-serif" font-size="22" fill="#555">' . $esc( $r['subject'] ) . '</text>'
			. '<text x="512" y="980" text-anchor="middle" font-family="Helvetica,Arial,sans-serif" font-size="18" fill="#666">sandbox preview</text>'
			. '</svg>';
	}

	private static function save_image( $data, $ext, $post_id ) {
		if ( ! function_exists( 'wp_upload_bits' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		$name = 'my-trip-my-vibe-' . $post_id . '-' . wp_generate_password( 6, false, false ) . '.' . $ext;
		$up   = wp_upload_bits( $name, null, $data );
		if ( ! empty( $up['error'] ) ) {
			return new WP_Error( 'pct_save_image', $up['error'] );
		}
		$att = wp_insert_attachment(
			array(
				'post_mime_type' => 'svg' === $ext ? 'image/svg+xml' : 'image/png',
				'post_title'     => 'My Trip, My Vibe #' . $post_id,
				'post_status'    => 'inherit',
				'post_parent'    => $post_id,
			),
			$up['file'],
			$post_id
		);
		if ( is_wp_error( $att ) ) {
			return $att;
		}
		if ( 'svg' !== $ext ) {
			require_once ABSPATH . 'wp-admin/includes/image.php';
			wp_update_attachment_metadata( $att, wp_generate_attachment_metadata( $att, $up['file'] ) );
		}
		return $att;
	}

	public static function result_url( array $r ) {
		return add_query_arg( array( 'pct_vibe' => $r['id'], 'k' => $r['token'] ), home_url( '/' ) );
	}

	public static function deliver( $id ) {
		$r = self::get( $id );
		if ( ! $r || 'done' !== $r['status'] ) {
			return false;
		}
		$mode = self::settings()['delivery'];
		$site = wp_specialchars_decode( get_bloginfo( 'name' ) );
		/* translators: 1: site name, 2: url */
		$msg  = sprintf( __( 'Your "My Trip, My Vibe" illustration from %1$s is ready: %2$s', 'personalized-city-tour' ), $site, self::result_url( $r ) );
		$sent = false;
		if ( 'sms' === $mode ) {
			$sent = PCT_SMS::send( $r['mobile'], $msg, $r['email'], 'vibe_ready:' . $id );
		} elseif ( 'email' === $mode && $r['email'] ) {
			$sent = wp_mail( $r['email'], $site, $msg );
		}
		if ( true === $sent ) {
			update_post_meta( $id, '_pct_status', 'delivered' );
			update_post_meta( $id, '_pct_delivered', current_time( 'mysql' ) . ' (' . $mode . ')' );
		}
		return $sent;
	}

	public static function mark_delivered( $id ) {
		update_post_meta( $id, '_pct_status', 'delivered' );
		update_post_meta( $id, '_pct_delivered', current_time( 'mysql' ) . ' (manual)' );
	}

	public static function handle_action() {
		check_admin_referer( 'pct_vibe_action' );
		if ( ! current_user_can( 'pct_manage' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'personalized-city-tour' ) );
		}
		$id     = absint( $_POST['request_id'] ?? 0 );
		$action = sanitize_key( $_POST['vibe_action'] ?? '' );
		$msg    = 'error';
		if ( $id && self::get( $id ) ) {
			switch ( $action ) {
				case 'approve':
					self::approve( $id );
					if ( ! empty( $_POST['generate_now'] ) ) {
						self::generate( $id );
					}
					$msg = 'approved';
					break;
				case 'generate':
					update_post_meta( $id, '_pct_status', 'approved' );
					self::generate( $id );
					$msg = 'generated';
					break;
				case 'reject':
					self::reject( $id, sanitize_text_field( wp_unslash( $_POST['reason'] ?? '' ) ) );
					$msg = 'rejected';
					break;
				case 'deliver':
					self::deliver( $id );
					$msg = 'delivered';
					break;
				case 'mark_delivered':
					self::mark_delivered( $id );
					$msg = 'delivered';
					break;
				case 'delete':
					$r = self::get( $id );
					foreach ( array( 'photo_id', 'image_id' ) as $k ) {
						if ( ! empty( $r[ $k ] ) ) {
							wp_delete_attachment( (int) $r[ $k ], true );
						}
					}
					wp_delete_post( $id, true );
					$msg = 'deleted';
					break;
			}
		}
		wp_safe_redirect( add_query_arg( array( 'page' => 'pct-vibe', 'pct_msg' => $msg ), admin_url( 'admin.php' ) ) );
		exit;
	}
}
