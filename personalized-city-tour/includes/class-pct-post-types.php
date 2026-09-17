<?php
/**
 * Custom post types and taxonomies.
 *
 * pct_city  - a city with map defaults and Google My Maps links.
 * pct_place - one city database entry (historical, cultural, social, artistic ...).
 * pct_event - a public event registered by admins or local guides.
 * pct_tour  - a generated personalized tour (stores form data + result).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PCT_Post_Types {

	const CITY  = 'pct_city';
	const PLACE = 'pct_place';
	const EVENT = 'pct_event';
	const TOUR  = 'pct_tour';

	const CATEGORY = 'pct_category';
	const TAG      = 'pct_tag';

	public static function init() {
		add_action( 'init', array( __CLASS__, 'register' ) );
	}

	public static function register() {
		$hidden_menu = false; // Registered under the plugin's own menu.

		register_post_type(
			self::CITY,
			array(
				'labels'          => self::labels( __( 'City', 'personalized-city-tour' ), __( 'Cities', 'personalized-city-tour' ) ),
				'public'          => false,
				'show_ui'         => true,
				'show_in_menu'    => $hidden_menu,
				'show_in_rest'    => false,
				'supports'        => array( 'title', 'editor', 'thumbnail' ),
				'capability_type' => 'page',
				'map_meta_cap'    => true,
				'menu_icon'       => 'dashicons-location-alt',
			)
		);

		register_post_type(
			self::PLACE,
			array(
				'labels'          => self::labels( __( 'City Data Entry', 'personalized-city-tour' ), __( 'City Data', 'personalized-city-tour' ) ),
				'public'          => false,
				'show_ui'         => true,
				'show_in_menu'    => $hidden_menu,
				'show_in_rest'    => false,
				'supports'        => array( 'title', 'editor', 'thumbnail', 'author' ),
				'taxonomies'      => array( self::CATEGORY, self::TAG ),
				'capability_type' => array( 'pct_place', 'pct_places' ),
				'map_meta_cap'    => true,
			)
		);

		register_post_type(
			self::EVENT,
			array(
				'labels'          => self::labels( __( 'Event', 'personalized-city-tour' ), __( 'Events', 'personalized-city-tour' ) ),
				'public'          => false,
				'show_ui'         => true,
				'show_in_menu'    => $hidden_menu,
				'show_in_rest'    => false,
				'supports'        => array( 'title', 'editor', 'thumbnail', 'author' ),
				'taxonomies'      => array( self::CATEGORY, self::TAG ),
				'capability_type' => array( 'pct_event', 'pct_events' ),
				'map_meta_cap'    => true,
			)
		);

		register_post_type(
			self::TOUR,
			array(
				'labels'          => self::labels( __( 'Tour', 'personalized-city-tour' ), __( 'Tours', 'personalized-city-tour' ) ),
				'public'          => false,
				'show_ui'         => true,
				'show_in_menu'    => $hidden_menu,
				'show_in_rest'    => false,
				'supports'        => array( 'title' ),
				'capability_type' => 'page',
				'map_meta_cap'    => true,
				'capabilities'    => array( 'create_posts' => 'do_not_allow' ),
			)
		);

		register_taxonomy(
			self::CATEGORY,
			array( self::PLACE, self::EVENT ),
			array(
				'labels'            => array(
					'name'          => __( 'Categories', 'personalized-city-tour' ),
					'singular_name' => __( 'Category', 'personalized-city-tour' ),
				),
				'hierarchical'      => true,
				'public'            => false,
				'show_ui'           => true,
				'show_admin_column' => true,
				'show_in_rest'      => false,
				'capabilities'      => array(
					'manage_terms' => 'manage_options',
					'edit_terms'   => 'manage_options',
					'delete_terms' => 'manage_options',
					'assign_terms' => 'edit_pct_places',
				),
			)
		);

		register_taxonomy(
			self::TAG,
			array( self::PLACE, self::EVENT ),
			array(
				'labels'            => array(
					'name'          => __( 'Tags', 'personalized-city-tour' ),
					'singular_name' => __( 'Tag', 'personalized-city-tour' ),
				),
				'hierarchical'      => false,
				'public'            => false,
				'show_ui'           => true,
				'show_admin_column' => true,
				'show_in_rest'      => false,
				'capabilities'      => array(
					'manage_terms' => 'manage_options',
					'edit_terms'   => 'manage_options',
					'delete_terms' => 'manage_options',
					'assign_terms' => 'edit_pct_places',
				),
			)
		);
	}

	/**
	 * Default city-data categories.
	 */
	public static function default_categories() {
		return array(
			'historical'   => __( 'Historical', 'personalized-city-tour' ),
			'cultural'     => __( 'Cultural', 'personalized-city-tour' ),
			'social'       => __( 'Social', 'personalized-city-tour' ),
			'artistic'     => __( 'Artistic', 'personalized-city-tour' ),
			'architecture' => __( 'Architecture', 'personalized-city-tour' ),
			'food'         => __( 'Food & Drink', 'personalized-city-tour' ),
			'shopping'     => __( 'Shopping', 'personalized-city-tour' ),
			'nature'       => __( 'Nature & Parks', 'personalized-city-tour' ),
			'nightlife'    => __( 'Nightlife', 'personalized-city-tour' ),
			'family'       => __( 'Family', 'personalized-city-tour' ),
			'religious'    => __( 'Religious', 'personalized-city-tour' ),
			'markets'      => __( 'Markets', 'personalized-city-tour' ),
			'museums'      => __( 'Museums', 'personalized-city-tour' ),
			'viewpoints'   => __( 'Viewpoints', 'personalized-city-tour' ),
		);
	}

	public static function insert_default_terms() {
		if ( ! taxonomy_exists( self::CATEGORY ) ) {
			self::register();
		}
		foreach ( self::default_categories() as $slug => $name ) {
			if ( ! term_exists( $slug, self::CATEGORY ) ) {
				wp_insert_term( $name, self::CATEGORY, array( 'slug' => $slug ) );
			}
		}
	}

	private static function labels( $singular, $plural ) {
		return array(
			'name'               => $plural,
			'singular_name'      => $singular,
			/* translators: %s: singular label */
			'add_new_item'       => sprintf( __( 'Add New %s', 'personalized-city-tour' ), $singular ),
			/* translators: %s: singular label */
			'edit_item'          => sprintf( __( 'Edit %s', 'personalized-city-tour' ), $singular ),
			/* translators: %s: singular label */
			'new_item'           => sprintf( __( 'New %s', 'personalized-city-tour' ), $singular ),
			/* translators: %s: singular label */
			'view_item'          => sprintf( __( 'View %s', 'personalized-city-tour' ), $singular ),
			/* translators: %s: plural label */
			'search_items'       => sprintf( __( 'Search %s', 'personalized-city-tour' ), $plural ),
			/* translators: %s: plural label */
			'not_found'          => sprintf( __( 'No %s found.', 'personalized-city-tour' ), strtolower( $plural ) ),
			/* translators: %s: plural label */
			'not_found_in_trash' => sprintf( __( 'No %s found in Trash.', 'personalized-city-tour' ), strtolower( $plural ) ),
			'all_items'          => $plural,
			'menu_name'          => $plural,
		);
	}

	/**
	 * Helper: list of cities as id => title.
	 */
	public static function cities() {
		$posts = get_posts(
			array(
				'post_type'      => self::CITY,
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'orderby'        => 'title',
				'order'          => 'ASC',
			)
		);
		$out   = array();
		foreach ( $posts as $p ) {
			$out[ $p->ID ] = $p->post_title;
		}
		return $out;
	}
}
