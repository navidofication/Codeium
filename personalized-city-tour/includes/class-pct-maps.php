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
	 * Client configuration shared with the frontend script.
	 */
	public static function client_config() {
		return array(
			'provider'    => PCT_Settings::get( 'map_provider' ),
			'routing'     => PCT_Settings::get( 'routing_provider' ),
			'osrm'        => PCT_Settings::get( 'osrm_endpoint' ),
			'tileUrl'     => PCT_Settings::get( 'tile_url' ),
			'attribution' => PCT_Settings::get( 'tile_attribution' ),
			'googleKey'   => 'google' === PCT_Settings::get( 'map_provider' ) || 'google' === PCT_Settings::get( 'routing_provider' ) ? PCT_Settings::get( 'google_maps_api_key' ) : '',
		);
	}
}
