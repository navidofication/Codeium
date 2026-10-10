<?php
/**
 * Read access to the city database (cities, places, events) as plain arrays.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PCT_Data {

	public static function accessibility_options() {
		return array(
			'wheelchair'  => __( 'Wheelchair accessible', 'personalized-city-tour' ),
			'stroller'    => __( 'Stroller friendly', 'personalized-city-tour' ),
			'no_stairs'   => __( 'Step-free access', 'personalized-city-tour' ),
			'rest_areas'  => __( 'Seating / rest areas', 'personalized-city-tour' ),
			'accessible_wc' => __( 'Accessible toilets', 'personalized-city-tour' ),
		);
	}

	public static function price_tiers() {
		return array(
			'free'   => __( 'Free', 'personalized-city-tour' ),
			'low'    => __( 'Low', 'personalized-city-tour' ),
			'medium' => __( 'Medium', 'personalized-city-tour' ),
			'high'   => __( 'High', 'personalized-city-tour' ),
		);
	}

	public static function best_times() {
		return array(
			'morning'   => __( 'Morning', 'personalized-city-tour' ),
			'afternoon' => __( 'Afternoon', 'personalized-city-tour' ),
			'evening'   => __( 'Evening', 'personalized-city-tour' ),
			'night'     => __( 'Night', 'personalized-city-tour' ),
		);
	}

	public static function city( $city_id ) {
		$post = get_post( $city_id );
		if ( ! $post || PCT_Post_Types::CITY !== $post->post_type ) {
			return null;
		}
		return array(
			'id'          => $post->ID,
			'name'        => $post->post_title,
			'country'     => (string) get_post_meta( $post->ID, '_pct_country', true ),
			'description' => wp_strip_all_tags( $post->post_content ),
			'lat'         => (float) get_post_meta( $post->ID, '_pct_lat', true ),
			'lng'         => (float) get_post_meta( $post->ID, '_pct_lng', true ),
			'zoom'        => (int) ( get_post_meta( $post->ID, '_pct_zoom', true ) ?: 13 ),
			'mymaps'      => self::mymaps( $post->ID ),
			'channels'    => self::links( $post->ID, '_pct_channels' ),
			'gmaps_url'   => (string) get_post_meta( $post->ID, '_pct_gmaps_url', true ),
			'ar_url'      => (string) get_post_meta( $post->ID, '_pct_ar_url', true ),
			'ar_label'    => (string) get_post_meta( $post->ID, '_pct_ar_label', true ),
			'timezone'    => (string) get_post_meta( $post->ID, '_pct_timezone', true ),
		);
	}

	/**
	 * Generic list of labelled links stored in post meta: [ {label, url} ].
	 */
	public static function links( $post_id, $meta_key ) {
		$raw = get_post_meta( $post_id, $meta_key, true );
		$out = array();
		foreach ( (array) $raw as $row ) {
			if ( ! is_array( $row ) || empty( $row['url'] ) ) {
				continue;
			}
			$out[] = array(
				'label' => (string) ( $row['label'] ?? '' ),
				'url'   => (string) $row['url'],
			);
		}
		return $out;
	}

	public static function social_networks() {
		return array(
			'instagram' => 'Instagram',
			'telegram'  => 'Telegram',
			'facebook'  => 'Facebook',
			'x'         => 'X / Twitter',
			'youtube'   => 'YouTube',
			'tiktok'    => 'TikTok',
			'whatsapp'  => 'WhatsApp',
			'website'   => __( 'Website', 'personalized-city-tour' ),
			'other'     => __( 'Other', 'personalized-city-tour' ),
		);
	}

	/**
	 * Google My Maps links attached to a city: [ {label, url, embed} ].
	 */
	public static function mymaps( $city_id ) {
		$raw = get_post_meta( $city_id, '_pct_mymaps', true );
		$out = array();
		foreach ( (array) $raw as $row ) {
			if ( empty( $row['url'] ) ) {
				continue;
			}
			$out[] = array(
				'label' => (string) ( $row['label'] ?? '' ),
				'url'   => (string) $row['url'],
				'embed' => self::mymaps_embed_url( $row['url'] ),
			);
		}
		return $out;
	}

	/**
	 * Convert a Google My Maps share/view URL into an embeddable URL.
	 */
	public static function mymaps_embed_url( $url ) {
		$parts = wp_parse_url( $url );
		if ( empty( $parts['query'] ) ) {
			return '';
		}
		parse_str( $parts['query'], $q );
		if ( empty( $q['mid'] ) ) {
			return '';
		}
		return 'https://www.google.com/maps/d/embed?mid=' . rawurlencode( $q['mid'] );
	}

	/**
	 * Read public Google My Maps KML features and match them to PCT city items.
	 * My Maps URLs alone are not machine-readable enough for route planning, so
	 * public maps are converted to a small, cached feature set. Private maps or
	 * maps that disallow export are safely skipped.
	 *
	 * @return array {maps:[], matched_item_ids:[], candidate_items:[]}
	 */
	public static function curated_mymap_context( $city_id, array $items = array() ) {
		$maps = self::mymaps( $city_id );
		$out  = array();
		$matched = array();
		$candidates = array();
		foreach ( $maps as $map ) {
			$url = (string) $map['url'];
			$features = self::mymap_kml_features( $url );
			if ( ! $features ) {
				$cache_status = self::mymap_cache_status( $url );
				$out[] = array(
					'label'    => $map['label'],
					'url'      => $url,
					'features' => array(),
					'status'   => 'unavailable',
					'cached_at' => (int) ( $cache_status['cached_at'] ?? 0 ),
					'cache_stale' => ! empty( $cache_status['stale'] ),
					'note'     => 'The public KML export could not be read on the server. The original map URL is still supplied to the AI as a reference.',
				);
				continue;
			}
			$cache_status = self::mymap_cache_status( $url );
			$mf = array();
			foreach ( $features as $feature_index => $feature ) {
				$match = self::match_mymap_feature( $feature, $items );
				if ( $match ) {
					$feature['matched_item_id'] = (int) $match['id'];
					$feature['match_method'] = $match['_match_method'] ?? 'name';
					$matched[ (int) $match['id'] ] = true;
				}
				// Every usable KML point is also materialized as a first-class
				// City-Data-like virtual item. A true match is retained as metadata,
				// but the KML point itself remains selectable with its authoritative
				// coordinates, name and curated description.
				if ( 'point' === ( $feature['type'] ?? '' ) && ! empty( $feature['lat'] ) && ! empty( $feature['lng'] ) && ! empty( $feature['name'] ) ) {
					$virtual = self::mymap_virtual_item( $map, $feature, (int) $feature_index );
					if ( $match ) {
						$virtual['mymap_matched_item_id'] = (int) $match['id'];
						$virtual['mymap_match_method'] = $match['_match_method'] ?? 'name';
					}
					$candidates[ (int) $virtual['id'] ] = $virtual;
					$feature['candidate_item_id'] = (int) $virtual['id'];
				}
				if ( 'route' === ( $feature['type'] ?? '' ) ) {
					$route_ids = self::route_nearby_item_ids( $feature, $items );
					if ( $route_ids ) {
						$feature['route_item_ids'] = $route_ids;
						foreach ( $route_ids as $rid ) { $matched[ (int) $rid ] = true; }
					}
				}
				$feature['map_label'] = (string) $map['label'];
				$mf[] = $feature;
			}
			// Re-evaluate line features against both City Data items and the
			// virtual My Maps points created above, so a curated route can carry
			// its own stop order even when those places are not in City Data.
			$route_pool = array_merge( $items, array_values( $candidates ) );
			foreach ( $mf as &$mf_feature ) {
				if ( 'route' !== ( $mf_feature['type'] ?? '' ) ) { continue; }
				$route_ids = self::route_nearby_item_ids( $mf_feature, $route_pool );
				if ( $route_ids ) { $mf_feature['route_item_ids'] = $route_ids; }
			}
			unset( $mf_feature );
			$out[] = array( 'label' => $map['label'], 'url' => $url, 'features' => $mf, 'status' => 'ok', 'cached_at' => (int) ( $cache_status['cached_at'] ?? 0 ), 'cache_stale' => ! empty( $cache_status['stale'] ) );
		}
		$matched_meta = array();
		foreach ( $out as $map_row ) {
			foreach ( (array) ( $map_row['features'] ?? array() ) as $feature ) {
				if ( ! empty( $feature['matched_item_id'] ) ) {
					$matched_meta[ (int) $feature['matched_item_id'] ] = array( 'url' => (string) $map_row['url'], 'label' => (string) $map_row['label'] );
				}
			}
		}
		$route_sequences = array();
		$route_rank = array();
		foreach ( $out as $map_row ) {
			foreach ( (array) ( $map_row['features'] ?? array() ) as $feature ) {
				if ( 'route' !== ( $feature['type'] ?? '' ) || empty( $feature['route_item_ids'] ) ) {
					continue;
				}
				$item_ids = array_values( array_map( 'intval', (array) $feature['route_item_ids'] ) );
				foreach ( $item_ids as $rid ) {
					if ( ! isset( $matched_meta[ $rid ] ) ) {
						$matched_meta[ $rid ] = array( 'url' => (string) $map_row['url'], 'label' => (string) $map_row['label'] );
					}
				}
				$route_sequences[] = array(
					'map'       => (string) $map_row['label'],
					'name'      => (string) ( $feature['name'] ?? '' ),
					'folder'    => (string) ( $feature['folder'] ?? '' ),
					'item_ids'  => $item_ids,
					'coordinates' => (array) ( $feature['geometry'] ?? array() ),
				);
				foreach ( $item_ids as $order => $rid ) {
					if ( ! isset( $route_rank[ $rid ] ) || $order < $route_rank[ $rid ] ) {
						$route_rank[ $rid ] = $order;
					}
				}
			}
		}
		/*
		 * Route order is useful for both virtual My Maps points and ordinary
		 * City Data places that were matched to a My Maps route. Annotate the
		 * complete candidate pool so the deterministic planner can preserve a
		 * curated route even when the map point matched an existing City Data
		 * item.
		 */
		$route_meta = array();
		foreach ( $route_sequences as $sequence ) {
			foreach ( (array) ( $sequence['item_ids'] ?? array() ) as $order => $rid ) {
				$rid = (int) $rid;
				if ( ! isset( $route_meta[ $rid ] ) || $order < $route_meta[ $rid ]['order'] ) {
					$route_meta[ $rid ] = array(
						'order' => (int) $order,
						'map'   => (string) ( $sequence['map'] ?? '' ),
						'name'  => (string) ( $sequence['name'] ?? '' ),
						'folder'=> (string) ( $sequence['folder'] ?? '' ),
					);
				}
			}
		}
		foreach ( $candidates as $cid => &$candidate ) {
			if ( isset( $route_meta[ (int) $cid ] ) ) {
				$candidate['mymap_route_order'] = $route_meta[ (int) $cid ]['order'];
				$candidate['mymap_route_map']   = $route_meta[ (int) $cid ]['map'];
				$candidate['mymap_route_name']  = $route_meta[ (int) $cid ]['name'];
				$candidate['mymap_route_folder']= $route_meta[ (int) $cid ]['folder'];
			}
		}
		unset( $candidate );
		foreach ( $items as &$item ) {
			if ( isset( $matched_meta[ (int) $item['id'] ] ) ) {
				$item['mymap_matched'] = true;
				$item['mymap_url'] = $matched_meta[ (int) $item['id'] ]['url'];
				$item['mymap_label'] = $matched_meta[ (int) $item['id'] ]['label'];
			}
			if ( isset( $route_meta[ (int) $item['id'] ] ) ) {
				$item['mymap_matched'] = true;
				$item['mymap_route_order'] = $route_meta[ (int) $item['id'] ]['order'];
				$item['mymap_route_map']   = $route_meta[ (int) $item['id'] ]['map'];
				$item['mymap_route_name']  = $route_meta[ (int) $item['id'] ]['name'];
				$item['mymap_route_folder']= $route_meta[ (int) $item['id'] ]['folder'];
			}
		}
		unset( $item );
		return array(
			'maps'            => $out,
			'matched_item_ids'=> array_map( 'intval', array_keys( $matched ) ),
			'candidate_items' => array_values( $candidates ),
			'city_data_like_items' => array_values( $candidates ),

			'route_sequences' => $route_sequences,
			'route_item_meta' => $route_meta,
			'mymap_item_meta' => $matched_meta,
		);
	}

	/**
	 * Refresh and persist the KML/KMZ export for every My Maps link on a city.
	 * The cache lives in wp-content/uploads so tour generation does not depend
	 * on Google's export endpoint being reachable on every visitor request.
	 *
	 * @return array[] status rows.
	 */
	public static function refresh_mymap_cache( $city_id ) {
		$rows = array();
		foreach ( self::mymaps( $city_id ) as $map ) {
			$url = (string) ( $map['url'] ?? '' );
			$before = self::mymap_cache_status( $url );
			$features = self::mymap_kml_features( $url, true );
			$status = self::mymap_cache_status( $url );
			$updated = $features && (int) ( $status['cached_at'] ?? 0 ) > (int) ( $before['cached_at'] ?? 0 );
			$state = $updated ? 'ok' : ( $features ? 'cached_fallback' : 'error' );
			$rows[] = array(
				'label' => (string) ( $map['label'] ?? '' ),
				'url' => $url,
				'status' => $state,
				'features' => count( $features ),
				'cached_at' => (int) ( $status['cached_at'] ?? 0 ),
				'error' => (string) ( $status['error'] ?? '' ),
			);
		}
		return $rows;
	}

	/** Return the local KML cache status for a My Maps URL. */
	public static function mymap_cache_status( $url ) {
		$index = (array) get_option( 'pct_mymap_cache_index', array() );
		$key = md5( (string) $url );
		$row = isset( $index[ $key ] ) && is_array( $index[ $key ] ) ? $index[ $key ] : array();
		$file = (string) ( $row['file'] ?? '' );
		$row['available'] = $file && file_exists( $file );
		$row['stale'] = $row['available'] && ! empty( $row['cached_at'] ) && ( time() - (int) $row['cached_at'] > 7 * DAY_IN_SECONDS );
		return $row;
	}

	private static function mymap_cache_dir() {
		$uploads = wp_upload_dir();
		if ( ! empty( $uploads['basedir'] ) ) {
			$dir = trailingslashit( $uploads['basedir'] ) . 'pct-mymaps';
			if ( ! file_exists( $dir ) ) {
				wp_mkdir_p( $dir );
			}
			return $dir;
		}
		return '';
	}

	private static function save_mymap_kml_cache( $url, $body, $source = 'google' ) {
		$dir = self::mymap_cache_dir();
		if ( '' === $dir || '' === trim( (string) $body ) ) {
			return false;
		}
		$key = md5( (string) $url );
		$file = trailingslashit( $dir ) . $key . '.kml';
		if ( false === file_put_contents( $file, (string) $body ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_put_contents_file_put_contents
			return false;
		}
		$index = (array) get_option( 'pct_mymap_cache_index', array() );
		$index[ $key ] = array(
			'file' => $file,
			'cached_at' => time(),
			'hash' => md5( (string) $body ),
			'source' => $source,
			'feature_count' => count( self::parse_mymap_kml_body( (string) $body ) ),
			'error' => '',
		);
		update_option( 'pct_mymap_cache_index', $index, false );
		return $file;
	}

	private static function record_mymap_cache_error( $url, $error ) {
		$key = md5( (string) $url );
		$index = (array) get_option( 'pct_mymap_cache_index', array() );
		$row = isset( $index[ $key ] ) && is_array( $index[ $key ] ) ? $index[ $key ] : array();
		$row['error'] = sanitize_text_field( (string) $error );
		$row['last_check'] = time();
		$index[ $key ] = $row;
		update_option( 'pct_mymap_cache_index', $index, false );
	}

	private static function mymap_kml_features( $url, $force = false ) {
		$cache = self::mymap_cache_status( $url );
		if ( ! $force && ! empty( $cache['available'] ) ) {
			$cached_body = @file_get_contents( $cache['file'] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			if ( is_string( $cached_body ) && '' !== trim( $cached_body ) ) {
				$parsed = self::parse_mymap_kml_body( $cached_body );
				if ( $parsed ) { return $parsed; }
			}
		}
		$parts = wp_parse_url( $url );
		$mid = '';
		if ( ! empty( $parts['query'] ) ) {
			parse_str( $parts['query'], $q );
			$mid = sanitize_text_field( $q['mid'] ?? '' );
		}
		$downloads = array();
		if ( $mid ) {
			$mid_q = rawurlencode( $mid );
			$downloads[] = 'https://www.google.com/maps/d/kml?mid=' . $mid_q . '&forcekml=1&hl=en';
			$downloads[] = 'https://www.google.com/maps/d/u/0/kml?mid=' . $mid_q . '&forcekml=1&hl=en';
			$downloads[] = 'https://www.google.com/maps/d/u/0/kml?mid=' . $mid_q . '&forcekml=1&cid=mp';
			$downloads[] = 'https://www.google.com/maps/d/kml?mid=' . $mid_q . '&forcekml=1&cid=mp';
			$downloads[] = 'https://www.google.com/maps/d/kml?mid=' . $mid_q . '&forcekml=1&authuser=0';
		} elseif ( preg_match( '#https?://[^\s]+\.(?:kml|kmz)(?:\?[^\s]*)?$#i', $url ) ) {
			$downloads[] = $url;
		}
		if ( empty( $downloads ) ) {
			self::record_mymap_cache_error( $url, 'The My Maps URL does not contain a map ID (mid=...) and is not a direct KML/KMZ URL.' );
			return array();
		}
		$body = '';
		$last_error = '';
		foreach ( $downloads as $download ) {
			$response = wp_remote_get( $download, array( 'timeout' => 12, 'redirection' => 5, 'headers' => array( 'Accept' => 'application/vnd.google-earth.kml+xml,application/xml,text/xml,*/*', 'User-Agent' => 'Personalized-City-Tour/' . PCT_VERSION ) ) );
			if ( is_wp_error( $response ) ) { $last_error = $response->get_error_message(); continue; }
			if ( 200 !== (int) wp_remote_retrieve_response_code( $response ) ) { $last_error = 'HTTP ' . (int) wp_remote_retrieve_response_code( $response ); continue; }
			$candidate = wp_remote_retrieve_body( $response );
			$content_type = strtolower( (string) wp_remote_retrieve_header( $response, 'content-type' ) );
			if ( '' !== trim( $candidate ) && false !== stripos( $candidate, '<kml' ) ) {
				$body = $candidate;
				break;
			}
			// Google may return a KMZ archive depending on the map/export path.
			if ( false !== strpos( $content_type, 'zip' ) || false !== strpos( $content_type, 'kmz' ) ) {
				$tmp = wp_tempnam( 'pct-mymap' );
				if ( $tmp && file_put_contents( $tmp, $candidate ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
					$zip = class_exists( 'ZipArchive' ) ? new ZipArchive() : null;
					if ( $zip && true === $zip->open( $tmp ) ) {
						for ( $i = 0; $i < $zip->numFiles; $i++ ) {
							$name = $zip->getNameIndex( $i );
							if ( $name && preg_match( '/\.kml$/i', $name ) ) {
								$body = (string) $zip->getFromIndex( $i );
								break;
							}
						}
						$zip->close();
					}
					@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				}
				if ( '' !== trim( $body ) ) { break; }
			}
		}
		if ( '' === trim( $body ) || strlen( $body ) > 5000000 ) {
			self::record_mymap_cache_error( $url, $last_error ?: 'No readable KML/KMZ export was returned.' );
			if ( ! empty( $cache['available'] ) ) {
				$cached_body = @file_get_contents( $cache['file'] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
				return is_string( $cached_body ) ? self::parse_mymap_kml_body( $cached_body ) : array();
			}
			return array();
		}
		self::save_mymap_kml_cache( $url, $body, 'google' );
		return self::parse_mymap_kml_body( $body );
	}

	private static function parse_mymap_kml_body( $body ) {
		if ( '' === trim( $body ) || strlen( $body ) > 5000000 || ! function_exists( 'simplexml_load_string' ) ) { return array(); }
		libxml_use_internal_errors( true );
		$xml = simplexml_load_string( $body );
		if ( false === $xml ) { return array(); }
		$features = array();
		$placemarks = $xml->xpath( '//*[local-name()="Placemark"]' );
		foreach ( (array) $placemarks as $pm ) {
			$name = trim( (string) ( $pm->name ?? '' ) );
			$desc = trim( wp_strip_all_tags( (string) ( $pm->description ?? '' ) ) );
			$folder_nodes = $pm->xpath( 'ancestor::*[local-name()="Folder"][1]/*[local-name()="name"]' );
			$folder = $folder_nodes ? trim( (string) $folder_nodes[0] ) : '';
			$geometry = array();
			$type = 'point';
			$pointNodes = $pm->xpath( './/*[local-name()="Point"]//*[local-name()="coordinates"]' );
			$lineNodes  = $pm->xpath( './/*[local-name()="LineString"]//*[local-name()="coordinates"]' );
			if ( $lineNodes ) {
				$type = 'route';
				foreach ( $lineNodes as $node ) {
					foreach ( preg_split( '/\s+/', trim( (string) $node ) ) as $tuple ) {
						$parts2 = array_map( 'trim', explode( ',', $tuple ) );
						if ( count( $parts2 ) < 2 || ! is_numeric( $parts2[0] ) || ! is_numeric( $parts2[1] ) ) { continue; }
						$geometry[] = array( (float) $parts2[1], (float) $parts2[0] );
						if ( count( $geometry ) >= 60 ) { break 2; }
					}
				}
			} elseif ( $pointNodes ) {
				$tuple = trim( (string) $pointNodes[0] );
				$parts2 = array_map( 'trim', explode( ',', $tuple ) );
				if ( count( $parts2 ) >= 2 && is_numeric( $parts2[0] ) && is_numeric( $parts2[1] ) ) {
					$geometry[] = array( (float) $parts2[1], (float) $parts2[0] );
				}
			}
			if ( ! $geometry ) { continue; }
			$lat = (float) $geometry[0][0];
			$lng = (float) $geometry[0][1];
			if ( ! $lat && ! $lng ) { continue; }
			$features[] = array(
				'name' => $name,
				'folder' => $folder,
				'type' => $type,
				'lat' => $lat,
				'lng' => $lng,
				'geometry' => $geometry,
				'description' => wp_trim_words( $desc, 35, '…' ),
			);
			if ( count( $features ) >= 150 ) { break; }
		}
		return $features;
	}

	private static function mymap_virtual_item( array $map, array $feature, $index ) {
		$seed = (string) ( $map['url'] ?? '' ) . '|' . (string) ( $feature['name'] ?? '' ) . '|' . (string) $index;
		$id = 700000000 + ( abs( crc32( $seed ) ) % 200000000 );
		$context = trim( (string) ( $feature['folder'] ?? '' ) . ' ' . (string) ( $feature['description'] ?? '' ) );
		$categories = self::mymap_categories( $context . ' ' . (string) ( $feature['name'] ?? '' ) );
		$name = trim( (string) ( $feature['name'] ?? '' ) );
		$gmaps = 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode( (string) $feature['lat'] . ',' . (string) $feature['lng'] );
		$tags = array_values( array_filter( array_unique( array( 'Google My Maps', 'KML', (string) ( $map['label'] ?? '' ), (string) ( $feature['folder'] ?? '' ) ) ) ) );
		$summary = trim( (string) ( $feature['description'] ?? '' ) );
		if ( '' === $summary && ! empty( $feature['folder'] ) ) {
			$summary = 'Curated Google My Maps location from ' . (string) $feature['folder'] . '.';
		}
		return array(
			'id' => $id, 'type' => 'place', 'name' => $name,
			'summary' => wp_trim_words( $summary, 45, '…' ), 'categories' => $categories,
			'tags' => $tags, 'address' => '',
			'lat' => (float) $feature['lat'], 'lng' => (float) $feature['lng'], 'link' => '', 'gmaps_url' => $gmaps,
			'image' => '', 'socials' => array(), 'price_tier' => 'medium', 'accessibility' => array(),
			'duration' => 60, 'hours' => '', 'best_time' => array(), 'indoor' => '', 'family' => false,
			'links' => array( array( 'label' => __( 'Google Maps', 'personalized-city-tour' ), 'url' => $gmaps, 'kind' => 'maps' ) ),
			'mymap_virtual' => true, 'mymap_source' => 'kml', 'mymap_feature_index' => (int) $index,
			'mymap_label' => (string) ( $map['label'] ?? '' ), 'mymap_url' => (string) ( $map['url'] ?? '' ),
			'mymap_folder' => (string) ( $feature['folder'] ?? '' ), 'mymap_feature_type' => (string) ( $feature['type'] ?? 'point' ),
			'mymap_description' => (string) ( $feature['description'] ?? '' ), 'source' => 'mymap_kml',
		);
	}

	private static function mymap_categories( $text ) {
		$text = self::normalize_mymap_name( $text );
		$map = array(
			'historical' => array( 'history', 'historical', 'historic', 'old', 'heritage', 'تاریخی', 'تاریخ' ),
			'architecture' => array( 'architecture', 'architectural', 'building', 'معماری' ),
			'museums' => array( 'museum', 'موزه' ),
			'religious' => array( 'mosque', 'shrine', 'church', 'religious', 'مسجد', 'حرم', 'زیارت', 'مذهبی' ),
			'cultural' => array( 'culture', 'cultural', 'tradition', 'فرهنگ', 'فرهنگی', 'سنت', 'سنتی' ),
			'artistic' => array( 'art', 'gallery', 'artist', 'هنر', 'گالری', 'هنرمند' ),
			'markets' => array( 'market', 'bazaar', 'بازار' ),
			'shopping' => array( 'shop', 'shopping', 'store', 'فروشگاه', 'خرید', 'مرکز خرید' ),
			'food' => array( 'food', 'restaurant', 'cafe', 'coffee', 'رستوران', 'کافه', 'غذا', 'خوراک', 'قهوه' ),
			'nature' => array( 'park', 'garden', 'nature', 'باغ', 'پارک', 'طبیعت', 'سبز' ),
			'nightlife' => array( 'nightlife', 'night life', 'bar', 'club', 'شبانه', 'شب', 'کلاب' ),
			'social' => array( 'social', 'community', 'local life', 'meeting locals', 'اجتماعی', 'جامعه', 'مردم', 'محلی' ),
			'viewpoints' => array( 'view', 'viewpoint', 'panorama', 'scenic', 'sunset', 'چشم انداز', 'منظر', 'غروب', 'تماشا' ),
			'family' => array( 'family', 'children', 'kids', 'خانواده', 'کودک', 'بچه' ),
		);
		$out = array();
		foreach ( $map as $cat => $terms ) { foreach ( $terms as $term ) { if ( false !== strpos( $text, self::normalize_mymap_name( $term ) ) ) { $out[] = $cat; break; } } }
		return $out ? array_values( array_unique( $out ) ) : array();
	}

	private static function match_mymap_feature( array $feature, array $items ) {
		$name = self::normalize_mymap_name( ( $feature['name'] ?? '' ) . ' ' . ( $feature['description'] ?? '' ) );
		$best = null; $best_score = 0; $best_method = '';
		foreach ( $items as $item ) {
			$in = self::normalize_mymap_name( $item['name'] ?? '' );
			if ( '' === $in ) { continue; }
			$score = 0;
			if ( $name === $in ) { $score = 1; }
			elseif ( false !== strpos( $name, $in ) || false !== strpos( $in, $name ) ) { $score = .92; }
			else { similar_text( $name, $in, $pct ); $score = $pct / 100; }
			if ( $score > $best_score ) { $best_score = $score; $best = $item; $best_method = 'name'; }
		}
		// A My Maps point often uses a short alias while the City database uses
		// the full official name. Coordinates are therefore a second, reliable
		// matching signal. Keep the radius deliberately local to avoid unrelated
		// places in a large city.
		if ( $best_score < .78 && ! empty( $feature['lat'] ) && ! empty( $feature['lng'] ) ) {
			$nearest = null; $nearest_km = 0.65;
			foreach ( $items as $item ) {
				if ( empty( $item['lat'] ) || empty( $item['lng'] ) ) { continue; }
				$d = self::distance( (float) $feature['lat'], (float) $feature['lng'], (float) $item['lat'], (float) $item['lng'] );
				if ( $d <= $nearest_km ) { $nearest = $item; $nearest_km = $d; }
			}
			if ( $nearest ) {
				$best = $nearest;
				$best_method = 'coordinate';
				return $best;
			}
		}
		if ( $best && $best_score >= 0.82 ) {
			$best['_match_method'] = $best_method ?: 'name';
			return $best;
		}
		return null;
	}

	/** Return City-database items encountered along a My Maps line, in route order. */
	private static function route_nearby_item_ids( array $feature, array $items ) {
		$geometry = (array) ( $feature['geometry'] ?? array() );
		if ( count( $geometry ) < 2 ) { return array(); }
		$out = array();
		$last_id = 0;
		foreach ( $geometry as $point ) {
			$best = null; $best_km = 1.25;
			foreach ( $items as $item ) {
				if ( empty( $item['lat'] ) || empty( $item['lng'] ) ) { continue; }
				$d = self::distance( (float) $point[0], (float) $point[1], (float) $item['lat'], (float) $item['lng'] );
				if ( $d <= $best_km ) { $best = $item; $best_km = $d; }
			}
			if ( $best && (int) $best['id'] !== $last_id ) {
				$out[] = (int) $best['id'];
				$last_id = (int) $best['id'];
			}
			if ( count( $out ) >= 12 ) { break; }
		}
		return array_values( array_unique( $out ) );
	}

	private static function normalize_mymap_name( $value ) {
		$value = mb_strtolower( trim( wp_strip_all_tags( (string) $value ) ), 'UTF-8' );
		$value = preg_replace( '/[^\p{L}\p{N}]+/u', ' ', $value );
		return trim( preg_replace( '/\s+/u', ' ', $value ) );
	}

	public static function published_cities() {
		$out = array();
		foreach ( array_keys( PCT_Post_Types::cities() ) as $id ) {
			$out[] = self::city( $id );
		}
		return $out;
	}

	private static function item_from_post( WP_Post $post ) {
		$type = PCT_Post_Types::EVENT === $post->post_type ? 'event' : 'place';
		$cats = wp_get_post_terms( $post->ID, PCT_Post_Types::CATEGORY, array( 'fields' => 'slugs' ) );
		$tags = wp_get_post_terms( $post->ID, PCT_Post_Types::TAG, array( 'fields' => 'names' ) );
		$acc  = get_post_meta( $post->ID, '_pct_accessibility', true );

		$official = (string) get_post_meta( $post->ID, '_pct_link', true );
		$gmaps    = (string) get_post_meta( $post->ID, '_pct_gmaps_url', true );
		$image    = get_the_post_thumbnail_url( $post->ID, 'large' );
		$item = array(
			'id'            => $post->ID,
			'type'          => $type,
			'name'          => $post->post_title,
			'summary'       => wp_trim_words( wp_strip_all_tags( $post->post_content ), 45, '…' ),
			'categories'    => is_wp_error( $cats ) ? array() : array_values( $cats ),
			'tags'          => is_wp_error( $tags ) ? array() : array_values( $tags ),
			'address'       => (string) get_post_meta( $post->ID, '_pct_address', true ),
			'lat'           => (float) get_post_meta( $post->ID, '_pct_lat', true ),
			'lng'           => (float) get_post_meta( $post->ID, '_pct_lng', true ),
			'link'          => $official,
			'gmaps_url'     => $gmaps,
			'ar_url'        => (string) get_post_meta( $post->ID, '_pct_ar_url', true ),
			'ar_label'      => (string) get_post_meta( $post->ID, '_pct_ar_label', true ),
			'image'         => $image ? esc_url_raw( $image ) : '',
			'socials'       => self::links( $post->ID, '_pct_socials' ),
			'price_tier'    => (string) get_post_meta( $post->ID, '_pct_price_tier', true ),
			'accessibility' => is_array( $acc ) ? array_values( $acc ) : array(),
			'duration'      => (int) get_post_meta( $post->ID, '_pct_duration', true ),
			'hours'         => (string) get_post_meta( $post->ID, '_pct_hours', true ),
			'best_time'     => (array) get_post_meta( $post->ID, '_pct_best_time', true ),
			'indoor'        => (string) get_post_meta( $post->ID, '_pct_indoor', true ),
			'family'        => (bool) get_post_meta( $post->ID, '_pct_family', true ),
		);
		if ( 'event' === $type ) {
			$item['organizer']     = (string) get_post_meta( $post->ID, '_pct_organizer_name', true );
			$item['organizer_url'] = (string) get_post_meta( $post->ID, '_pct_organizer_url', true );
			$item['event_url']     = (string) get_post_meta( $post->ID, '_pct_event_url', true );
			$item['start']         = (string) get_post_meta( $post->ID, '_pct_start', true );
			$item['end']           = (string) get_post_meta( $post->ID, '_pct_end', true );
			$item['venue']         = (string) get_post_meta( $post->ID, '_pct_venue', true );
			if ( ! $item['link'] ) {
				$item['link'] = $item['event_url'];
			}
		}
		$item['links'] = self::best_links( $item );
		return $item;
	}

	/**
	 * Build deterministic, safe destination links. Stored links are preferred;
	 * Google Maps/Search links are fallbacks and do not require an API key.
	 */
	public static function best_links( array $item ) {
		$name    = trim( (string) ( $item['name'] ?? '' ) );
		$address = trim( (string) ( $item['address'] ?? '' ) );
		$query   = trim( $name . ( $address ? ', ' . $address : '' ) );
		$links   = array();
		$add     = function ( $label, $url, $kind = 'details' ) use ( &$links ) {
			if ( $url && filter_var( $url, FILTER_VALIDATE_URL ) ) {
				$links[] = array( 'label' => (string) $label, 'url' => esc_url_raw( $url ), 'kind' => (string) $kind );
			}
		};
		if ( ! empty( $item['link'] ) ) {
			$add( __( 'Official website', 'personalized-city-tour' ), $item['link'], 'official' );
		}
		if ( ! empty( $item['event_url'] ) && $item['event_url'] !== $item['link'] ) {
			$add( __( 'Event details', 'personalized-city-tour' ), $item['event_url'], 'event' );
		}
		if ( ! empty( $item['organizer_url'] ) && $item['organizer_url'] !== $item['link'] ) {
			$add( __( 'Organizer', 'personalized-city-tour' ), $item['organizer_url'], 'organizer' );
		}
		if ( ! empty( $item['gmaps_url'] ) ) {
			$add( __( 'Google Maps', 'personalized-city-tour' ), $item['gmaps_url'], 'maps' );
		} elseif ( $query ) {
			$add( __( 'Google Maps', 'personalized-city-tour' ), 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode( $query ), 'maps' );
		}
		if ( $query ) {
			$add( __( 'Web search', 'personalized-city-tour' ), 'https://www.google.com/search?q=' . rawurlencode( $query ), 'web' );
			$add( __( 'Photo search', 'personalized-city-tour' ), 'https://www.google.com/search?tbm=isch&q=' . rawurlencode( $query ), 'photos' );
		}
		foreach ( (array) ( $item['socials'] ?? array() ) as $social ) {
			if ( ! empty( $social['url'] ) ) {
				$add( $social['label'] ?: __( 'Social', 'personalized-city-tour' ), $social['url'], 'social' );
			}
		}
		return array_values( array_slice( $links, 0, 8 ) );
	}

	public static function places( $city_id, $limit = -1 ) {
		$posts = get_posts(
			array(
				'post_type'      => PCT_Post_Types::PLACE,
				'post_status'    => 'publish',
				'posts_per_page' => $limit,
				'meta_key'       => '_pct_city_id', // phpcs:ignore WordPress.DB.SlowDBQuery
				'meta_value'     => (int) $city_id, // phpcs:ignore WordPress.DB.SlowDBQuery
				'orderby'        => 'title',
				'order'          => 'ASC',
			)
		);
		return array_map( array( __CLASS__, 'item_from_post' ), $posts );
	}

	/**
	 * Events for a city, optionally restricted to a date window (Y-m-d strings).
	 */
	public static function events( $city_id, $from = '', $to = '', $limit = -1 ) {
		// Fetch the city's published events first and apply overlap filtering in PHP.
		// The previous meta_query required a non-empty _pct_end, which could silently
		// exclude otherwise valid one-day/open-ended events from tour generation.
		$posts = get_posts( array(
			'post_type' => PCT_Post_Types::EVENT,
			'post_status' => 'publish',
			'posts_per_page' => $limit > 0 ? $limit : -1,
			'meta_key' => '_pct_start',
			'orderby' => 'meta_value',
			'order' => 'ASC',
			'meta_query' => array( array( 'key' => '_pct_city_id', 'value' => (int) $city_id ) ), // phpcs:ignore WordPress.DB.SlowDBQuery
		) );
		$items = array_map( array( __CLASS__, 'item_from_post' ), $posts );
		if ( ! $from && ! $to ) { return $items; }
		$from_ts = $from ? strtotime( $from . ' 00:00:00' ) : null;
		$to_ts   = $to ? strtotime( $to . ' 23:59:59' ) : null;
		$items = array_values( array_filter( $items, function ( $item ) use ( $from_ts, $to_ts ) {
			$start = ! empty( $item['start'] ) ? strtotime( $item['start'] ) : false;
			$end   = ! empty( $item['end'] ) ? strtotime( $item['end'] ) : $start;
			if ( false === $start && false === $end ) { return true; }
			if ( false === $start ) { $start = $end; }
			if ( false === $end ) { $end = $start; }
			if ( null !== $from_ts && $end < $from_ts ) { return false; }
			if ( null !== $to_ts && $start > $to_ts ) { return false; }
			return true;
		} ) );
		return $limit > 0 ? array_slice( $items, 0, $limit ) : $items;
	}

	public static function item( $id ) {
		$post = get_post( $id );
		if ( ! $post || ! in_array( $post->post_type, array( PCT_Post_Types::PLACE, PCT_Post_Types::EVENT ), true ) ) {
			return null;
		}
		return self::item_from_post( $post );
	}

	/**
	 * Haversine distance in km.
	 */
	public static function distance( $lat1, $lng1, $lat2, $lng2 ) {
		if ( ! $lat1 || ! $lat2 ) {
			return 0;
		}
		$r    = 6371;
		$dlat = deg2rad( $lat2 - $lat1 );
		$dlng = deg2rad( $lng2 - $lng1 );
		$a    = sin( $dlat / 2 ) ** 2 + cos( deg2rad( $lat1 ) ) * cos( deg2rad( $lat2 ) ) * sin( $dlng / 2 ) ** 2;
		return $r * 2 * atan2( sqrt( $a ), sqrt( 1 - $a ) );
	}
}
