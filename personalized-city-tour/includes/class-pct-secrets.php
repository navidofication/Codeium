<?php
/**
 * Encrypted storage for API keys and tokens kept in wp_options.
 *
 * Values are encrypted with AES-256-GCM using a key derived from the
 * PCT_SECRET_KEY constant (preferred) or the site's AUTH_KEY/SECURE_AUTH_KEY
 * salts. Legacy plain values are read transparently and re-encrypted on save.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PCT_Secrets {

	const PREFIX = 'pct1:';
	const CIPHER = 'aes-256-gcm';

	public static function available() {
		return function_exists( 'openssl_encrypt' ) && in_array( self::CIPHER, openssl_get_cipher_methods(), true );
	}

	private static function key() {
		if ( defined( 'PCT_SECRET_KEY' ) && PCT_SECRET_KEY ) {
			$material = PCT_SECRET_KEY;
		} else {
			$material = ( defined( 'AUTH_KEY' ) ? AUTH_KEY : '' ) . ( defined( 'SECURE_AUTH_KEY' ) ? SECURE_AUTH_KEY : '' );
			if ( '' === $material ) {
				$material = wp_salt( 'auth' );
			}
		}
		return hash( 'sha256', 'pct-secrets|' . $material, true );
	}

	public static function encrypt( $plain ) {
		$plain = (string) $plain;
		if ( '' === $plain ) {
			return '';
		}
		if ( ! self::available() ) {
			return $plain;
		}
		$iv  = random_bytes( 12 );
		$tag = '';
		$ct  = openssl_encrypt( $plain, self::CIPHER, self::key(), OPENSSL_RAW_DATA, $iv, $tag, '', 16 );
		if ( false === $ct ) {
			return $plain;
		}
		return self::PREFIX . base64_encode( $iv . $tag . $ct ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}

	public static function decrypt( $stored ) {
		$stored = (string) $stored;
		if ( '' === $stored || 0 !== strpos( $stored, self::PREFIX ) ) {
			return $stored;
		}
		if ( ! self::available() ) {
			return '';
		}
		$raw = base64_decode( substr( $stored, strlen( self::PREFIX ) ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		if ( false === $raw || strlen( $raw ) < 29 ) {
			return '';
		}
		$iv  = substr( $raw, 0, 12 );
		$tag = substr( $raw, 12, 16 );
		$ct  = substr( $raw, 28 );
		$out = openssl_decrypt( $ct, self::CIPHER, self::key(), OPENSSL_RAW_DATA, $iv, $tag );
		return false === $out ? '' : $out;
	}

	public static function is_encrypted( $stored ) {
		return 0 === strpos( (string) $stored, self::PREFIX );
	}

	/**
	 * Masked hint for the UI: never reveals more than the last 4 characters.
	 */
	public static function mask( $plain ) {
		$plain = (string) $plain;
		if ( '' === $plain ) {
			return '';
		}
		return str_repeat( '•', 8 ) . substr( $plain, -4 );
	}
}
