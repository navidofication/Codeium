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
		// Migrate the old image-model value shown by pre-2.1 settings to a real GPT Image model.
		if ( 'openai' === (string) PCT_Settings::get( 'image_provider', '' ) ) {
			$current_image_model = (string) PCT_Settings::get( 'image_model', '' );
			if ( in_array( $current_image_model, array( 'gpt-5.6-luna', 'gpt-5.6-terra', 'gpt-5.4-mini', 'gpt-image-2.5-flare' ), true ) ) {
				// Sunburst is the higher-precision GPT Image 2.5 model and is a
				// better default for this reference-photo identity-preservation workflow.
				PCT_Settings::set( 'image_model', 'gpt-image-2.5-sunburst' );
			}
		}
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
			'vibes'           => "Calm & nostalgic
Energetic & colorful
Romantic
Adventurous
Family fun
Artistic & dreamy",
			'styles'          => "Watercolor
Line art
Comic / pop-art
Oil painting
Minimal flat illustration
Persian miniature",
			'subjects'        => "Me in front of a landmark
My travel group
The city skyline
A street scene
Local food moment",
			'prompt_template' => "EDIT the uploaded traveller photo into a {style} illustration of {subject} in {city}. Mood: {vibe}. Notes from the traveller: {notes}. The uploaded photo is the PRIMARY AND AUTHORITATIVE VISUAL REFERENCE; the generated result must be an edit of that exact photo, not a newly invented group. Preserve EXACTLY the same people, the same number of people, their identities, faces, facial features, hairstyles, skin tones, body proportions, relative positions, pose and expressions. Do NOT add, remove, duplicate, merge or replace any person. Change only the requested artistic style and the requested scene/background treatment; keep the people recognisably the same. If the source contains two people, the output must contain exactly two people; if it contains one, output exactly one; and so on. Keep the original group composition and framing as much as possible. No extra people, crowds, background people, statues that look like people, text, logos or watermarks.", 
			'require_approval' => 1,
			'subject_visible' => 1,
			'requests_per_page' => 20,
			'outbox_per_page' => 20,
			'delivery'        => 'sms',
			'otp_ttl'         => 10,
			'max_upload_mb'   => 8,
			'image_size'      => '1024x1024',
			'sandbox'         => 0,
		);
	}

	public static function settings() {
		return wp_parse_args( (array) get_option( self::OPTION, array() ), self::defaults() );
	}

	public static function options_list( $key ) {
		$value = self::settings()[ $key ] ?? '';
		$lines = preg_split( '/\r\n|\r|\n/', (string) $value );
		return array_values( array_filter( array_map( 'trim', $lines ) ) );
	}

	/** Return stable values plus translated display labels for the frontend. */
	public static function localized_options( $key, $lang = null ) {
		$lang = $lang ? PCT_I18n::normalize( $lang ) : PCT_I18n::current();
		$out = array();
		foreach ( self::options_list( $key ) as $value ) {
			$out[] = array( 'value' => $value, 'label' => PCT_I18n::translate_ui_text( $value, $lang ) );
		}
		return $out;
	}

	public static function enabled() {
		return (bool) self::settings()['enabled'];
	}

	/**
	 * Approval is a live runtime switch. Keep this in one place so a request
	 * can never accidentally fall back to the historical default after the
	 * administrator has disabled approval.
	 */
	public static function requires_approval() {
		$s = self::settings();
		return ! empty( $s['require_approval'] );
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
			'subject_visible' => empty( $in['subject_visible'] ) ? 0 : 1,
			'requests_per_page' => max( 5, min( 200, absint( $in['requests_per_page'] ?? 20 ) ) ),
			'outbox_per_page' => max( 5, min( 200, absint( $in['outbox_per_page'] ?? 20 ) ) ),
			'delivery'         => in_array( $in['delivery'] ?? '', array( 'sms', 'email', 'manual' ), true ) ? $in['delivery'] : 'sms',
			'otp_ttl'          => max( 2, min( 60, absint( $in['otp_ttl'] ?? 10 ) ) ),
			'max_upload_mb'    => max( 1, min( 50, absint( $in['max_upload_mb'] ?? 8 ) ) ),
			'image_size'       => in_array( $in['image_size'] ?? '', array( '1024x1024', '1024x1536', '1536x1024', '512x512' ), true ) ? $in['image_size'] : '1024x1024',
			'sandbox'          => empty( $in['sandbox'] ) ? 0 : 1,
		);
		update_option( self::OPTION, $out, false );

		// If approval is switched OFF, do not leave requests created under the
		// previous approval mode stuck in "pending". Promote all pending requests
		// immediately so the setting takes effect for existing and new requests.
		if ( empty( $out['require_approval'] ) ) {
			$pending_ids = get_posts( array(
				'post_type'      => self::CPT,
				'post_status'    => 'private',
				'posts_per_page' => 200,
				'fields'         => 'ids',
				'meta_query'     => array( array( 'key' => '_pct_status', 'value' => 'pending' ) ),
			) );
			foreach ( $pending_ids as $pending_id ) {
				self::approve( (int) $pending_id );
			}
		}

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
		// Notify subscribed administrator devices only after the OTP request has
		// been accepted by the configured delivery path (SMS, e-mail or manual
		// outbox). This keeps the browser alert tied to a real OTP request.
		PCT_Push::notify_otp_request();
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

	private static function visitor_token_key( $token ) {
		return 'pct_vtok_' . wp_hash( (string) $token );
	}

	private static function visitor_token_used_key( $token ) {
		return 'pct_vtok_used_' . wp_hash( (string) $token );
	}

	public static function visitor_mobile( $token ) {
		$token = (string) $token;
		if ( strlen( $token ) < 20 ) {
			return '';
		}
		$value = get_transient( self::visitor_token_key( $token ) );
		if ( is_array( $value ) ) {
			return (string) ( $value['mobile'] ?? '' );
		}
		// Backward compatibility for tokens created by pre-2.2.1 builds.
		return is_string( $value ) ? $value : '';
	}

	/** Return whether this verified OTP token is still authorised to create a new image request. */
	public static function visitor_token_can_create( $token ) {
		if ( '' === self::visitor_mobile( $token ) ) {
			return false;
		}
		return ! (bool) get_transient( self::visitor_token_used_key( $token ) );
	}

	/** Mark the verified OTP token as consumed after one request is successfully created. */
	public static function consume_visitor_token( $token ) {
		if ( '' === self::visitor_mobile( $token ) ) {
			return false;
		}
		set_transient( self::visitor_token_used_key( $token ), 1, DAY_IN_SECONDS );
		return true;
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
	public static function has_active_request( $mobile ) {
		$mobile = PCT_SMS::normalize_mobile( $mobile );
		if ( '' === $mobile ) { return false; }
		$ids = get_posts( array( 'post_type' => self::CPT, 'post_status' => 'private', 'numberposts' => 1, 'fields' => 'ids', 'meta_query' => array( 'relation' => 'AND', array( 'key' => '_pct_mobile', 'value' => $mobile ), array( 'key' => '_pct_status', 'value' => array( 'pending', 'approved', 'generating' ), 'compare' => 'IN' ) ) ) );
		return ! empty( $ids );
	}

	private static function pending_request_id( $mobile ) {
		$mobile = PCT_SMS::normalize_mobile( $mobile );
		if ( '' === $mobile ) { return 0; }
		$ids = get_posts( array( 'post_type' => self::CPT, 'post_status' => 'private', 'numberposts' => 1, 'fields' => 'ids', 'meta_query' => array( 'relation' => 'AND', array( 'key' => '_pct_mobile', 'value' => $mobile ), array( 'key' => '_pct_status', 'value' => 'pending' ) ) ) );
		return ! empty( $ids ) ? (int) $ids[0] : 0;
	}

	public static function create( array $data, array $file = array() ) {
		if ( ! self::enabled() ) {
			return new WP_Error( 'pct_disabled', __( 'This service is not available right now.', 'personalized-city-tour' ), array( 'status' => 403 ) );
		}
		$s      = self::settings();
		$mobile = PCT_SMS::normalize_mobile( $data['mobile'] ?? '' );
		if ( self::has_active_request( $mobile ) ) {
			// If approval has just been disabled, an older pending request from
			// the previous approval mode should not remain stuck forever. Promote
			// that pending request automatically; approved/generating requests are
			// still treated as active duplicates.
			if ( ! self::requires_approval() ) {
				$pending_id = self::pending_request_id( $mobile );
				if ( $pending_id ) {
					self::approve( $pending_id, false );
					return self::get( $pending_id );
				}
			}
			return new WP_Error( 'pct_vibe_active', __( 'An image request for this verified device is already being processed.', 'personalized-city-tour' ), array( 'status' => 409 ) );
		}
		$pick   = function ( $key, $list ) use ( $data ) {
			$v = sanitize_text_field( $data[ $key ] ?? '' );
			return in_array( $v, $list, true ) ? $v : ( $list ? $list[0] : '' );
		};
		$vibe    = $pick( 'vibe', self::options_list( 'vibes' ) );
		$style   = $pick( 'style', self::options_list( 'styles' ) );
		$subject = ! empty( $s['subject_visible'] ) ? $pick( 'subject', self::options_list( 'subjects' ) ) : '';
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

		if ( ! self::requires_approval() ) {
			self::approve( $post_id, false );
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
		$s = self::settings();
		$city  = $r['city'] ? $r['city'] : 'the city';
		$style = $r['style'];
		$vibe  = $r['vibe'];
		$notes = $r['notes'] ? $r['notes'] : '-';

		/*
		 * A reference-photo request must never be described as "Me", "a woman",
		 * "a man", etc. Those subject labels are useful for text-to-image, but
		 * they can conflict with the actual number of people in the uploaded
		 * photograph. The photograph itself is the source of truth.
		 */
		if ( ! empty( $r['photo_id'] ) ) {
			// Keep the administrator's template in the actual edit prompt. The
			// template can improve style/face results, while the guard below makes
			// the uploaded photograph authoritative for people and composition.
			$tpl = (string) $s['prompt_template'];
			$base = trim( str_replace(
				array( '{style}', '{subject}', '{city}', '{vibe}', '{notes}', '{name}' ),
				array( $style, $r['subject'], $city, $vibe, $notes, $r['name'] ),
				$tpl
			) );
			$guard = 'IMPORTANT PHOTO-EDIT CONTRACT: Edit the uploaded photograph itself. The uploaded photo is the authoritative source for composition, people and proportions. Preserve the exact same number of photographed people, their identities, facial geometry, eyes, nose, mouth, jawline, hairstyle, skin tone, apparent age, body size/proportions, clothing, relative positions, pose, gaze and expressions. Preserve the original camera framing, crop, perspective and subject placement as closely as possible. Do not recreate the people from the text description. Do not add, remove, merge, duplicate, replace or redesign people. Do not turn background people, statues or objects into new main subjects. Change the requested artistic medium, lighting, palette and destination environment around the existing photographed people. If the destination requires a different background, extend or replace only the environment around the existing subjects while keeping their silhouettes and placement stable. The selected subject text is descriptive only and must never override the uploaded photo. No text, logos or watermarks.';
			return trim( $guard . ' EDIT INSTRUCTIONS: ' . $base );
		}

		$tpl = (string) $s['prompt_template'];
		$prompt = trim( str_replace(
			array( '{style}', '{subject}', '{city}', '{vibe}', '{notes}', '{name}' ),
			array( $style, $r['subject'], $city, $vibe, $notes, $r['name'] ),
			$tpl
		) );
		return trim( $prompt );
	}

	public static function approve( $id, $kick_cron = true ) {
		update_post_meta( $id, '_pct_status', 'approved' );
		update_post_meta( $id, '_pct_approved_by', get_current_user_id() );
		if ( ! wp_next_scheduled( self::CRON, array( (int) $id ) ) ) {
			wp_schedule_single_event( time() + 3, self::CRON, array( (int) $id ) );
		}
		// Manual administrator approval can still kick cron immediately.
		// Automatic visitor approval deliberately skips the loopback kick because
		// some LiteSpeed/hosting configurations can turn that best-effort request
		// into a visitor REST failure. The scheduled event remains authoritative.
		if ( $kick_cron && function_exists( 'spawn_cron' ) ) {
			spawn_cron( time() );
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
	 *
	 * Primary/fallback chain:
	 *   configured provider -> Pollinations -> Hugging Face -> Sandbox.
	 * The chain is deliberately server-side and never exposes API keys.
	 */
	private static function request_image( $prompt, array $r ) {
		$primary = (string) PCT_Settings::get( 'image_provider', 'pollinations' );
		$s       = self::settings();
		if ( 'mock' === $primary || $s['sandbox'] ) {
			return array( 'data' => self::mock_image( $r ), 'ext' => 'svg', 'provider' => 'sandbox' );
		}

		// A reference-photo request is an image-edit operation, not a text-only
		// generation. Pollinations, Hugging Face, Gemini and Higgsfield are not
		// given the uploaded file by this plugin, so allowing them in the fallback
		// chain would silently generate a different composition. OpenAI's Images
		// edit endpoint is the supported reference-photo path.
		if ( ! empty( $r['photo_id'] ) ) {
			if ( '' === self::image_key_for_provider( 'openai' ) ) {
				return new WP_Error( 'pct_image_reference_requires_openai', __( 'This request includes a reference photo. Configure an OpenAI Images API key so the uploaded photo can be sent to the image edit endpoint; text-only providers are not used for reference-photo requests.', 'personalized-city-tour' ) );
			}
			$providers = array( 'openai' );
		} else {
			$providers = array( $primary );
			if ( (int) PCT_Settings::get( 'image_fallback_enabled', 1 ) ) {
				$providers = array_merge( $providers, array( 'pollinations', 'huggingface', 'mock' ) );
			}
		}
		$providers = array_values( array_unique( $providers ) );
		$errors    = array();

		foreach ( $providers as $provider ) {
			if ( 'mock' === $provider ) {
				return array( 'data' => self::mock_image( $r ), 'ext' => 'svg', 'provider' => 'sandbox' );
			}
			$result = self::request_image_provider( $provider, $prompt, $r );
			if ( ! is_wp_error( $result ) ) {
				return $result;
			}
			$errors[] = ucfirst( $provider ) . ': ' . $result->get_error_message();
		}

		return new WP_Error( 'pct_image_all_failed', __( 'All configured image providers failed.', 'personalized-city-tour' ) . ' ' . implode( ' | ', $errors ) );
	}

	/** @return array|WP_Error */
	private static function request_image_provider( $provider, $prompt, array $r ) {
		$s = self::settings();
		if ( 'pollinations' === $provider ) {
			$key = self::image_key();
			if ( '' === $key ) {
				return new WP_Error( 'pct_image_pollinations_key', __( 'Pollinations API key is not configured.', 'personalized-city-tour' ) );
			}
			$endpoint = 'https://gen.pollinations.ai/v1/images/generations';
			$model = PCT_Settings::get( 'image_model', 'flux' );
			$payload = array( 'model' => $model ?: 'flux', 'prompt' => $prompt, 'n' => 1, 'size' => $s['image_size'] );
			$res = PCT_AI::post_with_retry( $endpoint, array(
				'timeout' => 180,
				'headers' => array( 'Content-Type' => 'application/json', 'Authorization' => 'Bearer ' . $key ),
				'body' => wp_json_encode( $payload ),
			) );
			return self::parse_openai_image_response( $res, 'pollinations' );
		}

		if ( 'gemini' === $provider ) {
			$key = PCT_Settings::secret( 'image_gemini_api_key' );
			if ( '' === $key ) { return new WP_Error( 'pct_image_gemini_key', __( 'Gemini API key is not configured.', 'personalized-city-tour' ) ); }
			$model = PCT_Settings::get( 'image_gemini_model', 'gemini-3.1-flash-image' );
			$endpoint = PCT_Settings::get( 'image_endpoint', '' ) ?: 'https://generativelanguage.googleapis.com/v1beta/interactions';
			$fmt = self::gemini_image_format( $s['image_size'] );
			$payload = array( 'model' => $model, 'input' => $prompt, 'response_format' => array( 'type' => 'image', 'mime_type' => 'image/png', 'aspect_ratio' => $fmt['aspect_ratio'], 'image_size' => $fmt['image_size'] ) );
			$res = wp_remote_post( $endpoint, array( 'timeout' => 180, 'headers' => array( 'Content-Type' => 'application/json', 'x-goog-api-key' => $key ), 'body' => wp_json_encode( $payload ) ) );
			if ( is_wp_error( $res ) ) { return $res; }
			$code = (int) wp_remote_retrieve_response_code( $res );
			$data = json_decode( wp_remote_retrieve_body( $res ), true );
			if ( $code < 200 || $code >= 300 ) {
				$msg = is_array( $data ) ? ( $data['error']['message'] ?? $data['message'] ?? '' ) : '';
				return new WP_Error( 'pct_image_gemini_http', sprintf( __( 'Gemini returned HTTP %d.', 'personalized-city-tour' ), $code ) . ( $msg ? ' ' . sanitize_text_field( $msg ) : '' ) );
			}
			$b64 = self::find_base64_image( $data );
			if ( ! $b64 ) { return new WP_Error( 'pct_image_gemini_empty', __( 'Gemini returned no image data.', 'personalized-city-tour' ) ); }
			$bytes = base64_decode( $b64, true );
			if ( false === $bytes ) { return new WP_Error( 'pct_image_gemini_decode', __( 'Gemini returned invalid image data.', 'personalized-city-tour' ) ); }
			return array( 'data' => $bytes, 'ext' => 'png', 'provider' => 'gemini' );
		}

		if ( 'higgsfield' === $provider ) {
			$key_id = PCT_Settings::secret( 'image_higgsfield_key_id' );
			$key_secret = PCT_Settings::secret( 'image_higgsfield_key_secret' );
			if ( '' === $key_id || '' === $key_secret ) { return new WP_Error( 'pct_image_higgsfield_key', __( 'Higgsfield API key ID and secret are required.', 'personalized-city-tour' ) ); }
			$model = trim( (string) PCT_Settings::get( 'image_higgsfield_model', 'z-image/turbo' ) ) ?: 'z-image/turbo';
			$endpoint = 'https://api.higgsfield.ai/' . ltrim( $model, '/' );
			$fmt = self::higgsfield_image_format( $s['image_size'] );
			$payload = array( 'prompt' => $prompt, 'resolution' => $fmt['resolution'], 'aspect_ratio' => $fmt['aspect_ratio'], 'prompt_extend' => false );
			$res = wp_remote_post( $endpoint, array( 'timeout' => 180, 'headers' => array( 'Content-Type' => 'application/json', 'Authorization' => 'Key ' . $key_id . ':' . $key_secret ), 'body' => wp_json_encode( $payload ) ) );
			if ( is_wp_error( $res ) ) { return $res; }
			$code = (int) wp_remote_retrieve_response_code( $res );
			$data = json_decode( wp_remote_retrieve_body( $res ), true );
			if ( $code < 200 || $code >= 300 ) {
				$msg = is_array( $data ) ? ( $data['error']['message'] ?? $data['message'] ?? $data['detail'] ?? '' ) : '';
				return new WP_Error( 'pct_image_higgsfield_http', sprintf( __( 'Higgsfield returned HTTP %d.', 'personalized-city-tour' ), $code ) . ( $msg ? ' ' . sanitize_text_field( is_string( $msg ) ? $msg : wp_json_encode( $msg ) ) : '' ) );
			}
			$status_url = is_array( $data ) ? (string) ( $data['status_url'] ?? '' ) : '';
			$request_id = is_array( $data ) ? (string) ( $data['request_id'] ?? $data['id'] ?? '' ) : '';
			if ( '' === $status_url && $request_id ) { $status_url = 'https://api.higgsfield.ai/requests/' . rawurlencode( $request_id ) . '/status'; }
			if ( '' === $status_url ) { return new WP_Error( 'pct_image_higgsfield_queue', __( 'Higgsfield did not return a status URL.', 'personalized-city-tour' ) ); }
			$deadline = time() + 170;
			while ( time() < $deadline ) {
				sleep( 3 );
				$status = wp_remote_get( $status_url, array( 'timeout' => 30, 'headers' => array( 'Authorization' => 'Key ' . $key_id . ':' . $key_secret ) ) );
				if ( is_wp_error( $status ) ) { return $status; }
				$sc = (int) wp_remote_retrieve_response_code( $status );
				$sd = json_decode( wp_remote_retrieve_body( $status ), true );
				if ( $sc < 200 || $sc >= 300 ) { return new WP_Error( 'pct_image_higgsfield_status', sprintf( __( 'Higgsfield status returned HTTP %d.', 'personalized-city-tour' ), $sc ) ); }
				$state = strtolower( (string) ( $sd['status'] ?? $sd['state'] ?? '' ) );
				if ( in_array( $state, array( 'failed', 'error', 'cancelled', 'canceled' ), true ) ) { return new WP_Error( 'pct_image_higgsfield_failed', __( 'Higgsfield image generation failed.', 'personalized-city-tour' ) ); }
				$images = (array) ( $sd['images'] ?? array() );
				if ( ! empty( $images ) ) {
					$url = is_array( $images[0] ) ? ( $images[0]['url'] ?? $images[0]['uri'] ?? '' ) : $images[0];
					if ( $url ) { return self::download_remote_image( $url, 'higgsfield' ); }
				}
			}
			return new WP_Error( 'pct_image_higgsfield_timeout', __( 'Higgsfield image generation timed out.', 'personalized-city-tour' ) );
		}

		if ( 'huggingface' === $provider ) {
			$key = PCT_Settings::secret( 'image_hf_api_key' );
			if ( '' === $key ) {
				return new WP_Error( 'pct_image_hf_key', __( 'Hugging Face API key is not configured.', 'personalized-city-tour' ) );
			}
			$model = PCT_Settings::get( 'image_hf_model', 'black-forest-labs/FLUX.1-schnell' );
			$endpoint = 'https://router.huggingface.co/hf-inference/models/' . implode( '/', array_map( 'rawurlencode', explode( '/', $model ) ) );
			$res = wp_remote_post( $endpoint, array(
				'timeout' => 180,
				'headers' => array( 'Content-Type' => 'application/json', 'Authorization' => 'Bearer ' . $key, 'Accept' => 'image/png, image/jpeg, image/*' ),
				'body' => wp_json_encode( array( 'inputs' => $prompt, 'parameters' => self::hf_parameters( $s['image_size'] ) ) ),
			) );
			if ( is_wp_error( $res ) ) {
				return $res;
			}
			$code = (int) wp_remote_retrieve_response_code( $res );
			$body = wp_remote_retrieve_body( $res );
			if ( $code < 200 || $code >= 300 ) {
				$detail = json_decode( $body, true );
				$message = is_array( $detail ) ? ( $detail['error'] ?? $detail['message'] ?? '' ) : '';
				return new WP_Error( 'pct_image_hf_http', sprintf( __( 'Hugging Face returned HTTP %d.', 'personalized-city-tour' ), $code ) . ( $message ? ' ' . sanitize_text_field( is_string( $message ) ? $message : wp_json_encode( $message ) ) : '' ) );
			}
			if ( '' === $body ) {
				return new WP_Error( 'pct_image_hf_empty', __( 'Hugging Face returned an empty image.', 'personalized-city-tour' ) );
			}
			$type = wp_remote_retrieve_header( $res, 'content-type' );
			$ext = ( false !== strpos( (string) $type, 'jpeg' ) || false !== strpos( (string) $type, 'jpg' ) ) ? 'jpg' : 'png';
			return array( 'data' => $body, 'ext' => $ext, 'provider' => 'huggingface' );
		}

		if ( in_array( $provider, array( 'openai', 'custom' ), true ) ) {
			$key = self::image_key_for_provider( $provider );
			$endpoint = self::image_endpoint();
			if ( '' === $endpoint ) {
				return new WP_Error( 'pct_image_config', __( 'Image endpoint is not configured.', 'personalized-city-tour' ) );
			}
			if ( '' === $key ) {
				return new WP_Error( 'pct_image_config', __( 'Image API key is not configured.', 'personalized-city-tour' ) );
			}
			$model = PCT_Settings::get( 'image_model', 'gpt-image-2.5-sunburst' );
			if ( 'openai' === $provider && 0 !== strpos( strtolower( (string) $model ), 'gpt-image-' ) ) {
				$model = 'gpt-image-2.5-sunburst';
			}
			// An uploaded traveller photo means this is an edit/reference workflow.
			// OpenAI's Image API requires multipart /images/edits for an input image.
			if ( 'openai' === $provider && ! empty( $r['photo_id'] ) ) {
				return self::openai_reference_edit( $model, $key, $prompt, self::reference_image_size( (int) $r['photo_id'], $s['image_size'] ), (int) $r['photo_id'] );
			}
			$res = PCT_AI::post_with_retry( $endpoint, array(
				'timeout' => 180,
				'headers' => array( 'Content-Type' => 'application/json', 'Authorization' => 'Bearer ' . $key ),
				'body' => wp_json_encode( array( 'model' => $model, 'prompt' => $prompt, 'n' => 1, 'size' => $s['image_size'], 'quality' => 'medium' ) ),
			) );
			return self::parse_openai_image_response( $res, $provider );
		}

		return new WP_Error( 'pct_image_provider', __( 'Unknown image provider.', 'personalized-city-tour' ) );
	}

	private static function gemini_image_format( $size ) {
		$map = array( '1024x1024' => array( 'aspect_ratio' => '1:1', 'image_size' => '1K' ), '1024x1536' => array( 'aspect_ratio' => '2:3', 'image_size' => '1K' ), '1536x1024' => array( 'aspect_ratio' => '3:2', 'image_size' => '1K' ), '512x512' => array( 'aspect_ratio' => '1:1', 'image_size' => '512px' ) );
		return $map[ $size ] ?? $map['1024x1024'];
	}

	private static function higgsfield_image_format( $size ) {
		$map = array( '1024x1024' => array( 'aspect_ratio' => '1:1', 'resolution' => '1k' ), '1024x1536' => array( 'aspect_ratio' => '2:3', 'resolution' => '1k' ), '1536x1024' => array( 'aspect_ratio' => '3:2', 'resolution' => '1k' ), '512x512' => array( 'aspect_ratio' => '1:1', 'resolution' => '1k' ) );
		return $map[ $size ] ?? $map['1024x1024'];
	}

	private static function find_base64_image( $data ) {
		if ( ! is_array( $data ) ) { return ''; }
		if ( ! empty( $data['output_image']['data'] ) ) { return (string) $data['output_image']['data']; }
		foreach ( (array) ( $data['steps'] ?? array() ) as $step ) {
			foreach ( (array) ( $step['content'] ?? array() ) as $block ) {
				if ( ! empty( $block['data'] ) && 'image' === ( $block['type'] ?? '' ) ) { return (string) $block['data']; }
			}
		}
		return '';
	}

	private static function download_remote_image( $url, $provider ) {
		$url = esc_url_raw( (string) $url );
		if ( '' === $url ) { return new WP_Error( 'pct_image_url', __( 'Image provider returned an invalid image URL.', 'personalized-city-tour' ) ); }
		$img = wp_remote_get( $url, array( 'timeout' => 90 ) );
		if ( is_wp_error( $img ) ) { return $img; }
		$code = (int) wp_remote_retrieve_response_code( $img );
		if ( $code < 200 || $code >= 300 ) { return new WP_Error( 'pct_image_download', sprintf( __( 'Generated image URL returned HTTP %d.', 'personalized-city-tour' ), $code ) ); }
		$body = wp_remote_retrieve_body( $img );
		if ( '' === $body ) { return new WP_Error( 'pct_image_empty', __( 'The image provider returned no image.', 'personalized-city-tour' ) ); }
		$type = strtolower( (string) wp_remote_retrieve_header( $img, 'content-type' ) );
		$ext = ( false !== strpos( $type, 'jpeg' ) || false !== strpos( $type, 'jpg' ) ) ? 'jpg' : 'png';
		return array( 'data' => $body, 'ext' => $ext, 'provider' => $provider );
	}

	private static function hf_parameters( $size ) {
		$parts = array_map( 'absint', explode( 'x', (string) $size ) );
		$width = ! empty( $parts[0] ) ? $parts[0] : 1024;
		$height = ! empty( $parts[1] ) ? $parts[1] : 1024;
		return array( 'width' => $width, 'height' => $height );
	}

	/**
	 * Send a reference photo to OpenAI as a real multipart/form-data request.
	 *
	 * WordPress' HTTP abstraction can treat CURLFile values differently across
	 * Requests/cURL versions. Building the multipart body explicitly removes
	 * that ambiguity: the uploaded visitor photo is always part of the edit
	 * request, never silently downgraded to a text-only generation.
	 *
	 * @return array|WP_Error
	 */
	private static function reference_image_size( $photo_id, $fallback = '1024x1024' ) {
		$photo = get_attached_file( (int) $photo_id );
		if ( ! $photo || ! file_exists( $photo ) || ! is_readable( $photo ) ) {
			return $fallback;
		}
		$info = @getimagesize( $photo ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( ! is_array( $info ) || empty( $info[0] ) || empty( $info[1] ) ) {
			return $fallback;
		}
		$w = (int) $info[0];
		$h = (int) $info[1];
		if ( $w < 1 || $h < 1 ) {
			return $fallback;
		}

		// GPT Image 2.5 accepts custom dimensions as multiples of 16. Preserve
		// the source aspect ratio instead of forcing every traveller photo into
		// a square canvas, which can cause unnecessary reframing/cropping.
		$ratio = max( 1 / 3, min( 3, $w / $h ) );
		$max_edge = 1536;
		if ( $w >= $h ) {
			$tw = $max_edge;
			$th = (int) round( $tw / $ratio / 16 ) * 16;
		} else {
			$th = $max_edge;
			$tw = (int) round( $th * $ratio / 16 ) * 16;
		}
		$tw = max( 16, $tw );
		$th = max( 16, $th );

		// Keep within the GPT Image 2.5 documented pixel bounds.
		$pixels = $tw * $th;
		if ( $pixels < 655360 ) {
			$scale = sqrt( 655360 / $pixels );
			$tw = (int) ceil( ( $tw * $scale ) / 16 ) * 16;
			$th = (int) ceil( ( $th * $scale ) / 16 ) * 16;
		}
		while ( $tw * $th > 8294400 ) {
			$tw = max( 16, $tw - 16 );
			$th = max( 16, $th - 16 );
		}
		return $tw . 'x' . $th;
	}

	private static function openai_reference_edit( $model, $key, $prompt, $size, $photo_id ) {
		$photo = get_attached_file( $photo_id );
		if ( ! $photo || ! file_exists( $photo ) || ! is_readable( $photo ) ) {
			return new WP_Error( 'pct_image_photo_missing', __( 'The uploaded reference photo could not be found or read on the server.', 'personalized-city-tour' ) );
		}

		$data = file_get_contents( $photo ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( false === $data || '' === $data ) {
			return new WP_Error( 'pct_image_photo_empty', __( 'The uploaded reference photo is empty or could not be read.', 'personalized-city-tour' ) );
		}

		$mime = get_post_mime_type( $photo_id ) ?: 'image/jpeg';
		if ( ! preg_match( '#^image/(?:jpeg|png|webp)$#i', $mime ) ) {
			$mime = 'image/jpeg';
		}
		$filename = basename( $photo );
		$boundary = '----PCTOpenAI' . wp_generate_password( 24, false, false );

		$parts = array();
		$add_field = static function ( $name, $value ) use ( &$parts, $boundary ) {
			$parts[] = '--' . $boundary . "\r\n"
				. 'Content-Disposition: form-data; name="' . $name . "\"\r\n\r\n"
				. (string) $value . "\r\n";
		};
		$add_file = static function ( $name, $filename, $mime, $contents ) use ( &$parts, $boundary ) {
			$parts[] = '--' . $boundary . "\r\n"
				. 'Content-Disposition: form-data; name="' . $name . '"; filename="' . addcslashes( $filename, "\"\\" ) . "\"\r\n"
				. 'Content-Type: ' . $mime . "\r\n\r\n"
				. $contents . "\r\n";
		};

		$add_field( 'model', $model );
		$add_field( 'prompt', $prompt );
		$add_field( 'n', '1' );
		$add_field( 'size', $size );
		$add_field( 'quality', 'high' );
		$add_file( 'image[]', $filename, $mime, $data );
		$body = implode( '', $parts ) . '--' . $boundary . "--\r\n";

		$res = PCT_AI::post_with_retry(
			'https://api.openai.com/v1/images/edits',
			array(
				'timeout' => 180,
				'headers' => array(
					'Authorization' => 'Bearer ' . $key,
					'Content-Type'  => 'multipart/form-data; boundary=' . $boundary,
				),
				'body' => $body,
			)
		);

		if ( ! is_wp_error( $res ) ) {
			$code = (int) wp_remote_retrieve_response_code( $res );
			$request_id = (string) wp_remote_retrieve_header( $res, 'x-request-id' );
			PCT_AI_Log::log( array(
				'kind'     => 'image_reference',
				'provider' => 'openai',
				'status'   => ( $code >= 200 && $code < 300 ) ? 'ok' : 'error',
				'title'    => 'Reference photo edit',
				'request'  => 'OpenAI Images edit: uploaded visitor photo attached; model=' . $model . '; output_size=' . $size . '; mime=' . $mime . '; bytes=' . strlen( $data ) . ( $request_id ? '; request_id=' . sanitize_text_field( $request_id ) : '' ),
			) );
		}

		return self::parse_openai_image_response( $res, 'openai' );
	}

	/** @return array|WP_Error */
	private static function parse_openai_image_response( $res, $provider ) {
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$code = wp_remote_retrieve_response_code( $res );
		$body = json_decode( wp_remote_retrieve_body( $res ), true );
		if ( $code < 200 || $code >= 300 || ! is_array( $body ) ) {
			$message = isset( $body['error']['message'] ) ? $body['error']['message'] : ( isset( $body['error'] ) && is_string( $body['error'] ) ? $body['error'] : '' );
			return new WP_Error( 'pct_image_http', sprintf( __( 'Image provider returned HTTP %s.', 'personalized-city-tour' ), $code ) . ( $message ? ' ' . sanitize_text_field( $message ) : '' ) );
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
			$img_code = (int) wp_remote_retrieve_response_code( $img );
			if ( $img_code < 200 || $img_code >= 300 ) {
				return new WP_Error( 'pct_image_download', sprintf( __( 'Generated image URL returned HTTP %d.', 'personalized-city-tour' ), $img_code ) );
			}
			return array( 'data' => wp_remote_retrieve_body( $img ), 'ext' => 'png', 'provider' => $provider );
		}
		return new WP_Error( 'pct_image_empty', __( 'The image provider returned no image.', 'personalized-city-tour' ) );
	}

	private static function image_key_for_provider( $provider ) {
		$provider = (string) $provider;
		$key = PCT_Settings::secret( 'image_api_key' );
		if ( '' !== $key ) {
			return $key;
		}
		if ( in_array( $provider, array( 'openai', 'custom' ), true ) ) {
			return PCT_Settings::ai_key();
		}
		if ( 'pollinations' === $provider || 'huggingface' === $provider ) {
			return $key;
		}
		return '';
	}

	/** Dedicated image key. Legacy OpenAI/custom providers may reuse the AI key;
	 * Pollinations must use its own key so a Groq key is never sent there. */
	public static function image_key() {
		$key = PCT_Settings::secret( 'image_api_key' );
		$provider = (string) PCT_Settings::get( 'image_provider', 'mock' );
		if ( '' !== $key || 'pollinations' === $provider || 'huggingface' === $provider ) {
			return $key;
		}
		return PCT_Settings::ai_key();
	}

	public static function image_endpoint() {
		$provider = (string) PCT_Settings::get( 'image_provider', 'mock' );
		if ( 'openai' === $provider ) {
			return 'https://api.openai.com/v1/images/generations';
		}
		if ( 'pollinations' === $provider ) {
			return 'https://gen.pollinations.ai/v1/images/generations';
		}
		if ( 'huggingface' === $provider ) {
			$model = PCT_Settings::get( 'image_hf_model', 'black-forest-labs/FLUX.1-schnell' );
			return 'https://router.huggingface.co/hf-inference/models/' . implode( '/', array_map( 'rawurlencode', explode( '/', $model ) ) );
		}
		if ( 'gemini' === $provider ) { return (string) PCT_Settings::get( 'image_endpoint', '' ) ?: 'https://generativelanguage.googleapis.com/v1beta/interactions'; }
		if ( 'higgsfield' === $provider ) { return 'https://api.higgsfield.ai/' . ltrim( (string) PCT_Settings::get( 'image_higgsfield_model', 'z-image/turbo' ), '/' ); }
		return (string) PCT_Settings::get( 'image_endpoint' );
	}

	/**
	 * Image generator diagnostics. Checks configuration and, for live
	 * providers, authenticates against the provider's model listing endpoint
	 * (no image is generated, so the check is free). Never returns keys.
	 *
	 * @return array List of { label, status: ok|warn|error|skip, detail }.
	 */
	public static function diagnose() {
		$checks = array();
		$check  = function ( $label, $status, $detail = '' ) use ( &$checks ) {
			$checks[] = array( 'label' => $label, 'status' => $status, 'detail' => (string) $detail );
		};
		$provider = (string) PCT_Settings::get( 'image_provider', 'mock' );
		$s        = self::settings();
		$labels   = array(
			'mock'         => __( 'Sandbox (SVG mock)', 'personalized-city-tour' ),
			'openai'       => __( 'OpenAI Images', 'personalized-city-tour' ),
			'custom'       => __( 'Custom OpenAI-compatible endpoint', 'personalized-city-tour' ),
			'pollinations' => __( 'Pollinations', 'personalized-city-tour' ),
			'huggingface'  => __( 'Hugging Face', 'personalized-city-tour' ),
			'gemini'       => __( 'Google Gemini / Nano Banana', 'personalized-city-tour' ),
			'higgsfield'  => __( 'Higgsfield API', 'personalized-city-tour' ),
		);
		$check( __( 'Provider', 'personalized-city-tour' ), 'mock' === $provider ? 'warn' : 'ok', isset( $labels[ $provider ] ) ? $labels[ $provider ] : $provider );
		$check( __( 'Model', 'personalized-city-tour' ), PCT_Settings::get( 'image_model' ) ? 'ok' : 'warn', (string) PCT_Settings::get( 'image_model' ) );
		$check( __( 'Image size', 'personalized-city-tour' ), 'ok', (string) $s['image_size'] );
		if ( (int) PCT_Settings::get( 'image_fallback_enabled', 1 ) ) {
			$hf = PCT_Settings::has_secret( 'image_hf_api_key' ) ? __( 'configured', 'personalized-city-tour' ) : __( 'not configured — Sandbox remains available', 'personalized-city-tour' );
			$check( __( 'Fallback chain', 'personalized-city-tour' ), 'ok', __( 'Primary provider → Pollinations → Hugging Face → Sandbox', 'personalized-city-tour' ) . ' (' . $hf . ')' );
		} else {
			$check( __( 'Fallback chain', 'personalized-city-tour' ), 'warn', __( 'Disabled — only the selected provider will be tried.', 'personalized-city-tour' ) );
		}

		$dir = wp_upload_dir();
		$ok  = ! empty( $dir['basedir'] ) && wp_mkdir_p( $dir['basedir'] ) && is_writable( $dir['basedir'] );
		$check( __( 'Uploads folder writable', 'personalized-city-tour' ), $ok ? 'ok' : 'error', (string) $dir['basedir'] );

		if ( 'mock' === $provider || $s['sandbox'] ) {
			$check( __( 'Live request', 'personalized-city-tour' ), 'skip', $s['sandbox'] && 'mock' !== $provider ? __( 'Vibe sandbox mode is on; the provider is not called.', 'personalized-city-tour' ) : __( 'Mock provider; no external calls.', 'personalized-city-tour' ) );
			return $checks;
		}
		$endpoint = self::image_endpoint();
		$check( __( 'Endpoint URL', 'personalized-city-tour' ), $endpoint ? 'ok' : 'error', $endpoint ? $endpoint : __( 'Enter the image generation endpoint.', 'personalized-city-tour' ) );
		$key_src = PCT_Settings::has_secret( 'image_api_key' ) ? __( 'dedicated image key', 'personalized-city-tour' ) : ( '' !== PCT_Settings::ai_key() ? __( 'falls back to the AI provider key', 'personalized-city-tour' ) : '' );
		$check( __( 'API key', 'personalized-city-tour' ), $key_src ? 'ok' : 'error', $key_src ? $key_src : __( 'Missing — save an image API key or an AI provider key.', 'personalized-city-tour' ) );
		if ( ! $endpoint || ! $key_src ) { return $checks; }

		if ( 'huggingface' === $provider ) {
			$check( __( 'Live request', 'personalized-city-tour' ), 'warn', __( 'Hugging Face image inference is configured; the diagnostic does not spend credits by generating a real image.', 'personalized-city-tour' ) );
			return $checks;
		}
		if ( 'gemini' === $provider ) {
			$key = PCT_Settings::secret( 'image_gemini_api_key' );
			$check( __( 'API key', 'personalized-city-tour' ), $key ? 'ok' : 'error', $key ? __( 'Gemini key configured.', 'personalized-city-tour' ) : __( 'Gemini API key is missing.', 'personalized-city-tour' ) );
			$check( __( 'Live request', 'personalized-city-tour' ), 'warn', __( 'Gemini image generation is configured; diagnostic does not generate an image or spend image credits.', 'personalized-city-tour' ) );
			return $checks;
		}
		if ( 'higgsfield' === $provider ) {
			$key_id = PCT_Settings::secret( 'image_higgsfield_key_id' );
			$key_secret = PCT_Settings::secret( 'image_higgsfield_key_secret' );
			$check( __( 'API credentials', 'personalized-city-tour' ), $key_id && $key_secret ? 'ok' : 'error', $key_id && $key_secret ? __( 'Higgsfield key ID and secret configured.', 'personalized-city-tour' ) : __( 'Both Higgsfield key ID and secret are required.', 'personalized-city-tour' ) );
			$check( __( 'Live request', 'personalized-city-tour' ), 'warn', __( 'Higgsfield is configured; diagnostic does not generate an image or spend generation credits.', 'personalized-city-tour' ) );
			return $checks;
		}

		// Authenticate against /models (or the endpoint root) instead of generating an image.
		$models_url = preg_replace( '#/images/generations/?$#', '/models', untrailingslashit( $endpoint ) );
		if ( $models_url === untrailingslashit( $endpoint ) ) {
			$models_url = untrailingslashit( $endpoint ) . '/models';
		}
		$started = microtime( true );
		$res     = wp_remote_get( $models_url, array( 'timeout' => 20, 'headers' => array( 'Authorization' => 'Bearer ' . self::image_key() ) ) );
		$ms      = (int) round( ( microtime( true ) - $started ) * 1000 );
		if ( is_wp_error( $res ) ) {
			$check( __( 'Live request', 'personalized-city-tour' ), 'error', $res->get_error_message() );
			return $checks;
		}
		$code = (int) wp_remote_retrieve_response_code( $res );
		$body = json_decode( wp_remote_retrieve_body( $res ), true );
		if ( 401 === $code || 403 === $code ) {
			$check( __( 'Live request', 'personalized-city-tour' ), 'error', sprintf( /* translators: %d: HTTP status */ __( 'HTTP %d — the API key was rejected.', 'personalized-city-tour' ), $code ) );
		} elseif ( $code >= 200 && $code < 300 ) {
			$ids   = array_column( (array) ( $body['data'] ?? array() ), 'id' );
			$model = (string) PCT_Settings::get( 'image_model' );
			$found = ! $ids || in_array( $model, $ids, true );
			$check( __( 'Live request', 'personalized-city-tour' ), $found ? 'ok' : 'warn', sprintf( /* translators: %d: milliseconds */ __( 'Authenticated in %d ms.', 'personalized-city-tour' ), $ms ) . ( $found ? '' : ' ' . sprintf( /* translators: %s: model */ __( 'Model "%s" is not in the provider model list.', 'personalized-city-tour' ), $model ) ) );
		} elseif ( 404 === $code || 405 === $code ) {
			$check( __( 'Live request', 'personalized-city-tour' ), 'warn', __( 'Endpoint reachable, but it has no /models listing; run a real generation to fully verify.', 'personalized-city-tour' ) );
		} else {
			$check( __( 'Live request', 'personalized-city-tour' ), 'error', sprintf( /* translators: %d: HTTP status */ __( 'HTTP %d', 'personalized-city-tour' ), $code ) . ( isset( $body['error']['message'] ) ? ' — ' . $body['error']['message'] : '' ) );
		}
		return $checks;
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
