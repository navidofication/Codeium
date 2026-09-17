<?php
/**
 * Plugin settings (option: pct_settings).
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

	public static function defaults() {
		return array(
			'access_mode'          => 'public',
			'guide_registration'   => 1,
			'guide_auto_publish'   => 0,
			'ai_provider'          => 'none',
			'ai_api_key'           => '',
			'ai_model'             => 'gpt-4o-mini',
			'ai_endpoint'          => '',
			'ai_temperature'       => '0.6',
			'ai_max_items'         => 60,
			'stops_per_day'        => 3,
			'map_provider'         => 'osm',
			'google_maps_api_key'  => '',
			'routing_provider'     => 'osrm',
			'osrm_endpoint'        => 'https://router.project-osrm.org',
			'tile_url'             => 'https://tile.openstreetmap.org/{z}/{x}/{y}.png',
			'tile_attribution'     => '&copy; OpenStreetMap contributors',
			'store_submissions'    => 1,
			'notify_admin_guides'  => 1,
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
	 * Effective AI key: constant overrides stored option.
	 */
	public static function ai_key() {
		if ( defined( 'PCT_AI_API_KEY' ) && PCT_AI_API_KEY ) {
			return PCT_AI_API_KEY;
		}
		return (string) self::get( 'ai_api_key' );
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

	public static function sanitize( $input ) {
		$defaults = self::defaults();
		$current  = wp_parse_args( (array) get_option( self::OPTION, array() ), $defaults );
		$input    = is_array( $input ) ? $input : array();
		$out      = $current;

		$out['access_mode']         = in_array( $input['access_mode'] ?? '', array( 'public', 'logged_in' ), true ) ? $input['access_mode'] : 'public';
		$out['guide_registration']  = empty( $input['guide_registration'] ) ? 0 : 1;
		$out['guide_auto_publish']  = empty( $input['guide_auto_publish'] ) ? 0 : 1;
		$out['store_submissions']   = empty( $input['store_submissions'] ) ? 0 : 1;
		$out['notify_admin_guides'] = empty( $input['notify_admin_guides'] ) ? 0 : 1;

		$providers            = array_keys( PCT_AI::providers() );
		$out['ai_provider']   = in_array( $input['ai_provider'] ?? '', $providers, true ) ? $input['ai_provider'] : 'none';
		$out['ai_model']      = sanitize_text_field( $input['ai_model'] ?? $defaults['ai_model'] );
		$out['ai_endpoint']   = esc_url_raw( trim( (string) ( $input['ai_endpoint'] ?? '' ) ) );
		$temp                 = floatval( $input['ai_temperature'] ?? 0.6 );
		$out['ai_temperature'] = (string) max( 0, min( 2, $temp ) );
		$out['ai_max_items']  = max( 10, min( 300, absint( $input['ai_max_items'] ?? 60 ) ) );
		$out['stops_per_day'] = max( 1, min( 8, absint( $input['stops_per_day'] ?? 3 ) ) );

		// Keys: keep the stored key when the field is submitted empty.
		$key = trim( (string) ( $input['ai_api_key'] ?? '' ) );
		if ( '' !== $key ) {
			$out['ai_api_key'] = sanitize_text_field( $key );
		}
		if ( ! empty( $input['ai_api_key_clear'] ) ) {
			$out['ai_api_key'] = '';
		}
		$gkey = trim( (string) ( $input['google_maps_api_key'] ?? '' ) );
		if ( '' !== $gkey ) {
			$out['google_maps_api_key'] = sanitize_text_field( $gkey );
		}
		if ( ! empty( $input['google_maps_api_key_clear'] ) ) {
			$out['google_maps_api_key'] = '';
		}

		$out['map_provider']     = in_array( $input['map_provider'] ?? '', array( 'osm', 'google' ), true ) ? $input['map_provider'] : 'osm';
		$out['routing_provider'] = in_array( $input['routing_provider'] ?? '', array( 'osrm', 'google', 'straight' ), true ) ? $input['routing_provider'] : 'osrm';
		$out['osrm_endpoint']    = esc_url_raw( untrailingslashit( trim( (string) ( $input['osrm_endpoint'] ?? $defaults['osrm_endpoint'] ) ) ) );
		if ( '' === $out['osrm_endpoint'] ) {
			$out['osrm_endpoint'] = $defaults['osrm_endpoint'];
		}
		$tile = trim( (string) ( $input['tile_url'] ?? '' ) );
		$out['tile_url']         = $tile ? esc_url_raw( $tile ) : $defaults['tile_url'];
		$out['tile_attribution'] = wp_kses_post( $input['tile_attribution'] ?? $defaults['tile_attribution'] );

		// Roles depend on auto-publish; refresh them when it changes.
		if ( (int) $out['guide_auto_publish'] !== (int) $current['guide_auto_publish'] ) {
			self::$cache = $out;
			PCT_Roles::add_roles();
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
