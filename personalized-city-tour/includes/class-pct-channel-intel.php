<?php
/**
 * Read-only enrichment from public City Channels.
 *
 * This class never logs in, bypasses access controls, or scrapes private content.
 * It fetches only URLs explicitly configured by the administrator, caches the
 * result, extracts public structured event data and (where possible) public
 * Telegram post text, then turns high-confidence items into City-Data-like
 * virtual candidates for the Tour Generator.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class PCT_Channel_Intel {
	const CACHE_TTL = 6 * HOUR_IN_SECONDS;
	const MAX_URLS = 8;
	const MAX_CANDIDATES = 40;
	private static $geo_count = 0;
	private static $last_report = array();
	private static $source_contexts = array();
	private static $link_fetch_count = 0;

	private static function rejection_reasons() {
		return array(
			'no_coordinates'          => 0,
			'wrong_city'              => 0,
			'expired_event'           => 0,
			'outside_date_window'     => 0,
			'low_confidence'          => 0,
			'duplicate'               => 0,
			'unsupported_source'      => 0,
			'invalid_url'             => 0,
			'not_selected_by_planner' => 0,
			'route_provider_failed'   => 0,
			'missing_date'            => 0,
			'normalization'           => 0,
		);
	}

	private static function reject_candidate( $source_key, $reason ) {
		if ( ! isset( self::$last_report['sources'][ $source_key ] ) ) {
			return;
		}
		self::$last_report['sources'][ $source_key ]['rejected_candidates']++;
		if ( ! isset( self::$last_report['sources'][ $source_key ]['rejection_reasons'][ $reason ] ) ) {
			self::$last_report['sources'][ $source_key ]['rejection_reasons'][ $reason ] = 0;
		}
		self::$last_report['sources'][ $source_key ]['rejection_reasons'][ $reason ]++;
	}

	private static function is_public_url( $url ) {
		$url = esc_url_raw( (string) $url );
		if ( ! $url || ! wp_http_validate_url( $url ) ) {
			return false;
		}
		$parts = wp_parse_url( $url );
		$scheme = strtolower( (string) ( $parts['scheme'] ?? '' ) );
		$host = strtolower( trim( (string) ( $parts['host'] ?? '' ), " \t\n\r\0\x0B." ) );
		if ( ! in_array( $scheme, array( 'http', 'https' ), true ) || '' === $host ) {
			return false;
		}
		if ( in_array( $host, array( 'localhost', 'localhost.localdomain' ), true ) || preg_match( '/(^|\.)local$/', $host ) ) {
			return false;
		}
		$ip = filter_var( $host, FILTER_VALIDATE_IP );
		if ( $ip && ! filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
			return false;
		}
		return true;
	}

	public static function enabled() {
		return (bool) PCT_Settings::get( 'channel_intel_enabled', 1 );
	}

	public static function candidates( array $city, array $form = array(), array $opts = array() ) {
		self::$geo_count = 0;
		self::$last_report = array( 'sources' => array(), 'total_candidates' => 0, 'accepted_candidates' => 0, 'route_ready_candidates' => 0, 'rejected_candidates' => 0, 'locationless_accepted_candidates' => 0 );
		self::$source_contexts = array();
		self::$link_fetch_count = 0;
		if ( ! self::enabled() || empty( $city['channels'] ) ) { return array(); }
		$cached_only = ! empty( $opts['cached_only'] );
		list( $from_date, $to_date ) = self::date_window_for_form( $form );
		$from = self::ymd_ts( $from_date );
		$to   = self::ymd_ts( $to_date );
		$existing = array_merge( PCT_Data::places( $city['id'] ), PCT_Data::events( $city['id'], '', '' ) );
		$out = array();
		$urls = array_slice( (array) $city['channels'], 0, self::MAX_URLS );
		foreach ( $urls as $channel ) {
			$url = esc_url_raw( (string) ( $channel['url'] ?? '' ) );
			$label = sanitize_text_field( (string) ( $channel['label'] ?? '' ) );
			$source_key = md5( $url ?: $label );
			if ( ! self::is_public_url( $url ) ) {
				self::$last_report['sources'][ $source_key ] = array(
					'label' => $label,
					'url' => $url,
					'fetched' => false,
					'cache_hit' => false,
					'http_code' => 0,
					'fetch_url' => $url,
					'raw_candidates' => 0,
					'accepted_candidates' => 0,
					'locationless_accepted_candidates' => 0,
					'route_ready_candidates' => 0,
					'rejected_candidates' => 1,
					'rejection_reasons' => array_merge( self::rejection_reasons(), array( 'invalid_url' => 1 ) ),
					'error' => 'invalid_url',
				);
				continue;
			}
			$extract = self::extract_url( $url, $city, ! $cached_only );
			$raw_candidates = (array) ( $extract['candidates'] ?? $extract );
			$source_info = array(
				'label' => $label,
				'url' => $url,
				'fetched' => ! empty( $extract['fetched'] ),
				'cache_hit' => ! empty( $extract['cache_hit'] ),
				'http_code' => (int) ( $extract['http_code'] ?? 0 ),
				'fetch_url' => esc_url_raw( (string) ( $extract['fetch_url'] ?? $url ) ),
				'raw_candidates' => count( $raw_candidates ),
				'accepted_candidates' => 0,
				'locationless_accepted_candidates' => 0,
				'route_ready_candidates' => 0,
				'rejected_candidates' => 0,
				'rejection_reasons' => self::rejection_reasons(),
				'error' => sanitize_text_field( (string) ( $extract['error'] ?? '' ) ),
			);
			self::$last_report['sources'][ $source_key ] = $source_info;
			if ( ! empty( $extract['context'] ) ) {
				self::$source_contexts[ $source_key ] = array( 'label' => $label, 'url' => $url, 'text' => self::safe_source_context( $extract['context'] ) );
			}
			foreach ( $raw_candidates as $candidate ) {
				$candidate['channel_label'] = $label;
				$candidate['channel_configured_url'] = $url;
				$candidate = self::enrich_from_direct_link( $candidate, $city );
				$candidate = self::finalize_candidate( $candidate, $city, $existing );
				if ( ! $candidate ) {
					self::reject_candidate( $source_key, 'normalization' );
					continue;
				}
				if ( 'event' === $candidate['type'] ) {
					$start_ts = ! empty( $candidate['start'] ) ? strtotime( $candidate['start'] ) : false;
					$end_ts = ! empty( $candidate['end'] ) ? strtotime( $candidate['end'] ) : $start_ts;
					if ( ! $start_ts ) {
						self::reject_candidate( $source_key, 'missing_date' );
						continue;
					}
					if ( $end_ts && $end_ts < current_time( 'timestamp' ) ) {
						self::reject_candidate( $source_key, 'expired_event' );
						continue;
					}
					if ( ( $end_ts && $end_ts < $from ) || $start_ts > $to + DAY_IN_SECONDS ) {
						self::reject_candidate( $source_key, 'outside_date_window' );
						continue;
					}
				}
				if ( isset( $out[ (string) $candidate['id'] ] ) ) {
					self::reject_candidate( $source_key, 'duplicate' );
					continue;
				}
				$out[ (string) $candidate['id'] ] = $candidate;
				self::$last_report['sources'][ $source_key ]['accepted_candidates']++;
				if ( ! empty( $candidate['lat'] ) && ! empty( $candidate['lng'] ) ) {
					self::$last_report['sources'][ $source_key ]['route_ready_candidates']++;
				} else {
					self::$last_report['sources'][ $source_key ]['locationless_accepted_candidates']++;
				}
				if ( count( $out ) >= self::MAX_CANDIDATES ) { break 2; }
			}
		}
		self::$last_report['total_candidates'] = array_sum( array_map( function( $r ) { return (int) $r['raw_candidates']; }, self::$last_report['sources'] ) );
		self::$last_report['accepted_candidates'] = count( $out );
		self::$last_report['locationless_accepted_candidates'] = array_sum( array_map( function( $r ) { return (int) ( $r['locationless_accepted_candidates'] ?? 0 ); }, self::$last_report['sources'] ) );
		self::$last_report['route_ready_candidates'] = array_sum( array_map( function( $r ) { return (int) ( $r['route_ready_candidates'] ?? 0 ); }, self::$last_report['sources'] ) );
		self::$last_report['rejected_candidates'] = array_sum( array_map( function( $r ) { return (int) ( $r['rejected_candidates'] ?? 0 ); }, self::$last_report['sources'] ) );
		return array_values( $out );
	}


	public static function last_report() {
		return is_array( self::$last_report ) ? self::$last_report : array();
	}

	public static function source_contexts() {
		return is_array( self::$source_contexts ) ? array_values( self::$source_contexts ) : array();
	}

	/**
	 * Normalize AI/web-search discoveries into the exact same City-Data-like
	 * virtual stop shape used by public City Channel extraction. The AI may
	 * discover a direct event page (for example an Evand event URL) while
	 * searching a configured channel; the plugin, not the model, assigns the
	 * stable item_id, coordinates, links and safety metadata.
	 */
	public static function normalize_ai_candidates( array $rows, array $city, array $form = array() ) {
		if ( ! self::enabled() || empty( $rows ) ) { return array(); }
		$existing = array_merge( PCT_Data::places( $city['id'] ), PCT_Data::events( $city['id'], '', '' ) );
		list( $from, $to ) = self::date_window_for_form( $form );
		$out = array();
		foreach ( array_slice( $rows, 0, 12 ) as $row ) {
			if ( ! is_array( $row ) ) { continue; }
			$source = esc_url_raw( (string) ( $row['source_url'] ?? '' ) );
			$name = sanitize_text_field( (string) ( $row['name'] ?? '' ) );
			if ( ! $source || ! $name || ! self::source_allowed( $source, (array) $city['channels'] ) ) { continue; }
			$confidence = (float) ( $row['confidence'] ?? 0 );
			if ( $confidence < 0.65 ) { continue; }
			$type = 'event' === (string) ( $row['type'] ?? '' ) ? 'event' : 'place';
			$start = self::iso_datetime( $row['start'] ?? '' );
			$end   = self::iso_datetime( $row['end'] ?? '' );
			// Events must have a date, but a missing location is NOT a rejection.
			// A current, source-verified event can still be used as Serendipity.
			if ( 'event' === $type && ! $start ) { continue; }
			if ( 'event' === $type && $start ) {
				$ts = strtotime( $start ); $a = strtotime( $from . ' 00:00:00' ); $b = strtotime( $to . ' 23:59:59' );
				if ( $ts && $a && $b && ( $ts < $a || $ts > $b ) ) { continue; }
			}
			$c = array(
				'name' => $name,
				'summary' => wp_trim_words( sanitize_textarea_field( (string) ( $row['summary'] ?? '' ) ), 65, '…' ),
				'type' => $type,
				'address' => sanitize_text_field( (string) ( $row['venue'] ?? $row['address'] ?? '' ) ),
				'lat' => (float) ( $row['lat'] ?? 0 ),
				'lng' => (float) ( $row['lng'] ?? 0 ),
				'start' => $start,
				'end' => $end,
				'link' => esc_url_raw( (string) ( $row['link'] ?? $source ) ) ?: $source,
				'source_url' => $source,
				'source_kind' => 'city_channel_ai',
			);
			$c['categories'] = array_values( array_unique( array_filter( array_map( 'sanitize_key', (array) ( $row['categories'] ?? array() ) ) ) ) );
			if ( empty( $c['categories'] ) ) { $c['categories'] = self::categories( $c['name'] . ' ' . $c['summary'] ); }
			$c['tags'] = array_values( array_unique( array_filter( array_map( 'sanitize_text_field', (array) ( $row['tags'] ?? array() ) ) ) ) );
			$c['price_tier'] = in_array( (string) ( $row['price_tier'] ?? '' ), array( 'free', 'low', 'medium', 'high' ), true ) ? (string) $row['price_tier'] : '';
			$c['duration'] = max( 15, min( 360, absint( $row['duration'] ?? 60 ) ) );
			$c['accessibility'] = array(); $c['best_time'] = array(); $c['hours'] = ''; $c['family'] = false; $c['image'] = '';
			$c['organizer'] = sanitize_text_field( (string) ( $row['organizer'] ?? '' ) ); $c['organizer_url'] = '';
			$c['event_url'] = $c['link']; $c['venue'] = $c['address']; $c['socials'] = array();
			$matched = self::match_existing( $c, $existing );
			if ( $matched ) {
				$c['id'] = (int) $matched['id'];
				$c = array_merge( $matched, $c );
				$c['lat'] = (float) $matched['lat']; $c['lng'] = (float) $matched['lng'];
				if ( ! $c['address'] ) { $c['address'] = (string) $matched['address']; }
				$c['channel_matched_item_id'] = (int) $matched['id'];
			} else {
				// A current Event may be useful even when the public source does not
				// expose a reliable address/coordinate. Keep it as a validated
				// serendipity candidate instead of silently destroying it. It will
				// never become a map/routing stop until location is verified.
				if ( ! $c['lat'] || ! $c['lng'] ) {
					$geo_query = trim( (string) ( $c['address'] ?: $c['name'] ) . ', ' . $city['name'] );
					if ( $geo_query ) {
						$geo = self::geocode_public( $geo_query );
						if ( $geo ) { $c['lat'] = $geo['lat']; $c['lng'] = $geo['lng']; }
					}
				}
				if ( ! $c['lat'] || ! $c['lng'] ) {
					if ( 'event' !== $type ) { continue; }
					$c['location_status'] = 'needs_location_enrichment';
					$c['serendipity_eligible'] = true;
				} else {
					$c['location_status'] = 'verified';
				}
				$c['id'] = 800000000 + ( abs( crc32( $source . '|' . $name . '|' . $start ) ) % 100000000 );
			}
			$c['score'] = 10 + ( $confidence * 4 );
			$c['location_status'] = ( $c['lat'] && $c['lng'] ) ? 'verified' : 'needs_location_enrichment';
			$c['serendipity_eligible'] = ( 'event' === $type && 'verified' !== $c['location_status'] );
			$c['route_eligible'] = (bool) ( $c['lat'] && $c['lng'] );
			$source_host = (string) wp_parse_url( $source, PHP_URL_HOST );
			$c['candidate_id'] = 'channel_' . md5( $source . '|' . $name . '|' . $start );
			$c['source_name'] = sanitize_text_field( (string) ( $row['source_name'] ?? ( $source_host ?: __( 'Online city source', 'personalized-city-tour' ) ) ) );
			$c['observed_at'] = current_time( 'mysql' );
			$c['channel_virtual'] = true; $c['channel_ai_verified'] = true; $c['channel_source_url'] = $source;
			$c['channels'] = array( array( 'label' => __( 'Online city source', 'personalized-city-tour' ), 'url' => $source ) );
			$c['links'] = array(
				array( 'label' => __( 'Online source', 'personalized-city-tour' ), 'url' => $source, 'kind' => 'official' ),
				array( 'label' => __( 'Web search', 'personalized-city-tour' ), 'url' => 'https://www.google.com/search?q=' . rawurlencode( $name . ' ' . $city['name'] ), 'kind' => 'web' ),
				array( 'label' => __( 'Photo search', 'personalized-city-tour' ), 'url' => 'https://www.google.com/search?tbm=isch&q=' . rawurlencode( $name . ' ' . $city['name'] ), 'kind' => 'photos' ),
			);
			$out[(string) $c['id']] = $c;
		}
		return array_values( $out );
	}

	private static function source_allowed( $url, array $channels ) {
		if ( ! self::is_public_url( $url ) ) {
			return false;
		}
		$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		if ( ! $host ) { return false; }
		foreach ( $channels as $channel ) {
			$source_host = strtolower( (string) wp_parse_url( (string) ( $channel['url'] ?? '' ), PHP_URL_HOST ) );
			if ( $source_host && ( $host === $source_host || substr( $host, -strlen( '.' . $source_host ) ) === '.' . $source_host ) ) { return true; }
		}
		return false;
	}

	private static function date_window_for_form( array $form ) {
		$from_raw = ! empty( $form['date_start'] ) ? sanitize_text_field( $form['date_start'] ) : current_time( 'Y-m-d' );
		$to_raw   = ! empty( $form['date_end'] ) ? sanitize_text_field( $form['date_end'] ) : '';
		$from = class_exists( 'PCT_I18n' ) ? PCT_I18n::parse_date( $from_raw ) : '';
		if ( ! $from ) { $from = $from_raw; }
		$to = $to_raw && class_exists( 'PCT_I18n' ) ? PCT_I18n::parse_date( $to_raw ) : '';
		if ( ! $to ) { $to = $to_raw ?: gmdate( 'Y-m-d', current_time( 'timestamp' ) + 60 * DAY_IN_SECONDS ); }
		return array( $from, $to );
	}

	private static function extract_url( $url, array $city, $allow_network = true ) {
		$fetch_url = self::telegram_url( $url );
		// Versioned cache keys prevent a failed/empty result from an older parser
		// from poisoning later plugin versions for 30 minutes or more.
		$key = 'pct_channel_v220_' . md5( $fetch_url );
		$cached = get_transient( $key );
		if ( is_array( $cached ) && isset( $cached['candidates'] ) ) {
			$cached['cache_hit'] = true;
			$cached['fetched'] = false;
			$cached['fetch_url'] = $fetch_url;
			return $cached;
		}
		if ( ! $allow_network ) { return array( 'candidates' => array(), 'fetched' => false, 'cache_hit' => false, 'http_code' => 0, 'fetch_url' => $fetch_url, 'error' => 'network_disabled' ); }
		$response = wp_remote_get( $fetch_url, array(
			'timeout' => self::fetch_timeout( $fetch_url ),
			'redirection' => 4,
			'headers' => array( 'Accept' => 'text/html,application/xhtml+xml,application/ld+json,*/*', 'User-Agent' => 'Personalized-City-Tour/' . PCT_VERSION . ' (+public-source-enrichment)' ),
		) );
		$http_code = is_wp_error( $response ) ? 0 : (int) wp_remote_retrieve_response_code( $response );
		if ( is_wp_error( $response ) || 200 !== $http_code ) {
			return array( 'candidates' => array(), 'context' => '', 'fetched' => true, 'cache_hit' => false, 'http_code' => $http_code, 'fetch_url' => $fetch_url, 'error' => is_wp_error( $response ) ? $response->get_error_message() : 'http_' . $http_code );
		}
		$body = wp_remote_retrieve_body( $response );
		if ( ! $body || strlen( $body ) > 2 * 1024 * 1024 ) { return array( 'candidates' => array(), 'context' => '', 'fetched' => true, 'cache_hit' => false, 'http_code' => $http_code, 'fetch_url' => $fetch_url, 'error' => ! $body ? 'empty_body' : 'body_too_large' ); }
		$out = array_merge(
			self::evand_candidates( $body, $fetch_url, $city ),
			self::listing_candidates( $body, $fetch_url, $city ),
			self::jsonld_candidates( $body, $fetch_url ),
			self::telegram_candidates( $body, $fetch_url, $city ),
			self::social_public_candidates( $body, $fetch_url, $city ),
			self::generic_public_candidates( $body, $fetch_url, $city )
		);
		if ( empty( $out ) ) {
			$meta = self::meta_candidate( $body, $fetch_url, $city );
			if ( $meta ) { $out[] = $meta; }
		}
		$out = array_slice( $out, 0, 16 );
		$context = self::source_context( $body, $fetch_url );
		$pack = array( 'candidates' => $out, 'context' => $context, 'fetched' => true, 'cache_hit' => false, 'http_code' => $http_code, 'fetch_url' => $fetch_url, 'error' => '' );
		set_transient( $key, $pack, self::CACHE_TTL );
		return $pack;
	}

	private static function safe_source_context( $text ) {
		$text = self::clean_html_text( $text );
		$text = preg_replace( '/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/iu', '[email]', $text );
		$text = preg_replace( '/(?<![\w\/])\+?\d[\d\s().-]{7,}\d(?!\w)/u', '[phone]', $text );
		return mb_substr( trim( $text ), 0, 4500, 'UTF-8' );
	}

	private static function source_context( $body, $source ) {
		$parts = array();
		foreach ( array( 'og:title', 'og:description', 'twitter:title', 'twitter:description', 'description' ) as $key ) {
			$v = self::meta( $body, $key );
			if ( $v ) { $parts[] = $v; }
		}
		if ( class_exists( 'DOMDocument' ) ) {
			$dom = new DOMDocument(); $previous = libxml_use_internal_errors( true );
			@$dom->loadHTML( '<?xml encoding="UTF-8">' . $body ); libxml_clear_errors(); libxml_use_internal_errors( $previous );
			$xpath = new DOMXPath( $dom );
			$nodes = $xpath->query( '//main | //article | //body' );
			if ( $nodes && $nodes->length ) { $parts[] = self::clean_html_text( $nodes->item(0)->textContent ); }
		}
		return self::safe_source_context( implode( ' ', array_unique( $parts ) ) );
	}

	private static function telegram_url( $url ) {
		$p = wp_parse_url( $url );
		if ( ! empty( $p['host'] ) && false !== stripos( $p['host'], 't.me' ) ) {
			$path = trim( (string) ( $p['path'] ?? '' ), '/' );
			if ( $path && 0 !== strpos( $path, 's/' ) ) {
				$parts = explode( '/', $path );
				if ( ! empty( $parts[0] ) && $parts[0] !== 'joinchat' && $parts[0] !== '+' ) {
					return 'https://t.me/s/' . rawurlencode( $parts[0] );
				}
			}
		}
		return $url;
	}


	private static function fetch_timeout( $url ) {
		$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		if ( false !== strpos( $host, 'evand.com' ) ) { return 3; }
		if ( false !== strpos( $host, 't.me' ) ) { return 3; }
		return 2;
	}

	/**
	 * Evand city pages are public HTML listings. They often expose event links
	 * and human-readable date/time text but do not consistently expose JSON-LD.
	 * Promote those /events/ links into the same candidate shape used by all
	 * other City Channels. No login or account is required.
	 */
	private static function evand_candidates( $body, $source, array $city ) {
		$host = strtolower( (string) wp_parse_url( $source, PHP_URL_HOST ) );
		$path = (string) wp_parse_url( $source, PHP_URL_PATH );
		if ( 'evand.com' !== $host || ! preg_match( '#^/city(?:/|$)#i', $path ) ) { return array(); }
		$out = array();
		if ( class_exists( 'DOMDocument' ) ) {
			$dom = new DOMDocument();
			$previous = libxml_use_internal_errors( true );
			@$dom->loadHTML( '<?xml encoding="UTF-8">' . $body );
			libxml_clear_errors();
			libxml_use_internal_errors( $previous );
			$xpath = new DOMXPath( $dom );
			$nodes = $xpath->query( '//a[contains(@href,"/events/")]' );
			if ( $nodes ) {
				foreach ( $nodes as $node ) {
					$name = trim( preg_replace( '/\s+/u', ' ', self::clean_html_text( $node->textContent ) ) );
					if ( ! $name || mb_strlen( $name, 'UTF-8' ) < 3 ) { continue; }
					$href = (string) $node->getAttribute( 'href' );
					$link = esc_url_raw( (string) ( wp_http_validate_url( $href ) ? $href : 'https://evand.com' . '/' . ltrim( $href, '/' ) ) );
					if ( ! $link ) { continue; }
					$parent = $node->parentNode;
					$context = $parent ? trim( preg_replace( '/\s+/u', ' ', self::clean_html_text( $parent->textContent ) ) ) : $name;
					if ( $parent && $parent->parentNode ) {
						$context = trim( preg_replace( '/\s+/u', ' ', self::clean_html_text( $parent->parentNode->textContent ) ) );
					}
					$date = self::extract_datetime( $context, $city );
					$price = '';
					if ( preg_match( '/(رایگان|(?:از\s*)?[\d٬,\.]+\s*تومان)/u', $context, $pm ) ) { $price = 'رایگان' === $pm[1] ? 'free' : 'medium'; }
					$out[] = array(
						'name' => sanitize_text_field( $name ),
						'summary' => wp_trim_words( $context, 55, '…' ),
						'type' => 'event', 'address' => $city['name'], 'lat' => 0, 'lng' => 0,
						'start' => $date, 'end' => '', 'link' => $link, 'source_url' => $link, 'source_kind' => 'city_channel',
						'price_tier' => $price,
					);
					if ( count( $out ) >= 12 ) { break; }
				}
			}
		}
		return $out;
	}


	private static function listing_candidates( $body, $source, array $city ) {
		$host = strtolower( (string) wp_parse_url( $source, PHP_URL_HOST ) );
		if ( ! $host || false !== stripos( $host, 'instagram.com' ) || false !== stripos( $host, 't.me' ) ) { return array(); }
		if ( ! class_exists( 'DOMDocument' ) ) { return array(); }
		$out = array();
		$dom = new DOMDocument(); $previous = libxml_use_internal_errors( true );
		@$dom->loadHTML( '<?xml encoding="UTF-8">' . $body ); libxml_clear_errors(); libxml_use_internal_errors( $previous );
		$xpath = new DOMXPath( $dom );
		$nodes = $xpath->query( '//a[contains(translate(@href,"EVENTS","events"),"/events/") or contains(translate(@href,"EVENT","event"),"/event/") or contains(translate(@href,"EVENT","event"),"/e/")]' );
		if ( ! $nodes ) { return $out; }
		foreach ( $nodes as $node ) {
			$name = trim( preg_replace( '/\s+/u', ' ', self::clean_html_text( $node->textContent ) ) );
			if ( ! $name || mb_strlen( $name, 'UTF-8' ) < 4 ) { continue; }
			$href = (string) $node->getAttribute( 'href' );
			$link = esc_url_raw( (string) ( wp_http_validate_url( $href ) ? $href : '' ) );
			if ( ! $link ) { continue; }
			$parent = $node->parentNode; $context = $parent ? trim( preg_replace( '/\s+/u', ' ', self::clean_html_text( $parent->textContent ) ) ) : $name;
			if ( $parent && $parent->parentNode ) { $context = trim( preg_replace( '/\s+/u', ' ', self::clean_html_text( $parent->parentNode->textContent ) ) ); }
			$date = self::extract_datetime( $context, $city );
			if ( ! $date && preg_match( '/<time[^>]+datetime=["\']([^"\']+)["\']/i', $context, $tm ) ) { $date = self::iso_datetime( $tm[1] ); }
			$out[] = array( 'name' => sanitize_text_field( $name ), 'summary' => wp_trim_words( $context, 55, '…' ), 'type' => 'event', 'address' => self::extract_venue( $context ), 'lat' => 0, 'lng' => 0, 'start' => $date, 'end' => '', 'link' => $link, 'source_url' => $source, 'source_kind' => 'city_channel' );
			if ( count( $out ) >= 12 ) { break; }
		}
		return $out;
	}


	/**
	 * Generic public-source extractor. This is deliberately host-agnostic:
	 * new event platforms do not require a new hard-coded adapter. Structured
	 * data remains preferred; this fallback looks for event-like public links
	 * and nearby date/time text on ordinary HTML listing pages.
	 */
	private static function generic_public_candidates( $body, $source, array $city ) {
		$out = array();
		$host = strtolower( (string) wp_parse_url( $source, PHP_URL_HOST ) );
		if ( ! $host || false !== strpos( $host, 'evand.com' ) ) { return $out; }
		if ( ! class_exists( 'DOMDocument' ) ) { return $out; }
		$dom = new DOMDocument(); $previous = libxml_use_internal_errors( true );
		@$dom->loadHTML( '<?xml encoding="UTF-8">' . $body ); libxml_clear_errors(); libxml_use_internal_errors( $previous );
		$xpath = new DOMXPath( $dom );
		$nodes = $xpath->query('//a[@href]');
		if ( ! $nodes ) { return $out; }
		$seen = array();
		foreach ( $nodes as $node ) {
			$href = trim( (string) $node->getAttribute('href') );
			$name = trim( preg_replace('/\s+/u', ' ', self::clean_html_text( $node->textContent ) ) );
			if ( ! $href || ! $name || mb_strlen($name, 'UTF-8') < 5 || mb_strlen($name, 'UTF-8') > 180 ) { continue; }
			if ( preg_match('/^(#|javascript:|mailto:|tel:)/i', $href) ) { continue; }
			$link = esc_url_raw( (string) ( wp_http_validate_url($href) ? $href : '' ) );
			if ( ! $link ) {
				$base = 'https://' . $host;
				$link = esc_url_raw( $base . '/' . ltrim($href, '/') );
			}
			if ( ! $link || isset($seen[$link]) ) { continue; }
			$path = strtolower( (string) wp_parse_url($link, PHP_URL_PATH) );
			$eventish = preg_match('/(?:^|\/)(?:event|events|activity|activities|workshop|concert|exhibition|festival|program|programme|e)(?:\/|-|$)/i', $path);
			$parent = $node->parentNode;
			$context = $parent ? self::clean_html_text($parent->textContent) : $name;
			if ( $parent && $parent->parentNode ) { $context = self::clean_html_text($parent->parentNode->textContent); }
			$has_date = (bool) preg_match('/\d{2,4}[\/-]\d{1,2}[\/-]\d{1,2}|\d{1,2}\s*(?:مهر|آبان|آذر|دی|بهمن|اسفند|فروردین|اردیبهشت|خرداد|تیر|مرداد|شهریور)|\b(?:jan|feb|mar|apr|may|jun|jul|aug|sep|oct|nov|dec)\b|ساعت|time|date/iu', $context);
			$event_word = (bool) preg_match('/رویداد|کنسرت|جشنواره|کارگاه|همایش|نمایش|نمایشگاه|افتتاح|سمینار|workshop|concert|festival|event|exhibition|tour/iu', $name . ' ' . $context);
			if ( ! $eventish && ! ($has_date && $event_word) ) { continue; }
			$date = self::extract_datetime($context, $city);
			$out[] = array(
				'name' => sanitize_text_field($name),
				'summary' => wp_trim_words($context, 55, '…'),
				'type' => 'event', 'address' => self::extract_venue($context), 'lat' => 0, 'lng' => 0,
				'start' => $date, 'end' => '', 'link' => $link, 'source_url' => $source, 'source_kind' => 'city_channel_generic',
			);
			$seen[$link] = true;
			if ( count($out) >= 12 ) { break; }
		}
		return $out;
	}

	private static function jsonld_candidates( $body, $source ) {
		$out = array();
		if ( ! preg_match_all( '#<script[^>]+type=["\\\']application/ld\\+json["\\\'][^>]*>(.*?)</script>#is', $body, $m ) ) { return $out; }
		foreach ( $m[1] as $raw ) {
			$data = json_decode( html_entity_decode( trim( $raw ), ENT_QUOTES, 'UTF-8' ), true );
			if ( ! is_array( $data ) ) { continue; }
			$rows = isset( $data['@graph'] ) && is_array( $data['@graph'] ) ? $data['@graph'] : ( isset( $data[0] ) ? $data : array( $data ) );
			foreach ( $rows as $row ) {
				$type = strtolower( is_array( $row ) ? (string) ( $row['@type'] ?? '' ) : '' );
				if ( ! $row || ( false === strpos( $type, 'event' ) && false === strpos( $type, 'business' ) && false === strpos( $type, 'place' ) ) ) { continue; }
				$name = sanitize_text_field( (string) ( $row['name'] ?? '' ) );
				if ( ! $name ) { continue; }
				$loc = $row['location'] ?? array();
				$address = is_array( $loc ) ? (string) ( $loc['address']['streetAddress'] ?? $loc['address']['name'] ?? $loc['name'] ?? '' ) : (string) $loc;
				$out[] = array(
					'name' => $name, 'summary' => wp_trim_words( wp_strip_all_tags( (string) ( $row['description'] ?? '' ) ), 55, '…' ),
					'type' => false !== strpos( $type, 'event' ) ? 'event' : 'place', 'address' => sanitize_text_field( $address ),
					'lat' => (float) ( $loc['geo']['latitude'] ?? $row['geo']['latitude'] ?? 0 ), 'lng' => (float) ( $loc['geo']['longitude'] ?? $row['geo']['longitude'] ?? 0 ),
					'start' => self::iso_datetime( $row['startDate'] ?? '' ), 'end' => self::iso_datetime( $row['endDate'] ?? '' ),
					'link' => esc_url_raw( (string) ( $row['url'] ?? $source ) ), 'source_url' => $source, 'source_kind' => 'city_channel',
				);
			}
		}
		return $out;
	}

	private static function telegram_candidates( $body, $source, array $city ) {
		$out = array();
		if ( false === stripos( $source, 't.me/' ) || false === stripos( $body, 'tgme_widget_message' ) ) { return $out; }
		if ( class_exists( 'DOMDocument' ) ) {
			$dom = new DOMDocument(); $previous = libxml_use_internal_errors( true );
			@$dom->loadHTML( '<?xml encoding="UTF-8">' . $body ); libxml_clear_errors(); libxml_use_internal_errors( $previous );
			$xpath = new DOMXPath( $dom );
			$nodes = $xpath->query( '//*[contains(concat(" ", normalize-space(@class), " "), " tgme_widget_message_wrap ")]' );
			if ( $nodes ) {
				foreach ( $nodes as $wrap ) {
					$text_node = $xpath->query('.//*[contains(@class,"tgme_widget_message_text")]', $wrap)->item(0);
					$text = $text_node ? self::clean_html_text($text_node->textContent) : self::clean_html_text($wrap->textContent);
					if ( ! $text || ! self::looks_like_event($text) ) { continue; }
					$name = self::candidate_title($text); if ( ! $name ) { continue; }
					$date = self::extract_datetime($text, $city); $venue = self::extract_venue($text);
					$link_node = $xpath->query('.//a[contains(@class,"tgme_widget_message_date")]', $wrap)->item(0);
					$link = $link_node ? esc_url_raw((string)$link_node->getAttribute('href')) : $source;
					$out[] = array('name'=>$name,'summary'=>wp_trim_words($text,65,'…'),'type'=>'event','address'=>$venue,'lat'=>0,'lng'=>0,'start'=>$date,'end'=>'','link'=>$link ?: $source,'source_url'=>$source,'source_kind'=>'city_channel_telegram');
					if ( count($out) >= 12 ) { return $out; }
				}
				if ( $out ) { return $out; }
			}
		}
		if ( preg_match_all( '#<div[^>]+class=["\'][^"\']*tgme_widget_message[^"\']*["\'][^>]*>(.*?)</div>\s*</div>#is', $body, $m ) ) {
			foreach ( array_slice( $m[1], 0, 12 ) as $block ) {
				$text = self::clean_html_text( $block );
				if ( ! self::looks_like_event( $text ) ) { continue; }
				$name = self::candidate_title( $text );
				if ( ! $name ) { continue; }
				$date = self::extract_datetime( $text, $city );
				$venue = self::extract_venue( $text );
				$out[] = array(
					'name' => $name, 'summary' => wp_trim_words( $text, 65, '…' ), 'type' => 'event', 'address' => $venue,
					'lat' => 0, 'lng' => 0, 'start' => $date, 'end' => '', 'link' => $source, 'source_url' => $source, 'source_kind' => 'city_channel',
				);
			}
		}
		return $out;
	}


	/**
	 * Public social-page fallback for configured Instagram/Telegram/web social
	 * URLs. It deliberately reads only public HTML/meta text; it never logs in.
	 * This gives public social sources a first-class extraction path even when
	 * they do not expose Schema.org Event JSON-LD.
	 */
	private static function social_public_candidates( $body, $source, array $city ) {
		$host = strtolower( (string) wp_parse_url( $source, PHP_URL_HOST ) );
		if ( ! $host || ( false === strpos( $host, 'instagram.com' ) && false === strpos( $host, 'facebook.com' ) && false === strpos( $host, 'threads.net' ) ) ) { return array(); }
		$parts = array();
		foreach ( array( 'og:title', 'og:description', 'twitter:title', 'twitter:description', 'description' ) as $key ) {
			$v = self::meta( $body, $key );
			if ( $v ) { $parts[] = $v; }
		}
		if ( class_exists( 'DOMDocument' ) ) {
			$dom = new DOMDocument(); $previous = libxml_use_internal_errors( true );
			@$dom->loadHTML( '<?xml encoding="UTF-8">' . $body ); libxml_clear_errors(); libxml_use_internal_errors( $previous );
			$xpath = new DOMXPath( $dom );
			$nodes = $xpath->query( '//article | //main | //body' );
			if ( $nodes && $nodes->length ) {
				$text = self::clean_html_text( $nodes->item(0)->textContent );
				if ( $text ) { $parts[] = mb_substr( $text, 0, 4000, 'UTF-8' ); }
			}
		}
		$text = trim( preg_replace( '/\s+/u', ' ', implode( ' ', array_unique( $parts ) ) ) );
		if ( ! $text ) { return array(); }
		$name = self::candidate_title( $text );
		if ( ! $name ) { $name = self::meta( $body, 'og:title' ); }
		if ( ! $name ) { return array(); }
		if ( self::looks_like_event( $text ) ) {
			$date = self::extract_datetime( $text, $city );
			if ( ! $date ) { return array(); }
			return array( array(
				'name' => sanitize_text_field( $name ), 'summary' => wp_trim_words( $text, 65, '…' ), 'type' => 'event',
				'address' => self::extract_venue( $text ), 'lat' => 0, 'lng' => 0, 'start' => $date, 'end' => '',
				'link' => $source, 'source_url' => $source, 'source_kind' => 'city_channel_social',
			) );
		}
		// A social profile can be a useful evergreen place source even when it has
		// no dated event. Keep only strongly place-like public profiles; the route
		// layer will require coordinates before using them as physical stops.
		$place_like = preg_match( '/گالری|نگارخانه|موزه|کافه|رستوران|کتابفروشی|بازار|فروشگاه|هنر|gallery|museum|cafe|restaurant|market|bookstore|shop/iu', $text );
		$city_like = false !== stripos( self::norm( $text ), self::norm( (string) $city['name'] ) );
		if ( $place_like && $city_like ) {
			return array( array( 'name' => sanitize_text_field( $name ), 'summary' => wp_trim_words( $text, 65, '…' ), 'type' => 'place', 'address' => self::extract_venue( $text ), 'lat' => 0, 'lng' => 0, 'start' => '', 'end' => '', 'link' => $source, 'source_url' => $source, 'source_kind' => 'city_channel_social_place' ) );
		}
		return array();
	}

	private static function meta_candidate( $body, $source, array $city ) {
		$host = strtolower( (string) wp_parse_url( $source, PHP_URL_HOST ) );
		$path = (string) wp_parse_url( $source, PHP_URL_PATH );
		if ( 'evand.com' === $host && preg_match( '#^/city(?:/|$)#i', $path ) ) { return null; }
		$title = self::meta( $body, 'og:title' );
		$desc  = self::meta( $body, 'og:description' );
		if ( ! $desc ) { $desc = self::meta( $body, 'description' ); }
		if ( ! $title && ! $desc ) { return null; }
		$combined = trim( $title . ' ' . $desc );
		$type = self::looks_like_event( $combined ) ? 'event' : 'place';
		return array( 'name' => sanitize_text_field( $title ?: ( $city['name'] . ' online source' ) ), 'summary' => wp_trim_words( wp_strip_all_tags( $desc ), 65, '…' ), 'type' => $type, 'address' => '', 'lat' => 0, 'lng' => 0, 'start' => self::extract_datetime( $combined, $city ), 'end' => '', 'link' => $source, 'source_url' => $source, 'source_kind' => 'city_channel' );
	}

	private static function enrich_from_direct_link( array $c, array $city ) {
		$link = esc_url_raw( (string) ( $c['link'] ?? '' ) );
		if ( ! $link || self::$link_fetch_count >= 8 || ( 'event' !== ( $c['type'] ?? '' ) ) ) { return $c; }
		if ( ! self::is_public_url( $link ) ) { return $c; }
		$needs = empty( $c['start'] ) || empty( $c['address'] ) || empty( $c['lat'] ) || empty( $c['lng'] );
		if ( ! $needs ) { return $c; }
		$source_host = strtolower( (string) wp_parse_url( $c['channel_configured_url'] ?? $c['source_url'] ?? '', PHP_URL_HOST ) );
		$link_host = strtolower( (string) wp_parse_url( $link, PHP_URL_HOST ) );
		if ( ! $link_host || ( $source_host && ! ( $link_host === $source_host || substr( $link_host, -strlen( '.' . $source_host ) ) === '.' . $source_host ) ) ) { return $c; }
		self::$link_fetch_count++;
		$response = wp_remote_get( $link, array( 'timeout' => 2, 'redirection' => 3, 'headers' => array( 'Accept' => 'text/html,application/xhtml+xml,application/ld+json,*/*', 'User-Agent' => 'Personalized-City-Tour/' . PCT_VERSION . ' (+public-source-enrichment)' ) ) );
		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) { return $c; }
		$body = wp_remote_retrieve_body( $response );
		if ( ! $body || strlen( $body ) > 1200000 ) { return $c; }
		$rows = self::jsonld_candidates( $body, $link );
		foreach ( $rows as $row ) {
			if ( 'event' !== ( $row['type'] ?? '' ) ) { continue; }
			if ( empty( $c['start'] ) && ! empty( $row['start'] ) ) { $c['start'] = $row['start']; }
			if ( empty( $c['end'] ) && ! empty( $row['end'] ) ) { $c['end'] = $row['end']; }
			if ( empty( $c['address'] ) && ! empty( $row['address'] ) ) { $c['address'] = $row['address']; }
			if ( empty( $c['lat'] ) && ! empty( $row['lat'] ) ) { $c['lat'] = $row['lat']; }
			if ( empty( $c['lng'] ) && ! empty( $row['lng'] ) ) { $c['lng'] = $row['lng']; }
			if ( ! empty( $row['name'] ) && mb_strlen( $c['name'], 'UTF-8' ) < 4 ) { $c['name'] = $row['name']; }
			break;
		}
		if ( empty( $c['start'] ) ) { $c['start'] = self::extract_datetime( self::clean_html_text( $body ), $city ); }
		if ( empty( $c['address'] ) ) { $c['address'] = self::extract_venue( self::clean_html_text( $body ) ); }
		$c['link'] = $link;
		return $c;
	}

	private static function finalize_candidate( array $c, array $city, array $existing ) {
		$c['categories'] = self::categories( $c['name'] . ' ' . $c['summary'] );
		$c['tags'] = array_values( array_unique( array_filter( array_map( 'sanitize_text_field', preg_split( '/\s+/u', trim( preg_replace( '/[#،,:;|]+/u', ' ', $c['name'] ) ) ) ) ) ) );
		$c['price_tier'] = in_array( (string) ( $c['price_tier'] ?? '' ), array( 'free', 'low', 'medium', 'high' ), true ) ? (string) $c['price_tier'] : ''; $c['accessibility'] = array(); $c['duration'] = 60; $c['hours'] = ''; $c['best_time'] = array(); $c['family'] = false; $c['image'] = '';
		$c['organizer'] = ''; $c['organizer_url'] = ''; $c['event_url'] = $c['link']; $c['venue'] = $c['address']; $c['socials'] = array();
		$matched = self::match_existing( $c, $existing );
		if ( $matched ) {
			$c['lat'] = (float) $matched['lat']; $c['lng'] = (float) $matched['lng'];
			if ( ! $c['address'] ) { $c['address'] = (string) $matched['address']; }
			if ( ! $c['image'] ) { $c['image'] = (string) $matched['image']; }
			$c['channel_matched_item_id'] = (int) $matched['id'];
			$c['score'] = 9;
		} else {
			if ( ! $c['lat'] || ! $c['lng'] ) {
				$geo = self::geocode_public( trim( (string) ( $c['address'] ?: $c['name'] ) . ', ' . $city['name'] ) );
				if ( $geo ) { $c['lat'] = $geo['lat']; $c['lng'] = $geo['lng']; }
			}
			$c['location_status'] = ( $c['lat'] && $c['lng'] ) ? 'verified' : 'needs_location_enrichment';
			$c['serendipity_eligible'] = ( 'event' === ( $c['type'] ?? '' ) && 'verified' !== $c['location_status'] );
			$c['score'] = $c['lat'] && $c['lng'] ? 5 : 2;
		}
		$source_url = esc_url_raw( (string) $c['source_url'] );
		$c['id'] = 800000000 + ( abs( crc32( (string) ( $source_url . '|' . $c['name'] . '|' . $c['start'] ) ) ) % 100000000 );
		$c['location_status'] = ( ! empty( $c['lat'] ) && ! empty( $c['lng'] ) ) ? 'verified' : (string) ( $c['location_status'] ?? 'needs_location_enrichment' );
		$c['serendipity_eligible'] = ( 'event' === ( $c['type'] ?? '' ) && 'verified' !== $c['location_status'] );
		$c['route_eligible'] = (bool) ( ! empty( $c['lat'] ) && ! empty( $c['lng'] ) );
		$source_label = (string) ( $c['channel_label'] ?? '' );
		if ( '' === $source_label ) {
			$source_host = (string) wp_parse_url( $source_url, PHP_URL_HOST );
			$source_label = $source_host ?: __( 'Online city source', 'personalized-city-tour' );
		}
		$c['candidate_id'] = 'channel_' . md5( $source_url . '|' . $c['name'] . '|' . (string) ( $c['start'] ?? '' ) );
		$c['source_name'] = sanitize_text_field( $source_label );
		$c['observed_at'] = current_time( 'mysql' );
		$c['channel_virtual'] = true; $c['channel_source_url'] = $source_url;
		$c['channels'] = array( array( 'label' => (string) ( $c['channel_label'] ?? __( 'Online city source', 'personalized-city-tour' ) ), 'url' => $c['source_url'] ) );
		$c['links'] = array(
			array( 'label' => __( 'Online source', 'personalized-city-tour' ), 'url' => $c['source_url'], 'kind' => 'official' ),
			array( 'label' => __( 'Web search', 'personalized-city-tour' ), 'url' => 'https://www.google.com/search?q=' . rawurlencode( $c['name'] . ' ' . $city['name'] ), 'kind' => 'web' ),
			array( 'label' => __( 'Photo search', 'personalized-city-tour' ), 'url' => 'https://www.google.com/search?tbm=isch&q=' . rawurlencode( $c['name'] . ' ' . $city['name'] ), 'kind' => 'photos' ),
		);
		return $c;
	}

	private static function match_existing( array $c, array $existing ) {
		$needle = self::norm( $c['name'] . ' ' . $c['address'] );
		$best = null; $best_score = 0;
		foreach ( $existing as $item ) {
			$hay = self::norm( $item['name'] . ' ' . $item['address'] );
			$score = 0;
			if ( $needle && ( false !== strpos( $needle, self::norm( $item['name'] ) ) || false !== strpos( self::norm( $item['name'] ), $needle ) ) ) { $score += 3; }
			if ( $c['address'] && $item['address'] && false !== strpos( $needle, self::norm( $item['address'] ) ) ) { $score += 2; }
			if ( $score > $best_score && $item['lat'] && $item['lng'] ) { $best_score = $score; $best = $item; }
		}
		return $best_score >= 3 ? $best : null;
	}

	private static function geocode_public( $query ) {
		$query = trim( (string) $query );
		if ( self::$geo_count >= 4 || mb_strlen( $query, 'UTF-8' ) < 4 ) { return null; }
		$key = 'pct_channel_geo_' . md5( self::norm( $query ) );
		$cached = get_transient( $key );
		if ( is_array( $cached ) && isset( $cached['lat'], $cached['lng'] ) ) { return $cached; }
		self::$geo_count++;
		$response = wp_remote_get( 'https://nominatim.openstreetmap.org/search?' . http_build_query( array( 'q' => $query, 'format' => 'jsonv2', 'limit' => 1 ) ), array(
			'timeout' => 4,
			'headers' => array( 'Accept' => 'application/json', 'User-Agent' => 'Personalized-City-Tour/' . PCT_VERSION . ' (public-source-enrichment)' ),
		) );
		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) { return null; }
		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( empty( $data[0]['lat'] ) || empty( $data[0]['lon'] ) ) { return null; }
		$out = array( 'lat' => (float) $data[0]['lat'], 'lng' => (float) $data[0]['lon'] );
		set_transient( $key, $out, DAY_IN_SECONDS );
		return $out;
	}

	private static function categories( $text ) {
		$t = self::norm( $text ); $out = array();
		$map = array(
			'concert|music|کنسرت|موسیقی' => 'culture', 'festival|جشنواره' => 'events', 'workshop|کارگاه|همایش|کنفرانس' => 'events',
			'theater|تئاتر|نمایش' => 'culture', 'museum|موزه' => 'museums', 'heritage|historical|تاریخی|میراث' => 'historical',
			'food|restaurant|cafe|رستوران|کافه|غذا' => 'food', 'market|bazaar|بازار' => 'markets', 'nature|park|mountain|طبیعت|پارک|کوه' => 'nature',
			'photography|عکاسی' => 'photography', 'family|خانواده|کودک' => 'family', 'tour|travel|تور|سفر' => 'local_life',
		);
		foreach ( $map as $pattern => $cat ) { if ( preg_match( '/(?:' . $pattern . ')/iu', $t ) ) { $out[] = $cat; } }
		return array_values( array_unique( $out ) );
	}

	private static function looks_like_event( $text ) { return (bool) preg_match( '/کنسرت|جشنواره|رویداد|کارگاه|همایش|نمایش|برنامه|تور|افتتاح|نمایشگاه|سمینار|concert|festival|event|workshop|tour|exhibition/iu', $text ) && (bool) preg_match( '/\d{2,4}[\/-]\d{1,2}[\/-]\d{1,2}|\d{1,2}\s*(?:مهر|آبان|آذر|دی|بهمن|اسفند|فروردین|اردیبهشت|خرداد|تیر|مرداد|شهریور)|ساعت|تاریخ|روز/iu', $text ); }
	private static function candidate_title( $text ) { $lines = preg_split( '/\R+/u', trim( $text ) ); foreach ( $lines as $line ) { $line = trim( preg_replace( '/\s+/u', ' ', $line ) ); if ( $line && mb_strlen( $line, 'UTF-8' ) >= 5 ) { return mb_substr( $line, 0, 120, 'UTF-8' ); } } return ''; }
	private static function extract_venue( $text ) { if ( preg_match( '/(?:مکان|محل|سالن|آدرس|نشانی)\s*[:：-]?\s*([^\n]{3,100})/iu', $text, $m ) ) { return trim( $m[1] ); } return ''; }
	private static function extract_datetime( $text, array $city ) {
		$text = self::latin_digits( $text );
		if ( preg_match( '/(\d{3,4})[\/\-](\d{1,2})[\/\-](\d{1,2})(?:[^\d]{0,20}(\d{1,2})[:٫:](\d{2}))?/u', $text, $m ) ) {
			$year = (int) $m[1]; $month = (int) $m[2]; $day = (int) $m[3];
			if ( $year >= 1300 && $year <= 1500 ) { list( $gy, $gm, $gd ) = self::jalali_to_gregorian( $year, $month, $day ); $year = $gy; $month = $gm; $day = $gd; }
			$time = ! empty( $m[4] ) ? sprintf( '%02d:%02d', (int) $m[4], (int) $m[5] ) : '12:00';
			return sprintf( '%04d-%02d-%02d %s', $year, $month, $day, $time );
		}
		$months = array( 'فروردین'=>1,'اردیبهشت'=>2,'خرداد'=>3,'تیر'=>4,'مرداد'=>5,'شهریور'=>6,'مهر'=>7,'آبان'=>8,'آذر'=>9,'دی'=>10,'بهمن'=>11,'اسفند'=>12 );
		$month_re = implode( '|', array_keys( $months ) );
		if ( preg_match( '/(\d{1,2})\s*(' . $month_re . ')\s*(\d{4})(?:[^\d]{0,30}(\d{1,2})[:٫:](\d{2}))?/u', $text, $m ) ) {
			$jy = (int) $m[3]; $jm = (int) $months[ $m[2] ]; $jd = (int) $m[1];
			if ( $jy >= 1300 && $jy <= 1500 ) { list( $gy, $gm, $gd ) = self::jalali_to_gregorian( $jy, $jm, $jd ); $time = ! empty( $m[4] ) ? sprintf( '%02d:%02d', (int) $m[4], (int) $m[5] ) : '12:00'; return sprintf( '%04d-%02d-%02d %s', $gy, $gm, $gd, $time ); }
		}
		return '';
	}
	private static function iso_datetime( $v ) { $v = trim( (string) $v ); return $v && strtotime( $v ) ? wp_date( 'Y-m-d H:i:s', strtotime( $v ), wp_timezone() ) : ''; }
	private static function ymd_ts( $v ) { $v = (string) $v; if ( class_exists( 'PCT_I18n' ) ) { $parsed = PCT_I18n::parse_date( $v ); if ( $parsed ) { $v = $parsed; } } $ts = strtotime( $v . ' 00:00:00' ); return $ts ?: current_time( 'timestamp' ); }
	private static function norm( $v ) { $v = self::latin_digits( wp_strip_all_tags( (string) $v ) ); $v = mb_strtolower( $v, 'UTF-8' ); return trim( preg_replace( '/\s+/u', ' ', $v ) ); }
	private static function latin_digits( $v ) { return strtr( (string) $v, array( '۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9','٠'=>'0','١'=>'1','٢'=>'2','٣'=>'3','٤'=>'4','٥'=>'5','٦'=>'6','٧'=>'7','٨'=>'8','٩'=>'9' ) ); }
	private static function clean_html_text( $html ) { return trim( preg_replace( '/\s+/u', ' ', html_entity_decode( wp_strip_all_tags( $html ), ENT_QUOTES, 'UTF-8' ) ) ); }
	private static function meta( $body, $name ) { if ( preg_match( '#<meta[^>]+(?:property|name)=["\']' . preg_quote( $name, '#' ) . '["\'][^>]+content=["\']([^"\']*)["\'][^>]*>#i', $body, $m ) ) { return trim( html_entity_decode( $m[1], ENT_QUOTES, 'UTF-8' ) ); } return ''; }

	private static function jalali_to_gregorian( $jy, $jm, $jd ) {
		$jy -= 979; $jm -= 1; $jd -= 1; $j_day_no = 365 * $jy + intdiv( $jy, 33 ) * 8 + intdiv( ( $jy % 33 ) + 3, 4 );
		if ( $jm < 6 ) { $j_day_no += $jm * 31; } else { $j_day_no += $jm * 30 + 6; }
		$j_day_no += $jd; $g_day_no = $j_day_no + 79; $gy = 1600 + 400 * intdiv( $g_day_no, 146097 ); $g_day_no %= 146097; $leap = true;
		if ( $g_day_no >= 36525 ) { $g_day_no--; $gy += 100 * intdiv( $g_day_no, 36524 ); $g_day_no %= 36524; if ( $g_day_no >= 365 ) { $g_day_no++; } else { $leap = false; } }
		$gy += 4 * intdiv( $g_day_no, 1461 ); $g_day_no %= 1461; if ( $g_day_no > 365 ) { $leap = false; $g_day_no--; $gy += intdiv( $g_day_no, 365 ); $g_day_no %= 365; }
		$sal_a = array( 0,31, ( $leap ? 29 : 28 ),31,30,31,30,31,31,30,31,30,31 ); $gm = 1; while ( $gm <= 12 && $g_day_no >= $sal_a[ $gm ] ) { $g_day_no -= $sal_a[ $gm ]; $gm++; } return array( $gy, $gm, $g_day_no + 1 );
	}
}
