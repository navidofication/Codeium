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
			'link'          => (string) get_post_meta( $post->ID, '_pct_link', true ),
			'gmaps_url'     => (string) get_post_meta( $post->ID, '_pct_gmaps_url', true ),
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
		return $item;
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
		$meta_query = array(
			array(
				'key'   => '_pct_city_id',
				'value' => (int) $city_id,
			),
		);
		if ( $from ) {
			$meta_query[] = array(
				'key'     => '_pct_end',
				'value'   => $from . ' 00:00',
				'compare' => '>=',
				'type'    => 'CHAR',
			);
		}
		if ( $to ) {
			$meta_query[] = array(
				'key'     => '_pct_start',
				'value'   => $to . ' 23:59',
				'compare' => '<=',
				'type'    => 'CHAR',
			);
		}
		$posts = get_posts(
			array(
				'post_type'      => PCT_Post_Types::EVENT,
				'post_status'    => 'publish',
				'posts_per_page' => $limit,
				'meta_query'     => $meta_query, // phpcs:ignore WordPress.DB.SlowDBQuery
				'meta_key'       => '_pct_start', // phpcs:ignore WordPress.DB.SlowDBQuery
				'orderby'        => 'meta_value',
				'order'          => 'ASC',
			)
		);
		return array_map( array( __CLASS__, 'item_from_post' ), $posts );
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
