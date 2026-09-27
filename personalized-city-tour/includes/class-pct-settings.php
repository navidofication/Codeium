<?php
/**
 * Plugin settings (single option, tabbed admin UI).
 *
 * Secrets (API keys, tokens) are stored encrypted through PCT_Secrets and may
 * be overridden with constants in wp-config.php:
 *   PCT_AI_API_KEY, PCT_GOOGLE_MAPS_API_KEY, PCT_MAPBOX_TOKEN,
 *   PCT_TRANSLATION_API_KEY, PCT_DRIVE_CLIENT_SECRET, PCT_SMS_API_KEY, PCT_IMAGE_API_KEY.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PCT_Settings {

	const OPTION = 'pct_settings';

	/** @var array|null */
	private static $cache = null;

	public static function init() {
		add_action( 'admin_init', array( __CLASS__, 'register' ) );
	}

	/**
	 * Option keys that hold secrets (encrypted at rest, never echoed back).
	 */
	public static function secret_keys() {
		return array(
			'ai_api_key'          => 'PCT_AI_API_KEY',
			'google_maps_api_key' => 'PCT_GOOGLE_MAPS_API_KEY',
			'mapbox_token'        => 'PCT_MAPBOX_TOKEN',
			'translation_api_key' => 'PCT_TRANSLATION_API_KEY',
			'drive_client_secret' => 'PCT_DRIVE_CLIENT_SECRET',
			'drive_refresh_token' => '',
			'sms_api_key'         => 'PCT_SMS_API_KEY',
			'image_api_key'       => 'PCT_IMAGE_API_KEY',
		);
	}

	public static function defaults() {
		return array(
			/* attribution */
			'author_name'              => 'Rahimghazi.ir',
			'author_url'               => 'https://rahimghazi.ir',
			'credits'                  => 'RahimGhazi & Devin/R&D HumanAI',
			'show_credits_frontend'    => 0,

			/* general */
			'access_mode'              => 'public',
			'guide_registration'       => 1,
			'guide_auto_publish'       => 0,
			'guide_public_events'      => 1,
			'guide_require_mobile'     => 1,
			'store_submissions'        => 1,
			'notify_admin_guides'      => 1,
			'stops_per_day'            => 3,
			'calendar'                 => 'both',

			/* languages */
			'language'                 => 'fa',
			'enabled_languages'        => array( 'fa', 'en', 'es', 'zh', 'ru', 'hi', 'ar' ),
			'translation_provider'     => 'none',
			'translation_api_key'      => '',
			'translation_endpoint'     => '',
			'translate_ai_output'      => 0,

			/* AI */
			'ai_provider'              => 'none',
			'ai_api_key'               => '',
			'ai_model'                 => 'gpt-4o-mini',
			'ai_deployment'            => '',
			'ai_model_map'             => array(),
			'ai_endpoint'              => '',
			'ai_temperature'           => '0.6',
			'ai_max_items'             => 60,
			'ai_timeout'               => 90,
			'ai_consent_city_db'       => 0,
			'ai_allowed_fields'        => array( 'name', 'type', 'categories', 'tags', 'summary', 'price_tier', 'accessibility', 'duration', 'best_time', 'hours', 'start', 'end', 'venue', 'organizer', 'channels' ),
			'ai_share_name'            => 0,
			'ai_share_contact'         => 0,
			'ai_log_level'             => 'minimal',
			'ai_redact_pii'            => 1,
			'ai_log_retention_days'    => 30,
			'ai_sandbox'               => 0,
			'ai_retry_invalid'         => 1,
			'ai_rate_limit'            => 60,
			'ai_rate_limit_ip'         => 6,
			'ai_cache_ttl'             => 360,
			'ai_batch_window'          => 30,
			'ai_prompt_template'       => '',

			/* maps */
			'map_provider'             => 'osm',
			'google_maps_api_key'      => '',
			'mapbox_token'             => '',
			'routing_provider'         => 'osrm',
			'osrm_endpoint'            => 'https://router.project-osrm.org',
			'tile_url'                 => 'https://tile.openstreetmap.org/{z}/{x}/{y}.png',
			'tile_attribution'         => '&copy; OpenStreetMap contributors',
			'show_mymaps_public'       => 0,
			'search_page_url'          => '',

			/* analytics */
			'analytics_roles'          => array( 'administrator' ),
			'analytics_retention_days' => 365,

			/* Google Drive */
			'drive_enabled'            => 0,
			'drive_client_id'          => '',
			'drive_client_secret'      => '',
			'drive_refresh_token'      => '',
			'drive_folders'            => array(
				'cities'   => '',
				'forms'    => '',
				'tours'    => '',
				'sessions' => '',
				'reports'  => '',
			),
			'drive_schedule'           => 'none',
			'drive_exports'            => array( 'cities', 'forms', 'tours' ),
			'drive_retention_days'     => 90,
			'drive_sandbox'            => 1,

			/* SMS / OTP / image generation (My Trip, My Vibe) */
			'sms_provider'             => 'manual',
			'sms_endpoint'             => '',
			'sms_api_key'              => '',
			'sms_sender'               => '',
			'sms_country_code'         => '98',
			'admin_mobile'             => '',
			'image_provider'           => 'mock',
			'image_endpoint'           => '',
			'image_api_key'            => '',
			'image_model'              => 'gpt-image-1',
		);
	}

	public static function get( $key = null, $default = null ) {
		if ( null === self::$cache ) {
			$saved       = get_option( self::OPTION, array() );
			self::$cache = wp_parse_args( is_array( $saved ) ? $saved : array(), self::defaults() );
		}
		if ( null === $key ) {
			return self::$cache;
		}
		return isset( self::$cache[ $key ] ) ? self::$cache[ $key ] : $default;
	}

	public static function update( array $values ) {
		self::$cache = null;
		return update_option( self::OPTION, self::sanitize( $values ) );
	}

	/**
	 * Store a single value without re-running the form sanitizer (used by OAuth flows and jobs).
	 */
	public static function set( $key, $value ) {
		$all = self::get();
		if ( array_key_exists( $key, self::secret_keys() ) ) {
			$value = PCT_Secrets::encrypt( $value );
		}
		$all[ $key ] = $value;
		self::$cache = null;
		return update_option( self::OPTION, $all );
	}

	/**
	 * Decrypted secret; a wp-config constant overrides the stored value.
	 */
	public static function secret( $key ) {
		$constants = self::secret_keys();
		$const     = isset( $constants[ $key ] ) ? $constants[ $key ] : '';
		if ( $const && defined( $const ) && constant( $const ) ) {
			return (string) constant( $const );
		}
		return PCT_Secrets::decrypt( (string) self::get( $key ) );
	}

	public static function has_secret( $key ) {
		return '' !== self::secret( $key );
	}

	public static function ai_key() {
		return self::secret( 'ai_api_key' );
	}

	public static function register() {
		register_setting(
			'pct_settings_group',
			self::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize' ),
				'default'           => self::defaults(),
			)
		);
	}

	/**
	 * Settings tabs → keys. Only the submitted tab's keys are updated so that
	 * unchecked checkboxes on other tabs are not reset.
	 */
	public static function tabs() {
		return array(
			'general'   => __( 'General', 'personalized-city-tour' ),
			'ai'        => __( 'AI Integration', 'personalized-city-tour' ),
			'languages' => __( 'Languages', 'personalized-city-tour' ),
			'maps'      => __( 'Maps & Navigation', 'personalized-city-tour' ),
			'drive'     => __( 'Google Drive', 'personalized-city-tour' ),
			'sms'       => __( 'SMS & Images', 'personalized-city-tour' ),
			'about'     => __( 'Attribution', 'personalized-city-tour' ),
		);
	}

	private static function secret_field( array &$out, array $input, $key ) {
		$val = trim( (string) ( $input[ $key ] ?? '' ) );
		if ( '' !== $val ) {
			$out[ $key ] = PCT_Secrets::encrypt( sanitize_text_field( $val ) );
		} elseif ( isset( $out[ $key ] ) && '' !== $out[ $key ] && ! PCT_Secrets::is_encrypted( $out[ $key ] ) ) {
			// Migrate legacy plain values to encrypted storage.
			$out[ $key ] = PCT_Secrets::encrypt( $out[ $key ] );
		}
		if ( ! empty( $input[ $key . '_clear' ] ) ) {
			$out[ $key ] = '';
		}
	}

	private static function bool( array $input, $key ) {
		return empty( $input[ $key ] ) ? 0 : 1;
	}

	public static function sanitize( $input ) {
		$defaults = self::defaults();
		$current  = wp_parse_args( (array) get_option( self::OPTION, array() ), $defaults );
		$input    = is_array( $input ) ? $input : array();
		$out      = $current;
		$tab      = isset( $input['_tab'] ) ? sanitize_key( $input['_tab'] ) : 'all';
		$is       = function ( $name ) use ( $tab ) {
			return 'all' === $tab || $tab === $name;
		};

		if ( $is( 'about' ) ) {
			$out['author_name']           = sanitize_text_field( $input['author_name'] ?? $defaults['author_name'] ) ?: $defaults['author_name'];
			$out['author_url']            = esc_url_raw( $input['author_url'] ?? $defaults['author_url'] ) ?: $defaults['author_url'];
			$out['credits']               = sanitize_text_field( $input['credits'] ?? $defaults['credits'] ) ?: $defaults['credits'];
			$out['show_credits_frontend'] = self::bool( $input, 'show_credits_frontend' );
		}

		if ( $is( 'general' ) ) {
			$out['access_mode']          = in_array( $input['access_mode'] ?? '', array( 'public', 'logged_in' ), true ) ? $input['access_mode'] : 'public';
			$out['guide_registration']   = self::bool( $input, 'guide_registration' );
			$out['guide_auto_publish']   = self::bool( $input, 'guide_auto_publish' );
			$out['guide_public_events']  = self::bool( $input, 'guide_public_events' );
			$out['guide_require_mobile'] = self::bool( $input, 'guide_require_mobile' );
			$out['store_submissions']    = self::bool( $input, 'store_submissions' );
			$out['notify_admin_guides']  = self::bool( $input, 'notify_admin_guides' );
			$out['stops_per_day']        = max( 1, min( 8, absint( $input['stops_per_day'] ?? 3 ) ) );
			$out['calendar']             = in_array( $input['calendar'] ?? '', array( 'both', 'jalali', 'gregorian' ), true ) ? $input['calendar'] : 'both';
			$roles                       = array_keys( wp_roles()->roles );
			$out['analytics_roles']      = array_values( array_intersect( array_map( 'sanitize_key', (array) ( $input['analytics_roles'] ?? array() ) ), $roles ) );
			if ( ! in_array( 'administrator', $out['analytics_roles'], true ) ) {
				$out['analytics_roles'][] = 'administrator';
			}
			$out['analytics_retention_days'] = max( 0, min( 3650, absint( $input['analytics_retention_days'] ?? 365 ) ) );
		}

		if ( $is( 'languages' ) ) {
			$langs                        = array_keys( PCT_I18n::languages() );
			$out['language']              = in_array( $input['language'] ?? '', $langs, true ) ? $input['language'] : 'fa';
			$enabled                      = array_values( array_intersect( array_map( 'sanitize_key', (array) ( $input['enabled_languages'] ?? array() ) ), $langs ) );
			$out['enabled_languages']     = $enabled ? array_values( array_unique( array_merge( array( $out['language'] ), $enabled ) ) ) : $langs;
			$out['translation_provider']  = in_array( $input['translation_provider'] ?? '', array_keys( PCT_I18n::translation_providers() ), true ) ? $input['translation_provider'] : 'none';
			$out['translation_endpoint']  = esc_url_raw( trim( (string) ( $input['translation_endpoint'] ?? '' ) ) );
			$out['translate_ai_output']   = self::bool( $input, 'translate_ai_output' );
			self::secret_field( $out, $input, 'translation_api_key' );
		}

		if ( $is( 'ai' ) ) {
			$providers                = array_keys( PCT_AI::providers() );
			$out['ai_provider']       = in_array( $input['ai_provider'] ?? '', $providers, true ) ? $input['ai_provider'] : 'none';
			$model                    = sanitize_text_field( $input['ai_model'] ?? '' );
			if ( '__custom' === $model || '' === $model ) {
				$model = sanitize_text_field( $input['ai_model_custom'] ?? '' );
			}
			$out['ai_model']          = $model ? $model : $defaults['ai_model'];
			$out['ai_deployment']     = sanitize_text_field( $input['ai_deployment'] ?? '' );
			$out['ai_endpoint']       = esc_url_raw( trim( (string) ( $input['ai_endpoint'] ?? '' ) ) );
			$temp                     = floatval( $input['ai_temperature'] ?? 0.6 );
			$out['ai_temperature']    = (string) max( 0, min( 2, $temp ) );
			$out['ai_max_items']      = max( 10, min( 300, absint( $input['ai_max_items'] ?? 60 ) ) );
			$out['ai_timeout']        = max( 15, min( 600, absint( $input['ai_timeout'] ?? 90 ) ) );
			$out['ai_consent_city_db'] = self::bool( $input, 'ai_consent_city_db' );
			$out['ai_share_name']     = self::bool( $input, 'ai_share_name' );
			$out['ai_share_contact']  = self::bool( $input, 'ai_share_contact' );
			$out['ai_log_level']      = in_array( $input['ai_log_level'] ?? '', array( 'none', 'minimal', 'full' ), true ) ? $input['ai_log_level'] : 'minimal';
			$out['ai_redact_pii']     = self::bool( $input, 'ai_redact_pii' );
			$out['ai_log_retention_days'] = max( 0, min( 3650, absint( $input['ai_log_retention_days'] ?? 30 ) ) );
			$out['ai_sandbox']        = self::bool( $input, 'ai_sandbox' );
			$out['ai_retry_invalid']  = self::bool( $input, 'ai_retry_invalid' );
			$out['ai_rate_limit']     = max( 0, min( 100000, absint( $input['ai_rate_limit'] ?? 60 ) ) );
			$out['ai_rate_limit_ip']  = max( 0, min( 10000, absint( $input['ai_rate_limit_ip'] ?? 6 ) ) );
			$out['ai_cache_ttl']      = max( 0, min( 43200, absint( $input['ai_cache_ttl'] ?? 360 ) ) );
			$out['ai_batch_window']   = max( 0, min( 120, absint( $input['ai_batch_window'] ?? 30 ) ) );
			$out['ai_prompt_template'] = sanitize_textarea_field( $input['ai_prompt_template'] ?? '' );
			$allowed                  = array_keys( PCT_Tour_Generator::extract_fields() );
			$out['ai_allowed_fields'] = array_values( array_intersect( array_map( 'sanitize_key', (array) ( $input['ai_allowed_fields'] ?? array() ) ), $allowed ) );
			if ( empty( $out['ai_allowed_fields'] ) ) {
				$out['ai_allowed_fields'] = array( 'name', 'type', 'categories' );
			}
			$map = array();
			foreach ( (array) ( $input['ai_model_map'] ?? array() ) as $row ) {
				$p = sanitize_key( $row['provider'] ?? '' );
				$m = sanitize_text_field( $row['model'] ?? '' );
				if ( ! in_array( $p, $providers, true ) || '' === $m ) {
					continue;
				}
				$map[ $p ][] = array(
					'model'      => $m,
					'deployment' => sanitize_text_field( $row['deployment'] ?? '' ),
					'label'      => sanitize_text_field( $row['label'] ?? '' ),
				);
			}
			$out['ai_model_map'] = $map;
			self::secret_field( $out, $input, 'ai_api_key' );
		}

		if ( $is( 'maps' ) ) {
			$out['map_provider']       = in_array( $input['map_provider'] ?? '', array( 'osm', 'google', 'mapbox' ), true ) ? $input['map_provider'] : 'osm';
			$out['routing_provider']   = in_array( $input['routing_provider'] ?? '', array( 'osrm', 'google', 'mapbox', 'straight' ), true ) ? $input['routing_provider'] : 'osrm';
			$out['osrm_endpoint']      = esc_url_raw( untrailingslashit( trim( (string) ( $input['osrm_endpoint'] ?? $defaults['osrm_endpoint'] ) ) ) );
			if ( '' === $out['osrm_endpoint'] ) {
				$out['osrm_endpoint'] = $defaults['osrm_endpoint'];
			}
			$tile                      = trim( (string) ( $input['tile_url'] ?? '' ) );
			$out['tile_url']           = $tile ? esc_url_raw( $tile ) : $defaults['tile_url'];
			$out['tile_attribution']   = wp_kses_post( $input['tile_attribution'] ?? $defaults['tile_attribution'] );
			$out['show_mymaps_public'] = self::bool( $input, 'show_mymaps_public' );
			$out['search_page_url']    = esc_url_raw( trim( (string) ( $input['search_page_url'] ?? '' ) ) );
			self::secret_field( $out, $input, 'google_maps_api_key' );
			self::secret_field( $out, $input, 'mapbox_token' );
		}

		if ( $is( 'drive' ) ) {
			$out['drive_enabled']        = self::bool( $input, 'drive_enabled' );
			$out['drive_client_id']      = sanitize_text_field( $input['drive_client_id'] ?? '' );
			$out['drive_schedule']       = in_array( $input['drive_schedule'] ?? '', array_keys( PCT_Drive::schedules() ), true ) ? $input['drive_schedule'] : 'none';
			$out['drive_sandbox']        = self::bool( $input, 'drive_sandbox' );
			$out['drive_exports']        = array_values( array_intersect( array_map( 'sanitize_key', (array) ( $input['drive_exports'] ?? array() ) ), array_keys( PCT_Drive::export_types() ) ) );
			$out['drive_retention_days'] = max( 0, min( 3650, absint( $input['drive_retention_days'] ?? 90 ) ) );
			$folders                     = array();
			foreach ( array_keys( $defaults['drive_folders'] ) as $k ) {
				$folders[ $k ] = sanitize_text_field( $input['drive_folders'][ $k ] ?? '' );
			}
			$out['drive_folders'] = $folders;
			self::secret_field( $out, $input, 'drive_client_secret' );
			if ( ! empty( $input['drive_disconnect'] ) ) {
				$out['drive_refresh_token'] = '';
			}
		}

		if ( $is( 'sms' ) ) {
			$out['sms_provider']   = in_array( $input['sms_provider'] ?? '', array( 'manual', 'email', 'webhook' ), true ) ? $input['sms_provider'] : 'manual';
			$out['sms_endpoint']   = esc_url_raw( trim( (string) ( $input['sms_endpoint'] ?? '' ) ) );
			$out['sms_sender']     = sanitize_text_field( $input['sms_sender'] ?? '' );
			$out['sms_country_code'] = preg_replace( '/\D/', '', (string) ( $input['sms_country_code'] ?? '' ) );
			$out['admin_mobile']   = preg_replace( '/[^0-9+]/', '', (string) ( $input['admin_mobile'] ?? '' ) );
			$out['image_provider'] = in_array( $input['image_provider'] ?? '', array( 'mock', 'openai', 'custom' ), true ) ? $input['image_provider'] : 'mock';
			$out['image_endpoint'] = esc_url_raw( trim( (string) ( $input['image_endpoint'] ?? '' ) ) );
			$out['image_model']    = sanitize_text_field( $input['image_model'] ?? 'gpt-image-1' ) ?: 'gpt-image-1';
			self::secret_field( $out, $input, 'sms_api_key' );
			self::secret_field( $out, $input, 'image_api_key' );
		}

		// Roles depend on auto-publish; refresh them when it changes.
		if ( (int) $out['guide_auto_publish'] !== (int) $current['guide_auto_publish'] ) {
			self::$cache = $out;
			PCT_Roles::add_roles();
		}
		if ( $out['drive_schedule'] !== $current['drive_schedule'] || (int) $out['drive_enabled'] !== (int) $current['drive_enabled'] ) {
			self::$cache = $out;
			PCT_Drive::reschedule( $out );
		}

		self::$cache = null;
		return $out;
	}

	/**
	 * Whether the current visitor may use the traveler frontend.
	 */
	public static function visitor_allowed() {
		if ( 'logged_in' === self::get( 'access_mode' ) ) {
			return is_user_logged_in();
		}
		return true;
	}
}
