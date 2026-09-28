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
	public static function travel_mode( array $form ) {
		$modes = (array) ( $form['mobility_modes'] ?? array() );
		if ( in_array( 'walking', $modes, true ) || empty( $modes ) ) {
			return 'walking';
		}
		if ( in_array( 'bike', $modes, true ) ) {
			return 'bicycling';
		}
		if ( in_array( 'public_transport', $modes, true ) ) {
			return 'transit';
		}
		return 'driving';
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
		return add_query_arg( array_map( 'rawurlencode', $args ), 'https://www.google.com/maps/dir/' );
	}

	/**
	 * OpenStreetMap directions link (uses OSRM under the hood on osm.org).
	 */
	public static function osm_directions_url( array $stops, $mode = 'walking' ) {
		$stops = array_values( array_filter( $stops, array( __CLASS__, 'has_coords' ) ) );
		if ( count( $stops ) < 2 ) {
			return '';
		}
		$engine = 'fossgis_osrm_foot';
		if ( 'bicycling' === $mode ) {
			$engine = 'fossgis_osrm_bike';
		} elseif ( in_array( $mode, array( 'driving', 'transit' ), true ) ) {
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
			'tileUrl'     => PCT_Settings::get( 'tile_url' ),
			'attribution' => PCT_Settings::get( 'tile_attribution' ),
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
		$points = array_values( array_filter( array_map( function ( $p ) {
			return is_array( $p ) && count( $p ) >= 2 ? array( (float) $p[0], (float) $p[1] ) : null;
		}, $points ) ) );
		if ( count( $points ) < 2 ) {
			return new WP_Error( 'pct_route_points', __( 'At least two points are required.', 'personalized-city-tour' ), array( 'status' => 400 ) );
		}
		$points   = array_slice( $points, 0, 25 );
		$provider = PCT_Settings::get( 'routing_provider' );
		$cache    = 'pct_route_' . md5( $provider . $mode . wp_json_encode( $points ) );
		$hit      = get_transient( $cache );
		if ( is_array( $hit ) ) {
			return $hit;
		}
		switch ( $provider ) {
			case 'mapbox':
				$result = self::route_mapbox( $points, $mode );
				break;
			case 'google':
				$result = self::route_google( $points, $mode );
				break;
			case 'straight':
				$result = array( 'coordinates' => $points, 'distance' => 0, 'duration' => 0, 'provider' => 'straight' );
				break;
			default:
				$result = self::route_osrm( $points, $mode );
		}
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		set_transient( $cache, $result, 6 * HOUR_IN_SECONDS );
		return $result;
	}

	private static function lnglat( array $points ) {
		return implode( ';', array_map( function ( $p ) { return round( $p[1], 6 ) . ',' . round( $p[0], 6 ); }, $points ) );
	}

	private static function route_osrm( array $points, $mode ) {
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
		$data = json_decode( wp_remote_retrieve_body( $res ), true );
		if ( empty( $data['routes'][0]['geometry']['coordinates'] ) ) {
			return new WP_Error( 'pct_route_failed', __( 'No route found.', 'personalized-city-tour' ), array( 'status' => 502 ) );
		}
		return array(
			'coordinates' => array_map( function ( $c ) { return array( $c[1], $c[0] ); }, $data['routes'][0]['geometry']['coordinates'] ),
			'distance'    => (float) $data['routes'][0]['distance'],
			'duration'    => (float) $data['routes'][0]['duration'],
			'provider'    => 'osrm',
		);
	}

	private static function route_mapbox( array $points, $mode ) {
		$token = PCT_Settings::secret( 'mapbox_token' );
		if ( '' === $token ) {
			return new WP_Error( 'pct_route_config', __( 'Mapbox access token is missing.', 'personalized-city-tour' ), array( 'status' => 500 ) );
		}
		$profile = 'walking';
		if ( 'bicycling' === $mode ) {
			$profile = 'cycling';
		} elseif ( in_array( $mode, array( 'driving', 'transit' ), true ) ) {
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
