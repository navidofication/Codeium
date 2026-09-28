<?php
/**
 * Multilingual UI.
 *
 * Strategy: every plugin string goes through the text domain. For languages
 * other than English the plugin loads a JSON bundle (languages/pct-{lang}.json,
 * "English source" => "translation") merged with admin-uploaded overrides and
 * applies it through the gettext filters. Missing strings can optionally be
 * fetched from an online translation provider and cached.
 *
 * The active language is resolved from: ?pct_lang= → pct_lang cookie →
 * user meta (admin) → settings default (fa).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PCT_I18n {

	const COOKIE      = 'pct_lang';
	const BUNDLES_OPT = 'pct_lang_bundles';
	const CACHE_OPT   = 'pct_translation_cache';

	/** @var string|null */
	private static $current = null;

	/** @var array */
	private static $bundle = array();

	/** @var bool */
	private static $translating = false;

	public static function languages() {
		return array(
			'fa' => array( 'label' => 'فارسی', 'english' => 'Persian', 'rtl' => true, 'locale' => 'fa_IR', 'calendar' => 'jalali' ),
			'en' => array( 'label' => 'English', 'english' => 'English', 'rtl' => false, 'locale' => 'en_US', 'calendar' => 'gregorian' ),
			'es' => array( 'label' => 'Español', 'english' => 'Spanish', 'rtl' => false, 'locale' => 'es_ES', 'calendar' => 'gregorian' ),
			'zh' => array( 'label' => '中文', 'english' => 'Chinese', 'rtl' => false, 'locale' => 'zh_CN', 'calendar' => 'gregorian' ),
			'ru' => array( 'label' => 'Русский', 'english' => 'Russian', 'rtl' => false, 'locale' => 'ru_RU', 'calendar' => 'gregorian' ),
			'hi' => array( 'label' => 'हिन्दी', 'english' => 'Hindi', 'rtl' => false, 'locale' => 'hi_IN', 'calendar' => 'gregorian' ),
			'ar' => array( 'label' => 'العربية', 'english' => 'Arabic', 'rtl' => true, 'locale' => 'ar', 'calendar' => 'gregorian' ),
		);
	}

	public static function translation_providers() {
		return array(
			'none'   => __( 'None — local bundles only', 'personalized-city-tour' ),
			'google' => __( 'Google Cloud Translation (v2 API key)', 'personalized-city-tour' ),
			'deepl'  => __( 'DeepL', 'personalized-city-tour' ),
			'libre'  => __( 'LibreTranslate (self-hosted or public instance)', 'personalized-city-tour' ),
			'custom' => __( 'Custom JSON endpoint', 'personalized-city-tour' ),
		);
	}

	public static function init() {
		add_action( 'init', array( __CLASS__, 'boot' ), 1 );
		add_action( 'rest_api_init', array( __CLASS__, 'boot' ), 1 );
		add_action( 'admin_post_pct_upload_bundle', array( __CLASS__, 'handle_upload' ) );
		add_action( 'admin_post_pct_delete_bundle', array( __CLASS__, 'handle_delete' ) );
		add_action( 'admin_post_pct_admin_lang', array( __CLASS__, 'handle_admin_lang' ) );
	}

	public static function normalize( $code ) {
		$code = strtolower( substr( (string) $code, 0, 2 ) );
		return isset( self::languages()[ $code ] ) ? $code : (string) PCT_Settings::get( 'language', 'fa' );
	}

	public static function enabled_languages() {
		$enabled = (array) PCT_Settings::get( 'enabled_languages', array() );
		$all     = self::languages();
		$out     = array();
		foreach ( $all as $code => $meta ) {
			if ( empty( $enabled ) || in_array( $code, $enabled, true ) ) {
				$out[ $code ] = $meta;
			}
		}
		return $out;
	}

	public static function is_rtl( $lang = null ) {
		$lang = $lang ? $lang : self::current();
		return ! empty( self::languages()[ $lang ]['rtl'] );
	}

	/**
	 * Calendar for a language, honouring the admin "calendar" setting.
	 */
	public static function calendar_for( $lang ) {
		$setting = PCT_Settings::get( 'calendar', 'both' );
		if ( 'both' !== $setting ) {
			return $setting;
		}
		return self::languages()[ $lang ]['calendar'] ?? 'gregorian';
	}

	/**
	 * Resolve the active language once per request.
	 */
	public static function current() {
		if ( null !== self::$current ) {
			return self::$current;
		}
		$lang = '';
		if ( isset( $_GET['pct_lang'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			$lang = sanitize_key( wp_unslash( $_GET['pct_lang'] ) ); // phpcs:ignore WordPress.Security.NonceVerification
		} elseif ( isset( $_SERVER['HTTP_X_PCT_LANG'] ) ) {
			$lang = sanitize_key( wp_unslash( $_SERVER['HTTP_X_PCT_LANG'] ) );
		} elseif ( isset( $_COOKIE[ self::COOKIE ] ) ) {
			$lang = sanitize_key( wp_unslash( $_COOKIE[ self::COOKIE ] ) );
		}
		if ( is_admin() && ! wp_doing_ajax() && get_current_user_id() ) {
			$user_lang = (string) get_user_meta( get_current_user_id(), 'pct_admin_lang', true );
			if ( $user_lang ) {
				$lang = $user_lang;
			}
		}
		if ( ! isset( self::languages()[ $lang ] ) || ! isset( self::enabled_languages()[ $lang ] ) ) {
			$lang = (string) PCT_Settings::get( 'language', 'fa' );
		}
		self::$current = isset( self::languages()[ $lang ] ) ? $lang : 'fa';
		return self::$current;
	}

	/**
	 * Force a language for the rest of the request (REST calls pass ?pct_lang=).
	 */
	public static function set_current( $lang ) {
		self::$current = self::normalize( $lang );
		self::$bundle  = self::bundle( self::$current );
		return self::$current;
	}

	public static function boot() {
		static $booted = false;
		if ( $booted ) {
			return;
		}
		$booted       = true;
		self::$bundle = self::bundle( self::current() );
		add_filter( 'gettext_personalized-city-tour', array( __CLASS__, 'filter_gettext' ), 5, 3 );
		add_filter( 'gettext_with_context_personalized-city-tour', array( __CLASS__, 'filter_gettext_context' ), 5, 4 );
		add_filter( 'ngettext_personalized-city-tour', array( __CLASS__, 'filter_ngettext' ), 5, 5 );
		if ( is_admin() ) {
			add_filter( 'admin_body_class', array( __CLASS__, 'admin_body_class' ) );
		}
	}

	public static function admin_body_class( $classes ) {
		return $classes . ' pct-lang-' . self::current() . ( self::is_rtl() ? ' pct-rtl' : '' );
	}

	/* ------------------------------------------------------------------ */
	/* Bundles                                                             */
	/* ------------------------------------------------------------------ */

	/**
	 * Built-in bundle merged with admin uploads and cached online translations.
	 */
	public static function bundle( $lang ) {
		$lang = self::normalize( $lang );
		if ( 'en' === $lang ) {
			return array();
		}
		$cache_key = 'pct_bundle_' . $lang . '_' . PCT_VERSION;
		$cached    = wp_cache_get( $cache_key, 'pct' );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		$file   = PCT_DIR . 'languages/pct-' . $lang . '.json';
		$bundle = array();
		if ( file_exists( $file ) ) {
			$decoded = json_decode( (string) file_get_contents( $file ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			if ( is_array( $decoded ) ) {
				$bundle = $decoded;
			}
		}
		$uploaded = get_option( self::BUNDLES_OPT, array() );
		if ( isset( $uploaded[ $lang ] ) && is_array( $uploaded[ $lang ] ) ) {
			$bundle = array_merge( $bundle, $uploaded[ $lang ] );
		}
		$online = get_option( self::CACHE_OPT, array() );
		if ( isset( $online[ $lang ] ) && is_array( $online[ $lang ] ) ) {
			$bundle = array_merge( $online[ $lang ], $bundle );
		}
		wp_cache_set( $cache_key, $bundle, 'pct', HOUR_IN_SECONDS );
		return $bundle;
	}

	public static function bundle_stats() {
		$uploaded = get_option( self::BUNDLES_OPT, array() );
		$out      = array();
		foreach ( self::languages() as $code => $meta ) {
			$file  = PCT_DIR . 'languages/pct-' . $code . '.json';
			$built = 0;
			if ( file_exists( $file ) ) {
				$decoded = json_decode( (string) file_get_contents( $file ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions
				$built   = is_array( $decoded ) ? count( $decoded ) : 0;
			}
			$out[ $code ] = array(
				'built_in' => $built,
				'uploaded' => isset( $uploaded[ $code ] ) ? count( (array) $uploaded[ $code ] ) : 0,
			);
		}
		return $out;
	}

	public static function filter_gettext( $translation, $text, $domain ) {
		if ( isset( self::$bundle[ $text ] ) && '' !== self::$bundle[ $text ] ) {
			return self::$bundle[ $text ];
		}
		return $translation;
	}

	public static function filter_gettext_context( $translation, $text, $context, $domain ) {
		return self::filter_gettext( $translation, $text, $domain );
	}

	public static function filter_ngettext( $translation, $single, $plural, $number, $domain ) {
		$key = 1 === (int) $number ? $single : $plural;
		if ( isset( self::$bundle[ $key ] ) && '' !== self::$bundle[ $key ] ) {
			return self::$bundle[ $key ];
		}
		return $translation;
	}

	/**
	 * Translate one English source string (bundle first, then online provider).
	 */
	public static function t( $text, $lang = null ) {
		$lang = $lang ? self::normalize( $lang ) : self::current();
		if ( 'en' === $lang ) {
			return $text;
		}
		$bundle = $lang === self::$current ? self::$bundle : self::bundle( $lang );
		if ( isset( $bundle[ $text ] ) && '' !== $bundle[ $text ] ) {
			return $bundle[ $text ];
		}
		$online = self::translate_texts( array( $text ), $lang );
		return is_array( $online ) && isset( $online[0] ) ? $online[0] : $text;
	}

	/* ------------------------------------------------------------------ */
	/* Online translation providers                                         */
	/* ------------------------------------------------------------------ */

	public static function online_available() {
		$provider = PCT_Settings::get( 'translation_provider', 'none' );
		if ( 'none' === $provider ) {
			return false;
		}
		if ( 'libre' === $provider ) {
			return '' !== (string) PCT_Settings::get( 'translation_endpoint' );
		}
		if ( 'custom' === $provider ) {
			return '' !== (string) PCT_Settings::get( 'translation_endpoint' );
		}
		return PCT_Settings::has_secret( 'translation_api_key' );
	}

	/**
	 * Translate texts via the configured provider. Results are cached per language.
	 *
	 * @return array|WP_Error Translated strings in input order.
	 */
	public static function translate_texts( array $texts, $target, $source = 'en' ) {
		$target = self::normalize( $target );
		if ( $target === $source ) {
			return $texts;
		}
		if ( ! self::online_available() || self::$translating ) {
			return new WP_Error( 'pct_translate_disabled', __( 'Online translation is not configured.', 'personalized-city-tour' ) );
		}
		$texts = array_values( array_map( 'strval', $texts ) );
		$cache = get_option( self::CACHE_OPT, array() );
		$out   = array();
		$todo  = array();
		foreach ( $texts as $i => $text ) {
			if ( 'en' === $source && isset( $cache[ $target ][ $text ] ) ) {
				$out[ $i ] = $cache[ $target ][ $text ];
			} else {
				$todo[ $i ] = $text;
			}
		}
		if ( $todo ) {
			self::$translating = true;
			$result            = self::call_provider( array_values( $todo ), $target, $source );
			self::$translating = false;
			if ( is_wp_error( $result ) ) {
				return $result;
			}
			$keys = array_keys( $todo );
			foreach ( $result as $j => $translated ) {
				$i         = $keys[ $j ];
				$out[ $i ] = $translated;
				if ( 'en' === $source && strlen( $texts[ $i ] ) < 400 ) {
					$cache[ $target ][ $texts[ $i ] ] = $translated;
				}
			}
			if ( 'en' === $source ) {
				if ( isset( $cache[ $target ] ) && count( $cache[ $target ] ) > 3000 ) {
					$cache[ $target ] = array_slice( $cache[ $target ], -3000, null, true );
				}
				update_option( self::CACHE_OPT, $cache, false );
				wp_cache_delete( 'pct_bundle_' . $target . '_' . PCT_VERSION, 'pct' );
			}
		}
		ksort( $out );
		return array_values( $out );
	}

	private static function call_provider( array $texts, $target, $source ) {
		$provider = PCT_Settings::get( 'translation_provider', 'none' );
		$key      = PCT_Settings::secret( 'translation_api_key' );
		$endpoint = (string) PCT_Settings::get( 'translation_endpoint' );
		$target   = apply_filters( 'pct_translation_target_code', $target, $provider );

		switch ( $provider ) {
			case 'google':
				$url  = 'https://translation.googleapis.com/language/translate/v2?key=' . rawurlencode( $key );
				$body = array( 'q' => $texts, 'target' => $target, 'source' => $source, 'format' => 'text' );
				$res  = wp_remote_post( $url, array( 'timeout' => 20, 'headers' => array( 'Content-Type' => 'application/json' ), 'body' => wp_json_encode( $body ) ) );
				$path = array( 'data', 'translations' );
				$field = 'translatedText';
				break;
			case 'deepl':
				$url  = $endpoint ? $endpoint : ( 0 === strpos( $key, 'free' ) || substr( $key, -3 ) === ':fx' ? 'https://api-free.deepl.com/v2/translate' : 'https://api.deepl.com/v2/translate' );
				$body = array( 'text' => $texts, 'target_lang' => strtoupper( 'zh' === $target ? 'ZH' : $target ), 'source_lang' => strtoupper( $source ) );
				$res  = wp_remote_post( $url, array( 'timeout' => 20, 'headers' => array( 'Content-Type' => 'application/json', 'Authorization' => 'DeepL-Auth-Key ' . $key ), 'body' => wp_json_encode( $body ) ) );
				$path = array( 'translations' );
				$field = 'text';
				break;
			case 'libre':
				$url  = untrailingslashit( $endpoint ) . '/translate';
				$body = array( 'q' => $texts, 'source' => $source, 'target' => $target, 'format' => 'text' );
				if ( $key ) {
					$body['api_key'] = $key;
				}
				$res  = wp_remote_post( $url, array( 'timeout' => 20, 'headers' => array( 'Content-Type' => 'application/json' ), 'body' => wp_json_encode( $body ) ) );
				$path = array( 'translatedText' );
				$field = '';
				break;
			case 'custom':
			default:
				// Expected contract: POST {texts:[], source, target} → {translations:["..."]}.
				$res  = wp_remote_post( $endpoint, array( 'timeout' => 20, 'headers' => array( 'Content-Type' => 'application/json', 'Authorization' => 'Bearer ' . $key ), 'body' => wp_json_encode( array( 'texts' => $texts, 'source' => $source, 'target' => $target ) ) ) );
				$path = array( 'translations' );
				$field = '';
		}

		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$code = wp_remote_retrieve_response_code( $res );
		$data = json_decode( wp_remote_retrieve_body( $res ), true );
		if ( $code < 200 || $code >= 300 || ! is_array( $data ) ) {
			/* translators: %s: HTTP status */
			return new WP_Error( 'pct_translate_http', sprintf( __( 'Translation provider error (%s).', 'personalized-city-tour' ), $code ) );
		}
		$node = $data;
		foreach ( $path as $p ) {
			$node = isset( $node[ $p ] ) ? $node[ $p ] : null;
		}
		if ( ! is_array( $node ) ) {
			return new WP_Error( 'pct_translate_shape', __( 'Unexpected response from translation provider.', 'personalized-city-tour' ) );
		}
		$out = array();
		foreach ( $texts as $i => $text ) {
			$item    = isset( $node[ $i ] ) ? $node[ $i ] : $text;
			$out[ $i ] = $field && is_array( $item ) ? (string) ( $item[ $field ] ?? $text ) : (string) ( is_array( $item ) ? $text : $item );
		}
		return $out;
	}

	/* ------------------------------------------------------------------ */
	/* Admin: bundle upload / admin language                                */
	/* ------------------------------------------------------------------ */

	public static function handle_upload() {
		check_admin_referer( 'pct_upload_bundle' );
		if ( ! current_user_can( 'pct_manage' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'personalized-city-tour' ) );
		}
		$lang = self::normalize( $_POST['lang'] ?? '' );
		$msg  = 'bundle_error';
		if ( ! empty( $_FILES['bundle']['tmp_name'] ) && is_uploaded_file( $_FILES['bundle']['tmp_name'] ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			$raw   = (string) file_get_contents( $_FILES['bundle']['tmp_name'] ); // phpcs:ignore
			$name  = (string) ( $_FILES['bundle']['name'] ?? '' ); // phpcs:ignore
			$pairs = substr( $name, -3 ) === '.po' ? self::parse_po( $raw ) : json_decode( $raw, true );
			if ( is_array( $pairs ) ) {
				$clean = array();
				foreach ( $pairs as $src => $dst ) {
					if ( is_string( $src ) && is_string( $dst ) && '' !== $dst && strlen( $src ) < 1000 ) {
						$clean[ $src ] = wp_kses( $dst, array( 'strong' => array(), 'em' => array(), 'br' => array() ) );
					}
				}
				$all          = get_option( self::BUNDLES_OPT, array() );
				$all[ $lang ] = array_merge( (array) ( $all[ $lang ] ?? array() ), $clean );
				update_option( self::BUNDLES_OPT, $all, false );
				wp_cache_delete( 'pct_bundle_' . $lang . '_' . PCT_VERSION, 'pct' );
				$msg = 'bundle_saved';
			}
		}
		wp_safe_redirect( add_query_arg( array( 'page' => 'pct-settings', 'tab' => 'languages', 'pct_msg' => $msg ), admin_url( 'admin.php' ) ) );
		exit;
	}

	public static function handle_delete() {
		check_admin_referer( 'pct_delete_bundle' );
		if ( ! current_user_can( 'pct_manage' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'personalized-city-tour' ) );
		}
		$lang = self::normalize( $_POST['lang'] ?? '' );
		$all  = get_option( self::BUNDLES_OPT, array() );
		unset( $all[ $lang ] );
		update_option( self::BUNDLES_OPT, $all, false );
		wp_cache_delete( 'pct_bundle_' . $lang . '_' . PCT_VERSION, 'pct' );
		wp_safe_redirect( add_query_arg( array( 'page' => 'pct-settings', 'tab' => 'languages', 'pct_msg' => 'bundle_deleted' ), admin_url( 'admin.php' ) ) );
		exit;
	}

	public static function handle_admin_lang() {
		check_admin_referer( 'pct_admin_lang' );
		$lang = sanitize_key( $_POST['lang'] ?? '' );
		if ( isset( self::languages()[ $lang ] ) ) {
			update_user_meta( get_current_user_id(), 'pct_admin_lang', $lang );
		} else {
			delete_user_meta( get_current_user_id(), 'pct_admin_lang' );
		}
		wp_safe_redirect( wp_get_referer() ? wp_get_referer() : admin_url( 'admin.php?page=pct-dashboard' ) );
		exit;
	}

	/**
	 * Very small .po parser (msgid/msgstr pairs, multi-line strings).
	 */
	public static function parse_po( $raw ) {
		$pairs = array();
		$id    = null;
		$str   = null;
		$mode  = '';
		$unq   = function ( $s ) {
			return stripcslashes( substr( trim( $s ), 1, -1 ) );
		};
		foreach ( preg_split( '/\r?\n/', $raw ) as $line ) {
			$line = trim( $line );
			if ( 0 === strpos( $line, 'msgid ' ) ) {
				if ( null !== $id && null !== $str && '' !== $id ) {
					$pairs[ $id ] = $str;
				}
				$id   = $unq( substr( $line, 6 ) );
				$str  = null;
				$mode = 'id';
			} elseif ( 0 === strpos( $line, 'msgstr ' ) ) {
				$str  = $unq( substr( $line, 7 ) );
				$mode = 'str';
			} elseif ( '' !== $line && '"' === $line[0] ) {
				if ( 'id' === $mode ) {
					$id .= $unq( $line );
				} elseif ( 'str' === $mode ) {
					$str .= $unq( $line );
				}
			}
		}
		if ( null !== $id && null !== $str && '' !== $id ) {
			$pairs[ $id ] = $str;
		}
		return $pairs;
	}

	/* ------------------------------------------------------------------ */
	/* Dates (Jalali / Gregorian)                                            */
	/* ------------------------------------------------------------------ */

	/**
	 * Format a timestamp as a date in the calendar of the language.
	 */
	public static function format_date( $timestamp, $lang = null, $with_time = false ) {
		$lang = $lang ? self::normalize( $lang ) : self::current();
		$time = $with_time ? ' ' . gmdate( 'H:i', $timestamp ) : '';
		if ( 'jalali' === self::calendar_for( $lang ) ) {
			list( $y, $m, $d ) = self::to_jalali( (int) gmdate( 'Y', $timestamp ), (int) gmdate( 'n', $timestamp ), (int) gmdate( 'j', $timestamp ) );
			return sprintf( '%04d/%02d/%02d', $y, $m, $d ) . $time;
		}
		return gmdate( 'Y-m-d', $timestamp ) . $time;
	}

	/**
	 * Gregorian → Jalali (algorithm by Kazimierz M. Borkowski / jalaali-js).
	 */
	public static function to_jalali( $gy, $gm, $gd ) {
		$g_d_m = array( 0, 31, 59, 90, 120, 151, 181, 212, 243, 273, 304, 334 );
		$gy2   = ( $gm > 2 ) ? ( $gy + 1 ) : $gy;
		$days  = 355666 + ( 365 * $gy ) + intdiv( $gy2 + 3, 4 ) - intdiv( $gy2 + 99, 100 ) + intdiv( $gy2 + 399, 400 ) + $gd + $g_d_m[ $gm - 1 ];
		$jy    = -1595 + ( 33 * intdiv( $days, 12053 ) );
		$days %= 12053;
		$jy   += 4 * intdiv( $days, 1461 );
		$days %= 1461;
		if ( $days > 365 ) {
			$jy   += intdiv( $days - 1, 365 );
			$days  = ( $days - 1 ) % 365;
		}
		if ( $days < 186 ) {
			$jm = 1 + intdiv( $days, 31 );
			$jd = 1 + ( $days % 31 );
		} else {
			$jm = 7 + intdiv( $days - 186, 30 );
			$jd = 1 + ( ( $days - 186 ) % 30 );
		}
		return array( $jy, $jm, $jd );
	}

	/**
	 * Jalali → Gregorian.
	 */
	public static function to_gregorian( $jy, $jm, $jd ) {
		$jy   += 1595;
		$days  = -355668 + ( 365 * $jy ) + ( intdiv( $jy, 33 ) * 8 ) + intdiv( ( $jy % 33 ) + 3, 4 ) + $jd + ( ( $jm < 7 ) ? ( $jm - 1 ) * 31 : ( ( $jm - 7 ) * 30 ) + 186 );
		$gy    = 400 * intdiv( $days, 146097 );
		$days %= 146097;
		if ( $days > 36524 ) {
			$gy   += 100 * intdiv( --$days, 36524 );
			$days %= 36524;
			if ( $days >= 365 ) {
				$days++;
			}
		}
		$gy   += 4 * intdiv( $days, 1461 );
		$days %= 1461;
		if ( $days > 365 ) {
			$gy   += intdiv( $days - 1, 365 );
			$days  = ( $days - 1 ) % 365;
		}
		$gd   = $days + 1;
		$sal_a = array( 0, 31, ( ( $gy % 4 === 0 && $gy % 100 !== 0 ) || ( $gy % 400 === 0 ) ) ? 29 : 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31 );
		for ( $gm = 0; $gm < 13 && $gd > $sal_a[ $gm ]; $gm++ ) {
			$gd -= $sal_a[ $gm ];
		}
		return array( $gy, $gm, $gd );
	}

	/**
	 * Parse a user-entered date in either calendar to Y-m-d (Gregorian).
	 * Accepts 1404/06/30, 1404-06-30 (Jalali when year < 1700) or 2025-09-21.
	 */
	public static function parse_date( $value ) {
		$value = trim( (string) $value );
		$value = strtr( $value, array( '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9' ) );
		if ( ! preg_match( '/^(\d{4})[\/-](\d{1,2})[\/-](\d{1,2})$/', $value, $m ) ) {
			return '';
		}
		$y = (int) $m[1];
		$mo = (int) $m[2];
		$d = (int) $m[3];
		if ( $y < 1700 ) {
			if ( $mo < 1 || $mo > 12 || $d < 1 || $d > 31 ) {
				return '';
			}
			list( $y, $mo, $d ) = self::to_gregorian( $y, $mo, $d );
		}
		if ( ! checkdate( $mo, $d, $y ) ) {
			return '';
		}
		return sprintf( '%04d-%02d-%02d', $y, $mo, $d );
	}

	/**
	 * Client configuration for the frontend / admin scripts.
	 */
	public static function client_config() {
		$langs = array();
		foreach ( self::enabled_languages() as $code => $meta ) {
			$langs[] = array(
				'code'     => $code,
				'label'    => $meta['label'],
				'rtl'      => $meta['rtl'],
				'calendar' => self::calendar_for( $code ),
			);
		}
		return array(
			'current'   => self::current(),
			'rtl'       => self::is_rtl(),
			'calendar'  => self::calendar_for( self::current() ),
			'languages' => $langs,
			'online'    => self::online_available(),
		);
	}
}
