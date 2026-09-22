<?php
/**
 * Audit log for AI requests/responses (table: {prefix}pct_ai_log).
 *
 * Logging level (settings: ai_log_level):
 *   none    – nothing is stored
 *   minimal – metadata only (provider, model, status, duration, error)
 *   full    – metadata + request and response bodies (PII redacted when enabled)
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PCT_AI_Log {

	const CRON_HOOK = 'pct_ai_log_purge';

	public static function init() {
		add_action( self::CRON_HOOK, array( __CLASS__, 'cron_purge' ) );
	}

	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'pct_ai_log';
	}

	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $wpdb->get_charset_collate();
		$table   = self::table();
		dbDelta(
			"CREATE TABLE {$table} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				created datetime NOT NULL,
				kind varchar(32) NOT NULL DEFAULT 'tour',
				provider varchar(32) NOT NULL DEFAULT '',
				model varchar(120) NOT NULL DEFAULT '',
				status varchar(16) NOT NULL DEFAULT 'ok',
				duration_ms int unsigned NOT NULL DEFAULT 0,
				attempt tinyint unsigned NOT NULL DEFAULT 1,
				user_id bigint(20) unsigned NOT NULL DEFAULT 0,
				ip_hash char(32) NOT NULL DEFAULT '',
				title varchar(255) NOT NULL DEFAULT '',
				request longtext NULL,
				response longtext NULL,
				error text NULL,
				PRIMARY KEY  (id),
				KEY created (created),
				KEY kind_status (kind, status)
			) {$charset};"
		);
	}

	public static function level() {
		$level = (string) PCT_Settings::get( 'ai_log_level', 'minimal' );
		return in_array( $level, array( 'none', 'minimal', 'full' ), true ) ? $level : 'minimal';
	}

	/**
	 * @param array $entry kind, provider, model, status, duration_ms, attempt, title, request, response, error.
	 * @return int Inserted id (0 when disabled).
	 */
	public static function log( array $entry ) {
		global $wpdb;
		$level = self::level();
		if ( 'none' === $level && empty( $entry['force'] ) ) {
			return 0;
		}
		$redact = (bool) PCT_Settings::get( 'ai_redact_pii', 1 );
		$row    = array(
			'created'     => current_time( 'mysql' ),
			'kind'        => substr( sanitize_key( $entry['kind'] ?? 'tour' ), 0, 32 ),
			'provider'    => substr( sanitize_key( $entry['provider'] ?? '' ), 0, 32 ),
			'model'       => substr( sanitize_text_field( $entry['model'] ?? '' ), 0, 120 ),
			'status'      => substr( sanitize_key( $entry['status'] ?? 'ok' ), 0, 16 ),
			'duration_ms' => (int) ( $entry['duration_ms'] ?? 0 ),
			'attempt'     => (int) ( $entry['attempt'] ?? 1 ),
			'user_id'     => get_current_user_id(),
			'ip_hash'     => md5( ( isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '' ) . wp_salt() ),
			'title'       => substr( $redact ? self::redact( (string) ( $entry['title'] ?? '' ) ) : (string) ( $entry['title'] ?? '' ), 0, 255 ),
			'error'       => isset( $entry['error'] ) ? substr( (string) $entry['error'], 0, 5000 ) : null,
			'request'     => null,
			'response'    => null,
		);
		if ( 'full' === $level ) {
			$req = isset( $entry['request'] ) ? ( is_string( $entry['request'] ) ? $entry['request'] : wp_json_encode( $entry['request'], JSON_UNESCAPED_UNICODE ) ) : '';
			$res = isset( $entry['response'] ) ? ( is_string( $entry['response'] ) ? $entry['response'] : wp_json_encode( $entry['response'], JSON_UNESCAPED_UNICODE ) ) : '';
			$row['request']  = $redact ? self::redact( $req ) : $req;
			$row['response'] = $redact ? self::redact( $res ) : $res;
		}
		$wpdb->insert( self::table(), $row ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return (int) $wpdb->insert_id;
	}

	/**
	 * Redact e-mail addresses, phone numbers and API keys from a string.
	 */
	public static function redact( $text ) {
		$text = (string) $text;
		$text = preg_replace( '/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i', '[email]', $text );
		$text = preg_replace( '/(?<![\w\/])\+?\d[\d\s().-]{7,}\d(?!\w)/', '[phone]', $text );
		$text = preg_replace( '/(sk-[A-Za-z0-9_-]{8,}|Bearer\s+[A-Za-z0-9._-]{8,})/', '[secret]', $text );
		return $text;
	}

	public static function query( array $args = array() ) {
		global $wpdb;
		$table  = self::table();
		$where  = array( '1=1' );
		$params = array();
		if ( ! empty( $args['kind'] ) ) {
			$where[]  = 'kind = %s';
			$params[] = sanitize_key( $args['kind'] );
		}
		if ( ! empty( $args['status'] ) ) {
			$where[]  = 'status = %s';
			$params[] = sanitize_key( $args['status'] );
		}
		$per_page = max( 1, min( 200, (int) ( $args['per_page'] ?? 25 ) ) );
		$page     = max( 1, (int) ( $args['page'] ?? 1 ) );
		$offset   = ( $page - 1 ) * $per_page;
		$sql      = "SELECT * FROM {$table} WHERE " . implode( ' AND ', $where ) . ' ORDER BY id DESC LIMIT %d OFFSET %d';
		$params[] = $per_page;
		$params[] = $offset;
		$rows     = $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A ); // phpcs:ignore WordPress.DB
		$count    = (int) $wpdb->get_var( $params ? $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE " . implode( ' AND ', $where ), array_slice( $params, 0, -2 ) ) : "SELECT COUNT(*) FROM {$table}" ); // phpcs:ignore WordPress.DB
		return array(
			'rows'  => $rows ? $rows : array(),
			'total' => $count,
			'pages' => (int) ceil( $count / $per_page ),
		);
	}

	public static function get( $id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE id = %d', (int) $id ), ARRAY_A ); // phpcs:ignore WordPress.DB
	}

	public static function purge( $days = 0 ) {
		global $wpdb;
		if ( $days > 0 ) {
			return (int) $wpdb->query( $wpdb->prepare( 'DELETE FROM ' . self::table() . ' WHERE created < %s', gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS ) ) ); // phpcs:ignore WordPress.DB
		}
		return (int) $wpdb->query( 'TRUNCATE TABLE ' . self::table() ); // phpcs:ignore WordPress.DB
	}

	public static function stats( $days = 30 ) {
		global $wpdb;
		$since = gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );
		$rows  = $wpdb->get_results( $wpdb->prepare( 'SELECT status, COUNT(*) AS n, AVG(duration_ms) AS avg_ms FROM ' . self::table() . " WHERE created >= %s AND kind <> 'audit' GROUP BY status", $since ), ARRAY_A ); // phpcs:ignore WordPress.DB
		$out   = array( 'ok' => 0, 'error' => 0, 'invalid' => 0, 'avg_ms' => 0 );
		$total = 0;
		$sum   = 0;
		foreach ( (array) $rows as $r ) {
			$out[ $r['status'] ] = (int) $r['n'];
			$total              += (int) $r['n'];
			$sum                += (float) $r['avg_ms'] * (int) $r['n'];
		}
		$out['avg_ms'] = $total ? (int) round( $sum / $total ) : 0;
		$out['total']  = $total;
		return $out;
	}

	/**
	 * Daily retention job.
	 */
	public static function cron_purge() {
		$days = (int) PCT_Settings::get( 'ai_log_retention_days', 30 );
		if ( $days > 0 ) {
			self::purge( $days );
		}
	}
}
