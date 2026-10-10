<?php
/**
 * Map & routing helpers. The interactive map itself is rendered client side
 * (Leaflet + OSRM, or Google Maps when configured); this class builds deep links.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PCT_Maps {

	/**
	 * Map travel modes from the preference form to routing profiles.
	 */
	public static function normalize_travel_mode( $mode ) {
		$key = sanitize_key( (string) $mode );
		$map = array(
			'walking'          => 'walking',
			'walk'             => 'walking',
			'bike'             => 'bicycling',
			'bicycle'          => 'bicycling',
			'bicycling'        => 'bicycling',
			'public_transport' => 'transit',
			'public-transit'   => 'transit',
			'transit'          => 'transit',
			'taxi'             => 'driving',
			'car'              => 'driving',
			'driving'          => 'driving',
		);
		return isset( $map[ $key ] ) ? $map[ $key ] : '';
	}

	/** Return normalized traveller-selected mobility modes in stable preference order. */
	public static function travel_modes( array $form ) {
		$raw = (array) ( $form['mobility_modes'] ?? array() );
		$modes = array();
		foreach ( $raw as $value ) {
			$mode = self::normalize_travel_mode( $value );
			if ( $mode && ! in_array( $mode, $modes, true ) ) {
				$modes[] = $mode;
			}
		}
		return $modes ? $modes : array( 'walking' );
	}

	/** Select the preferred primary mode; walking is the default for a half-day tour. */
	public static function travel_mode( array $form ) {
		$modes = self::travel_modes( $form );
		foreach ( array( 'walking', 'transit', 'bicycling', 'driving' ) as $preferred ) {
			if ( in_array( $preferred, $modes, true ) ) {
				return $preferred;
			}
		}
		return 'walking';
	}

	private static function coord( $stop ) {
		return round( (float) $stop['lat'], 6 ) . ',' . round( (float) $stop['lng'], 6 );
	}

	/**
	 * Google Maps directions URL (universal cross-platform link).
	 */
	public static function google_directions_url( array $stops, $mode = 'walking' ) {
		$stops = array_values( array_filter( $stops, array( __CLASS__, 'has_coords' ) ) );
		if ( count( $stops ) < 1 ) {
			return '';
		}
		$dest      = array_pop( $stops );
		$args      = array(
			'api'         => 1,
			'destination' => self::coord( $dest ),
			'travelmode'  => $mode,
		);
		if ( $stops ) {
			$origin         = array_shift( $stops );
			$args['origin'] = self::coord( $origin );
			if ( $stops ) {
				$args['waypoints'] = implode( '|', array_map( array( __CLASS__, 'coord' ), $stops ) );
			}
		}
		return add_query_arg( $args, 'https://www.google.com/maps/dir/' );
	}

	/**
	 * OpenStreetMap directions link (uses OSRM under the hood on osm.org).
	 */
	public static function osm_directions_url( array $stops, $mode = 'walking' ) {
		$stops = array_values( array_filter( $stops, array( __CLASS__, 'has_coords' ) ) );
		if ( count( $stops ) < 2 ) {
			return '';
		}
		if ( 'transit' === $mode ) {
			return '';
		}
		$engine = 'fossgis_osrm_foot';
		if ( 'bicycling' === $mode ) {
			$engine = 'fossgis_osrm_bike';
		} elseif ( 'driving' === $mode ) {
			$engine = 'fossgis_osrm_car';
		}
		$first = reset( $stops );
		$last  = end( $stops );
		return 'https://www.openstreetmap.org/directions?engine=' . $engine . '&route=' . rawurlencode( self::coord( $first ) . ';' . self::coord( $last ) );
	}

	public static function has_coords( $stop ) {
		return ! empty( $stop['lat'] ) && ! empty( $stop['lng'] );
	}

	/**
	 * "Open in Google Maps" link for a stop: the Google Maps location link stored
	 * in the city data entry wins; otherwise a coordinate search URL is built.
	 */
	public static function item_google_url( array $item ) {
		if ( ! empty( $item['gmaps_url'] ) ) {
			return $item['gmaps_url'];
		}
		if ( self::has_coords( $item ) ) {
			return 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode( self::coord( $item ) );
		}
		if ( ! empty( $item['name'] ) ) {
			return 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode( $item['name'] . ( ! empty( $item['address'] ) ? ' ' . $item['address'] : '' ) );
		}
		return '';
	}

	/**
	 * Site search page used by quick search hints (settings: search_page_url;
	 * defaults to the WordPress search).
	 */
	public static function search_url( $query = '' ) {
		$base = (string) PCT_Settings::get( 'search_page_url', '' );
		if ( '' === $base ) {
			$base = home_url( '/' );
		}
		if ( '' === $query ) {
			return $base;
		}
		return add_query_arg( 's', rawurlencode( $query ), $base );
	}

	/**
	 * Client configuration shared with the frontend script.
	 */
	public static function client_config() {
		$map     = PCT_Settings::get( 'map_provider' );
		$routing = PCT_Settings::get( 'routing_provider' );
		return array(
			'provider'    => $map,
			'routing'     => $routing,
			'osrm'        => PCT_Settings::get( 'osrm_endpoint' ),
			'valhalla'    => PCT_Settings::get( 'valhalla_endpoint' ),
			'tileUrl'     => PCT_Settings::get( 'tile_url' ),
			'attribution' => PCT_Settings::get( 'tile_attribution' ),
			'tileFallbacks' => array(
				'https://{s}.tile.openstreetmap.fr/osmfr/{z}/{x}/{y}.png',
				'https://{s}.basemaps.cartocdn.com/light_all/{z}/{x}/{y}{r}.png',
			),
			'tileFallbackAttributions' => array(
				'&copy; OpenStreetMap France contributors',
				'&copy; OpenStreetMap contributors &copy; CARTO',
			),
			// Keys are only sent to the browser when they are needed for tile display;
			// routing always goes through the /route proxy.
			'googleKey'   => 'google' === $map ? PCT_Settings::secret( 'google_maps_api_key' ) : '',
			'mapboxToken' => 'mapbox' === $map ? PCT_Settings::secret( 'mapbox_token' ) : '',
			'routeUrl'    => rest_url( PCT_Rest::NS . '/route' ),
			'searchUrl'   => self::search_url(),
		);
	}

	/**
	 * Server-side routing proxy so provider tokens never need to be exposed when
	 * only routing (not tiles) is delegated to Google/Mapbox.
	 *
	 * @param array  $points [[lat,lng], ...]
	 * @param string $mode   walking|bicycling|driving|transit
	 * @return array|WP_Error { coordinates: [[lat,lng]...], distance, duration }
	 */
	public static function route( array $points, $mode = 'walking' ) {
		$mode = self::normalize_travel_mode( $mode );
		if ( '' === $mode ) {
			return new WP_Error( 'pct_route_mode_unsupported', __( 'This travel mode is not supported by the configured routing integration.', 'personalized-city-tour' ), array( 'status' => 422 ) );
		}
		$points = array_values( array_filter( array_map( function ( $p ) {
			return is_array( $p ) && count( $p ) >= 2 ? array( (float) $p[0], (float) $p[1] ) : null;
		}, $points ) ) );
		if ( count( $points ) < 2 ) {
			return new WP_Error( 'pct_route_points', __( 'At least two points are required.', 'personalized-city-tour' ), array( 'status' => 400 ) );
		}
		$points   = array_slice( $points, 0, 25 );
		$provider = PCT_Settings::get( 'routing_provider' );
		$rounded  = array_map( function ( $p ) { return array( round( $p[0], 5 ), round( $p[1], 5 ) ); }, $points );
		$cache    = 'pct_route_' . md5( $provider . '|' . $mode . '|' . PCT_Settings::get( 'osrm_endpoint' ) . '|' . PCT_Settings::get( 'valhalla_endpoint' ) . '|' . wp_json_encode( $rounded ) );
		$hit      = get_transient( $cache );
		if ( is_array( $hit ) ) {
			PCT_AI_Log::log( array( 'kind' => 'routing', 'provider' => $provider, 'status' => 'cache_hit', 'title' => 'Route cache hit', 'error' => wp_json_encode( array( 'mode' => $mode, 'point_count' => count( $points ) ), JSON_UNESCAPED_UNICODE ) ) );
			return $hit;
		}
		$started = microtime( true );
		switch ( $provider ) {
			case 'valhalla':
				$result = self::route_valhalla( $points, $mode );
				break;
			case 'mapbox':
				$result = self::route_mapbox( $points, $mode );
				break;
			case 'google':
				$result = self::route_google( $points, $mode );
				break;
			case 'straight':
				$result = new WP_Error( 'pct_route_provider_unsupported', __( 'Straight-line mode is disabled for navigable tour routes. Configure a routing provider that supports the selected travel mode.', 'personalized-city-tour' ), array( 'status' => 422 ) );
				break;
			default:
				$result = self::route_osrm( $points, $mode );
		}
		$elapsed = (int) round( ( microtime( true ) - $started ) * 1000 );
		if ( is_wp_error( $result ) ) {
			PCT_AI_Log::log( array( 'kind' => 'routing', 'provider' => $provider, 'status' => 'failed', 'duration_ms' => $elapsed, 'title' => 'Route calculation failed', 'error' => wp_json_encode( array( 'mode' => $mode, 'point_count' => count( $points ), 'code' => $result->get_error_code(), 'message' => $result->get_error_message() ), JSON_UNESCAPED_UNICODE ) ) );
			return $result;
		}
		PCT_AI_Log::log( array( 'kind' => 'routing', 'provider' => $provider, 'status' => 'completed', 'duration_ms' => $elapsed, 'title' => 'Route calculated', 'error' => wp_json_encode( array( 'mode' => $mode, 'point_count' => count( $points ), 'distance_m' => (float) ( $result['distance'] ?? 0 ), 'duration_s' => (float) ( $result['duration'] ?? 0 ), 'cache' => false ), JSON_UNESCAPED_UNICODE ) ) );
		set_transient( $cache, $result, 6 * HOUR_IN_SECONDS );
		return $result;
	}

	private static function lnglat( array $points ) {
		return implode( ';', array_map( function ( $p ) { return round( $p[1], 6 ) . ',' . round( $p[0], 6 ); }, $points ) );
	}

	/** Route using Valhalla's pedestrian, bicycle, auto or multimodal costing. */
	private static function route_valhalla( array $points, $mode ) {
		$costing = array( 'walking' => 'pedestrian', 'bicycling' => 'bicycle', 'driving' => 'auto', 'transit' => 'multimodal' );
		if ( ! isset( $costing[ $mode ] ) ) {
			return new WP_Error( 'pct_route_mode_unsupported', __( 'This travel mode is not supported by Valhalla.', 'personalized-city-tour' ), array( 'status' => 422 ) );
		}
		$locations = array_map( function ( $p ) { return array( 'lat' => $p[0], 'lon' => $p[1] ); }, $points );
		$body = array( 'locations' => $locations, 'costing' => $costing[ $mode ], 'units' => 'kilometers', 'shape_format' => 'polyline6' );
		if ( 'transit' === $mode ) { $body['date_time'] = array( 'type' => 0 ); }
		$endpoint = untrailingslashit( (string) PCT_Settings::get( 'valhalla_endpoint' ) );
		if ( '' === $endpoint ) { return new WP_Error( 'pct_route_config', __( 'Valhalla endpoint is missing.', 'personalized-city-tour' ), array( 'status' => 500 ) ); }
		$res = wp_remote_post( $endpoint . '/route', array( 'timeout' => 25, 'headers' => array( 'Content-Type' => 'application/json', 'X-Client-Id' => 'rahimghazi.ir-personalized-city-tour' ), 'body' => wp_json_encode( $body ) ) );
		if ( is_wp_error( $res ) ) { return $res; }
		$http = (int) wp_remote_retrieve_response_code( $res );
		$data = json_decode( wp_remote_retrieve_body( $res ), true );
		$legs = $data['trip']['legs'] ?? array();
		if ( $http < 200 || $http >= 300 || ! $legs ) {
			$message = sanitize_text_field( (string) ( $data['error'] ?? $data['status_message'] ?? '' ) );
			if ( '' === $message ) { $message = __( 'Valhalla could not find a route for these stops and this travel mode.', 'personalized-city-tour' ); }
			return new WP_Error( 'pct_route_failed', $message, array( 'status' => 502 ) );
		}
		$coordinates = array(); $distance_km = 0.0; $duration = 0.0;
		foreach ( $legs as $leg ) {
			if ( empty( $leg['shape'] ) ) { continue; }
			$shape = self::decode_valhalla_polyline( $leg['shape'] );
			if ( $coordinates && $shape ) { array_shift( $shape ); }
			$coordinates = array_merge( $coordinates, $shape );
			$distance_km += (float) ( $leg['summary']['length'] ?? 0 );
			$duration += (float) ( $leg['summary']['time'] ?? 0 );
		}
		if ( count( $coordinates ) < 2 ) { return new WP_Error( 'pct_route_geometry_missing', __( 'Valhalla returned no usable route geometry.', 'personalized-city-tour' ), array( 'status' => 502 ) ); }
		return array( 'coordinates' => $coordinates, 'distance' => $distance_km * 1000, 'duration' => $duration, 'provider' => 'valhalla' );
	}

	/** Decode Valhalla polyline6 into [[lat,lng], ...]. */
	private static function decode_valhalla_polyline( $encoded ) {
		$points = array(); $index = 0; $lat = 0; $lng = 0; $length = strlen( $encoded );
		while ( $index < $length ) {
			$values = array();
			foreach ( array( 'lat', 'lng' ) as $axis ) {
				$result = 0; $shift = 0;
				do { if ( $index >= $length ) { break 2; } $b = ord( $encoded[ $index++ ] ) - 63; $result |= ( $b & 0x1f ) << $shift; $shift += 5; } while ( $b >= 0x20 );
				$delta = ( $result & 1 ) ? ~( $result >> 1 ) : ( $result >> 1 );
				if ( 'lat' === $axis ) { $lat += $delta; } else { $lng += $delta; }
			}
			$points[] = array( $lat / 1e6, $lng / 1e6 );
		}
		return $points;
	}

	private static function route_osrm( array $points, $mode ) {
		if ( 'transit' === $mode ) {
			return new WP_Error( 'pct_route_mode_unsupported', __( 'OSRM does not calculate public-transit itineraries. Choose Google Routes with transit enabled or use a dedicated transit routing provider.', 'personalized-city-tour' ), array( 'status' => 422 ) );
		}
		$profile = 'foot';
		if ( 'bicycling' === $mode ) {
			$profile = 'bike';
		} elseif ( in_array( $mode, array( 'driving', 'transit' ), true ) ) {
			$profile = 'driving';
		}
		$url = untrailingslashit( PCT_Settings::get( 'osrm_endpoint' ) ) . '/route/v1/' . $profile . '/' . self::lnglat( $points ) . '?overview=full&geometries=geojson';
		$res = wp_remote_get( $url, array( 'timeout' => 20 ) );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$http_status = (int) wp_remote_retrieve_response_code( $res );
		$data        = json_decode( wp_remote_retrieve_body( $res ), true );
		if ( $http_status < 200 || $http_status >= 300 || empty( $data['routes'][0]['geometry']['coordinates'] ) ) {
			$reason = sanitize_text_field( (string) ( $data['message'] ?? $data['code'] ?? '' ) );
			if ( '' === $reason ) {
				$reason = ( 'walking' === $mode || 'bicycling' === $mode || 'transit' === $mode )
					? __( 'The configured OSRM server may not support this travel mode. Use a routing server configured for the selected profile, or select a compatible provider.', 'personalized-city-tour' )
					: __( 'No road route was found for these stops.', 'personalized-city-tour' );
			}
			PCT_AI_Log::log( array( 'kind' => 'routing', 'provider' => 'osrm', 'status' => 'error', 'title' => 'Route calculation failed', 'error' => wp_json_encode( array( 'mode' => $mode, 'http_status' => $http_status, 'reason' => $reason, 'point_count' => count( $points ) ), JSON_UNESCAPED_UNICODE ) ) );
			return new WP_Error( 'pct_route_failed', $reason, array( 'status' => 502 ) );
		}
		return array(
			'coordinates' => array_map( function ( $c ) { return array( $c[1], $c[0] ); }, $data['routes'][0]['geometry']['coordinates'] ),
			'distance'    => (float) $data['routes'][0]['distance'],
			'duration'    => (float) $data['routes'][0]['duration'],
			'provider'    => 'osrm',
		);
	}

	private static function route_mapbox( array $points, $mode ) {
		if ( 'transit' === $mode ) {
			return new WP_Error( 'pct_route_mode_unsupported', __( 'Mapbox Directions does not provide public-transit routing in this integration. Select Google Routes with transit enabled or choose another supported travel mode.', 'personalized-city-tour' ), array( 'status' => 422 ) );
		}
		$token = PCT_Settings::secret( 'mapbox_token' );
		if ( '' === $token ) {
			return new WP_Error( 'pct_route_config', __( 'Mapbox access token is missing.', 'personalized-city-tour' ), array( 'status' => 500 ) );
		}
		$profile = 'walking';
		if ( 'bicycling' === $mode ) {
			$profile = 'cycling';
		} elseif ( 'driving' === $mode ) {
			$profile = 'driving';
		}
		$url = 'https://api.mapbox.com/directions/v5/mapbox/' . $profile . '/' . self::lnglat( $points ) . '?geometries=geojson&overview=full&access_token=' . rawurlencode( $token );
		$res = wp_remote_get( $url, array( 'timeout' => 20 ) );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$data = json_decode( wp_remote_retrieve_body( $res ), true );
		if ( empty( $data['routes'][0]['geometry']['coordinates'] ) ) {
			return new WP_Error( 'pct_route_failed', isset( $data['message'] ) ? $data['message'] : __( 'No route found.', 'personalized-city-tour' ), array( 'status' => 502 ) );
		}
		return array(
			'coordinates' => array_map( function ( $c ) { return array( $c[1], $c[0] ); }, $data['routes'][0]['geometry']['coordinates'] ),
			'distance'    => (float) $data['routes'][0]['distance'],
			'duration'    => (float) $data['routes'][0]['duration'],
			'provider'    => 'mapbox',
		);
	}

	private static function route_google( array $points, $mode ) {
		$key = PCT_Settings::secret( 'google_maps_api_key' );
		if ( '' === $key ) {
			return new WP_Error( 'pct_route_config', __( 'Google Maps API key is missing.', 'personalized-city-tour' ), array( 'status' => 500 ) );
		}
		$google_mode = array( 'walking' => 'WALK', 'bicycling' => 'BICYCLE', 'driving' => 'DRIVE', 'transit' => 'TRANSIT' );
		$wp          = function ( $p ) {
			return array( 'location' => array( 'latLng' => array( 'latitude' => $p[0], 'longitude' => $p[1] ) ) );
		};
		$body = array(
			'origin'        => $wp( $points[0] ),
			'destination'   => $wp( end( $points ) ),
			'travelMode'    => $google_mode[ $mode ] ?? 'WALK',
			'polylineQuality' => 'OVERVIEW',
		);
		$mid = array_slice( $points, 1, -1 );
		if ( $mid ) {
			$body['intermediates'] = array_map( $wp, $mid );
		}
		$res = wp_remote_post(
			'https://routes.googleapis.com/directions/v2:computeRoutes',
			array(
				'timeout' => 20,
				'headers' => array(
					'Content-Type'     => 'application/json',
					'X-Goog-Api-Key'   => $key,
					'X-Goog-FieldMask' => 'routes.distanceMeters,routes.duration,routes.polyline.encodedPolyline',
				),
				'body'    => wp_json_encode( $body ),
			)
		);
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$data = json_decode( wp_remote_retrieve_body( $res ), true );
		if ( empty( $data['routes'][0]['polyline']['encodedPolyline'] ) ) {
			return new WP_Error( 'pct_route_failed', isset( $data['error']['message'] ) ? $data['error']['message'] : __( 'No route found.', 'personalized-city-tour' ), array( 'status' => 502 ) );
		}
		return array(
			'coordinates' => self::decode_polyline( $data['routes'][0]['polyline']['encodedPolyline'] ),
			'distance'    => (float) ( $data['routes'][0]['distanceMeters'] ?? 0 ),
			'duration'    => (float) rtrim( (string) ( $data['routes'][0]['duration'] ?? '0s' ), 's' ),
			'provider'    => 'google',
		);
	}

	/**
	 * Decode a Google encoded polyline to [[lat,lng], ...].
	 */
	public static function decode_polyline( $encoded ) {
		$points = array();
		$index  = 0;
		$lat    = 0;
		$lng    = 0;
		$len    = strlen( $encoded );
		while ( $index < $len ) {
			foreach ( array( 'lat', 'lng' ) as $var ) {
				$shift  = 0;
				$result = 0;
				do {
					$b       = ord( $encoded[ $index++ ] ) - 63;
					$result |= ( $b & 0x1f ) << $shift;
					$shift  += 5;
				} while ( $b >= 0x20 && $index < $len );
				$delta = ( $result & 1 ) ? ~( $result >> 1 ) : ( $result >> 1 );
				if ( 'lat' === $var ) {
					$lat += $delta;
				} else {
					$lng += $delta;
				}
			}
			$points[] = array( $lat / 1e5, $lng / 1e5 );
		}
		return $points;
	}
}
