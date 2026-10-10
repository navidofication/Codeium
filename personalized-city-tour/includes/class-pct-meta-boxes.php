<?php
/**
 * Meta boxes for cities, city data entries, events and generated tours.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PCT_Meta_Boxes {

	public static function init() {
		add_action( 'add_meta_boxes', array( __CLASS__, 'register' ) );
		add_action( 'save_post_' . PCT_Post_Types::CITY, array( __CLASS__, 'save_city' ), 10, 2 );
		add_action( 'save_post_' . PCT_Post_Types::PLACE, array( __CLASS__, 'save_item' ), 10, 2 );
		add_action( 'save_post_' . PCT_Post_Types::EVENT, array( __CLASS__, 'save_item' ), 10, 2 );
		add_action( 'post_submitbox_misc_actions', array( __CLASS__, 'guide_notice' ) );
		foreach ( array( PCT_Post_Types::CITY, PCT_Post_Types::PLACE, PCT_Post_Types::EVENT ) as $type ) {
			add_action( 'save_post_' . $type, array( 'PCT_Tour_Generator', 'flush_cache' ), 20, 0 );
		}
		add_action( 'deleted_post', array( __CLASS__, 'flush_on_delete' ), 10, 2 );
	}

	public static function flush_on_delete( $post_id, $post = null ) {
		$post = $post ? $post : get_post( $post_id );
		if ( $post && in_array( $post->post_type, array( PCT_Post_Types::CITY, PCT_Post_Types::PLACE, PCT_Post_Types::EVENT ), true ) ) {
			PCT_Tour_Generator::flush_cache();
		}
	}

	public static function register() {
		add_meta_box( 'pct_city_details', __( 'City details & maps', 'personalized-city-tour' ), array( __CLASS__, 'render_city' ), PCT_Post_Types::CITY, 'normal', 'high' );
		add_meta_box( 'pct_place_details', __( 'Entry details', 'personalized-city-tour' ), array( __CLASS__, 'render_place' ), PCT_Post_Types::PLACE, 'normal', 'high' );
		add_meta_box( 'pct_event_details', __( 'Event details', 'personalized-city-tour' ), array( __CLASS__, 'render_event' ), PCT_Post_Types::EVENT, 'normal', 'high' );
		add_meta_box( 'pct_tour_details', __( 'Personal tour message', 'personalized-city-tour' ), array( __CLASS__, 'render_tour' ), PCT_Post_Types::TOUR, 'normal', 'high' );
	}

	public static function guide_notice( $post ) {
		if ( in_array( $post->post_type, array( PCT_Post_Types::PLACE, PCT_Post_Types::EVENT ), true ) && ! current_user_can( 'publish_pct_events' ) ) {
			echo '<div class="misc-pub-section pct-guide-notice">' . esc_html__( 'Your submission will be reviewed by an administrator before it becomes visible.', 'personalized-city-tour' ) . '</div>';
		}
	}

	/* ---------------------------------------------------------------- */

	private static function field( $id, $label, $input, $help = '' ) {
		echo '<tr><th scope="row"><label for="' . esc_attr( $id ) . '">' . esc_html( $label ) . '</label></th><td>' . $input; // phpcs:ignore WordPress.Security.EscapeOutput
		if ( $help ) {
			echo '<p class="description">' . esc_html( $help ) . '</p>';
		}
		echo '</td></tr>';
	}

	private static function text( $id, $value, $type = 'text', $attrs = '' ) {
		return sprintf( '<input type="%s" id="%s" name="%s" value="%s" class="regular-text" %s />', esc_attr( $type ), esc_attr( $id ), esc_attr( $id ), esc_attr( $value ), $attrs );
	}

	private static function select( $id, $value, array $options, $empty = '' ) {
		$html = '<select id="' . esc_attr( $id ) . '" name="' . esc_attr( $id ) . '">';
		if ( '' !== $empty ) {
			$html .= '<option value="">' . esc_html( $empty ) . '</option>';
		}
		foreach ( $options as $k => $label ) {
			$html .= '<option value="' . esc_attr( $k ) . '" ' . selected( (string) $value, (string) $k, false ) . '>' . esc_html( $label ) . '</option>';
		}
		return $html . '</select>';
	}

	private static function checkboxes( $id, array $values, array $options ) {
		$html = '<fieldset class="pct-checkboxes">';
		foreach ( $options as $k => $label ) {
			$html .= '<label><input type="checkbox" name="' . esc_attr( $id ) . '[]" value="' . esc_attr( $k ) . '" ' . checked( in_array( $k, $values, true ), true, false ) . ' /> ' . esc_html( $label ) . '</label>';
		}
		return $html . '</fieldset>';
	}

	private static function map_picker( $lat, $lng, $city_id = 0 ) {
		$city = $city_id ? PCT_Data::city( $city_id ) : null;
		return '<div class="pct-map-picker" data-lat="' . esc_attr( $lat ) . '" data-lng="' . esc_attr( $lng ) . '" data-city-lat="' . esc_attr( $city ? $city['lat'] : '' ) . '" data-city-lng="' . esc_attr( $city ? $city['lng'] : '' ) . '">'
			. '<div class="pct-map-search"><input type="text" class="regular-text pct-geocode-input" placeholder="' . esc_attr__( 'Search an address…', 'personalized-city-tour' ) . '" /> <button type="button" class="button pct-geocode-btn">' . esc_html__( 'Find', 'personalized-city-tour' ) . '</button><div class="pct-geocode-results"></div></div>'
			. '<div class="pct-map-canvas"></div>'
			. '<p class="description">' . esc_html__( 'Click on the map to set the exact position, or drag the marker.', 'personalized-city-tour' ) . '</p></div>';
	}

	/* ---------------------------------------------------------------- */

	public static function render_city( $post ) {
		wp_nonce_field( 'pct_save_meta', 'pct_meta_nonce' );
		$m = function ( $k, $d = '' ) use ( $post ) {
			$v = get_post_meta( $post->ID, $k, true );
			return '' === $v ? $d : $v;
		};
		if ( isset( $_GET['pct_mymap_refresh'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$refresh = sanitize_text_field( wp_unslash( $_GET['pct_mymap_refresh'] ) ); // phpcs:ignore WordPress.Security.NonceVerification
			echo '<div class="notice notice-info inline"><p>' . esc_html( sprintf( __( 'My Maps KML database refresh result: %s.', 'personalized-city-tour' ), $refresh ) ) . '</p></div>';
		}
		echo '<table class="form-table pct-form-table">';
		self::field( '_pct_country', __( 'Country', 'personalized-city-tour' ), self::text( '_pct_country', $m( '_pct_country' ) ) );
		self::field( '_pct_timezone', __( 'Time zone', 'personalized-city-tour' ), self::text( '_pct_timezone', $m( '_pct_timezone' ) ), __( 'e.g. Europe/Rome', 'personalized-city-tour' ) );
		self::field( '_pct_lat', __( 'Centre latitude', 'personalized-city-tour' ), self::text( '_pct_lat', $m( '_pct_lat' ), 'text', 'data-pct="lat"' ) );
		self::field( '_pct_lng', __( 'Centre longitude', 'personalized-city-tour' ), self::text( '_pct_lng', $m( '_pct_lng' ), 'text', 'data-pct="lng"' ) );
		self::field( '_pct_zoom', __( 'Default zoom', 'personalized-city-tour' ), self::text( '_pct_zoom', $m( '_pct_zoom', 13 ), 'number', 'min="3" max="19"' ) );
		self::field( 'pct_map', __( 'Map position', 'personalized-city-tour' ), self::map_picker( $m( '_pct_lat' ), $m( '_pct_lng' ) ) );
		self::field( '_pct_gmaps_url', __( 'Google Maps location link', 'personalized-city-tour' ), self::text( '_pct_gmaps_url', $m( '_pct_gmaps_url' ), 'url', 'class="large-text" dir="ltr"' ), __( 'Public Google Maps link of the city (https://maps.app.goo.gl/… or https://www.google.com/maps/place/…). Used as fallback for "Open in Google Maps".', 'personalized-city-tour' ) );
		self::field( '_pct_ar_url', __( 'Augmented reality service link', 'personalized-city-tour' ), self::text( '_pct_ar_url', $m( '_pct_ar_url' ), 'url', 'class="large-text" dir="ltr"' ), __( 'Optional public AR experience URL for this city. It can point to your own AR web app or a compatible AR service; no app download is assumed.', 'personalized-city-tour' ) );
		self::field( '_pct_ar_label', __( 'AR button label', 'personalized-city-tour' ), self::text( '_pct_ar_label', $m( '_pct_ar_label', __( 'Augmented reality', 'personalized-city-tour' ) ) ), __( 'Optional label shown to visitors.', 'personalized-city-tour' ) );
		echo '</table>';

		echo '<h3>' . esc_html__( 'City Channels', 'personalized-city-tour' ) . '</h3>';
		echo '<p class="description">' . esc_html__( 'Public channels of the city (Telegram, Instagram, official tourism site, event calendars…). They are shown to travellers under the quick search hints and included in the AI input when city-database consent is enabled.', 'personalized-city-tour' ) . '</p>';
		self::links_repeater( 'pct-channels', '_pct_channels', (array) get_post_meta( $post->ID, '_pct_channels', true ), __( 'Channel URL', 'personalized-city-tour' ), __( 'Add another channel', 'personalized-city-tour' ) );

		echo '<h3>' . esc_html__( 'Google My Maps', 'personalized-city-tour' ) . '</h3>';
		echo '<p class="description">' . esc_html__( 'Paste share links of customised Google My Maps for this city (https://www.google.com/maps/d/…?mid=…). They are used as curated route sources; public map links are shown only when the public My Maps setting is enabled.', 'personalized-city-tour' ) . '</p>';
		self::links_repeater( 'pct-mymaps', '_pct_mymaps', (array) get_post_meta( $post->ID, '_pct_mymaps', true ), __( 'My Maps URL', 'personalized-city-tour' ), __( 'Add another map', 'personalized-city-tour' ) );
		$map_rows = PCT_Data::mymaps( $post->ID );
		if ( $map_rows ) {
			foreach ( $map_rows as $map_row ) {
				$cache = PCT_Data::mymap_cache_status( $map_row['url'] );
				if ( ! empty( $cache['available'] ) ) {
					$when = ! empty( $cache['cached_at'] ) ? wp_date( 'Y-m-d H:i', (int) $cache['cached_at'] ) : '';
					$class = ! empty( $cache['stale'] ) ? 'pct-mymap-cache-stale' : 'pct-mymap-cache-ok';
					$count = isset( $cache['feature_count'] ) ? absint( $cache['feature_count'] ) : 0;
					echo '<p class="description ' . esc_attr( $class ) . '">' . esc_html( sprintf( __( 'KML database: cached%s%s · %d map features.', 'personalized-city-tour' ), $when ? ' · ' . $when : '', ! empty( $cache['stale'] ) ? ' · ' . __( 'update recommended', 'personalized-city-tour' ) : '', $count ) ) . '</p>';
				} else {
					echo '<p class="description pct-mymap-cache-error">' . esc_html__( 'KML database: not cached yet. Refresh it after making the map public.', 'personalized-city-tour' ) . '</p>';
				}
			}
			?>
			<div class="pct-inline-form pct-mymap-refresh-form">
				<button type="button" class="button pct-mymap-refresh" data-city-id="<?php echo (int) $post->ID; ?>" data-action-url="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" data-nonce="<?php echo esc_attr( wp_create_nonce( 'pct_refresh_mymaps' ) ); ?>"><?php esc_html_e( 'Update KML database now', 'personalized-city-tour' ); ?></button>
				<span class="description"><?php esc_html_e( 'The plugin downloads the public KML/KMZ export once and then uses the local cache for tour generation. Save the city first if you have just changed its My Maps URLs.', 'personalized-city-tour' ); ?></span>
			</div>
			<?php
		}
	}

	/**
	 * Label + URL repeater table (channels, My Maps, social links).
	 */
	private static function links_repeater( $id, $key, array $rows, $url_label, $add_label ) {
		$rows = array_values( array_filter( $rows, function ( $r ) { return ! empty( $r['url'] ); } ) );
		$rows[] = array( 'label' => '', 'url' => '' );
		echo '<div class="pct-repeater-wrap"><table class="widefat pct-repeater" id="' . esc_attr( $id ) . '"><thead><tr><th>' . esc_html__( 'Label', 'personalized-city-tour' ) . '</th><th>' . esc_html( $url_label ) . '</th><th></th></tr></thead><tbody>';
		foreach ( $rows as $i => $row ) {
			echo '<tr><td data-label="' . esc_attr__( 'Label', 'personalized-city-tour' ) . '"><input type="text" name="' . esc_attr( $key ) . '[' . (int) $i . '][label]" value="' . esc_attr( (string) ( $row['label'] ?? '' ) ) . '" class="regular-text" /></td>'
				. '<td data-label="' . esc_attr( $url_label ) . '"><input type="url" name="' . esc_attr( $key ) . '[' . (int) $i . '][url]" value="' . esc_attr( (string) ( $row['url'] ?? '' ) ) . '" class="large-text" dir="ltr" /></td>'
				. '<td data-label=""><button type="button" class="button-link-delete pct-repeater-remove">' . esc_html__( 'Remove', 'personalized-city-tour' ) . '</button></td></tr>';
		}
		echo '</tbody></table></div><p><button type="button" class="button pct-repeater-add" data-target="' . esc_attr( $id ) . '">' . esc_html( $add_label ) . '</button></p>';
	}

	/**
	 * Sanitised label/URL rows from a submitted repeater.
	 */
	public static function sanitize_links( $rows ) {
		$out = array();
		foreach ( (array) $rows as $row ) {
			$url = esc_url_raw( trim( (string) ( is_array( $row ) ? ( $row['url'] ?? '' ) : '' ) ), array( 'http', 'https' ) );
			if ( ! $url || ! preg_match( '#^https?://#i', $url ) ) {
				continue;
			}
			$out[] = array(
				'label' => sanitize_text_field( (string) ( $row['label'] ?? '' ) ),
				'url'   => $url,
			);
		}
		return $out;
	}

	private static function render_common_location( $post, $with_city = true ) {
		$m = function ( $k, $d = '' ) use ( $post ) {
			$v = get_post_meta( $post->ID, $k, true );
			return '' === $v ? $d : $v;
		};
		$city_id = (int) $m( '_pct_city_id', (int) get_user_meta( get_current_user_id(), '_pct_active_city', true ) );
		if ( $with_city ) {
			self::field( '_pct_city_id', __( 'City', 'personalized-city-tour' ), self::select( '_pct_city_id', $city_id, PCT_Post_Types::cities(), __( '— select —', 'personalized-city-tour' ) ) );
		}
		self::field( '_pct_address', __( 'Address', 'personalized-city-tour' ), self::text( '_pct_address', $m( '_pct_address' ), 'text', 'class="large-text"' ) );
		self::field( '_pct_lat', __( 'Latitude', 'personalized-city-tour' ), self::text( '_pct_lat', $m( '_pct_lat' ), 'text', 'data-pct="lat"' ) );
		self::field( '_pct_lng', __( 'Longitude', 'personalized-city-tour' ), self::text( '_pct_lng', $m( '_pct_lng' ), 'text', 'data-pct="lng"' ) );
		self::field( 'pct_map', __( 'Map position', 'personalized-city-tour' ), self::map_picker( $m( '_pct_lat' ), $m( '_pct_lng' ), $city_id ) );
		self::field( '_pct_gmaps_url', __( 'Google Maps location link', 'personalized-city-tour' ), self::text( '_pct_gmaps_url', $m( '_pct_gmaps_url' ), 'url', 'class="large-text" dir="ltr"' ), __( 'Share link from Google Maps for this exact location; travellers get it as "Open in Google Maps".', 'personalized-city-tour' ) );
		self::field( '_pct_link', __( 'Official link', 'personalized-city-tour' ), self::text( '_pct_link', $m( '_pct_link' ), 'url', 'class="large-text"' ) );
		self::field( '_pct_ar_url', __( 'Augmented reality service link', 'personalized-city-tour' ), self::text( '_pct_ar_url', $m( '_pct_ar_url' ), 'url', 'class="large-text" dir="ltr"' ), __( 'Optional public AR experience URL for this place.', 'personalized-city-tour' ) );
		self::field( '_pct_ar_label', __( 'AR button label', 'personalized-city-tour' ), self::text( '_pct_ar_label', $m( '_pct_ar_label', __( 'Augmented reality', 'personalized-city-tour' ) ) ) );
		self::field( '_pct_price_tier', __( 'Price tier', 'personalized-city-tour' ), self::select( '_pct_price_tier', $m( '_pct_price_tier' ), PCT_Data::price_tiers(), __( '— unknown —', 'personalized-city-tour' ) ) );
		self::field( '_pct_accessibility', __( 'Accessibility', 'personalized-city-tour' ), self::checkboxes( '_pct_accessibility', (array) $m( '_pct_accessibility', array() ), PCT_Data::accessibility_options() ) );
	}

	public static function render_place( $post ) {
		wp_nonce_field( 'pct_save_meta', 'pct_meta_nonce' );
		$m = function ( $k, $d = '' ) use ( $post ) {
			$v = get_post_meta( $post->ID, $k, true );
			return '' === $v ? $d : $v;
		};
		echo '<table class="form-table pct-form-table">';
		self::render_common_location( $post );
		self::field( '_pct_hours', __( 'Opening hours', 'personalized-city-tour' ), self::text( '_pct_hours', $m( '_pct_hours' ), 'text', 'class="large-text"' ), __( 'Free text, e.g. Tue–Sun 9:00–18:00', 'personalized-city-tour' ) );
		self::field( '_pct_duration', __( 'Typical visit (minutes)', 'personalized-city-tour' ), self::text( '_pct_duration', $m( '_pct_duration' ), 'number', 'min="0" step="5"' ) );
		self::field( '_pct_best_time', __( 'Best time of day', 'personalized-city-tour' ), self::checkboxes( '_pct_best_time', (array) $m( '_pct_best_time', array() ), PCT_Data::best_times() ) );
		self::field( '_pct_indoor', __( 'Indoor / outdoor', 'personalized-city-tour' ), self::select( '_pct_indoor', $m( '_pct_indoor' ), array( 'indoor' => __( 'Indoor', 'personalized-city-tour' ), 'outdoor' => __( 'Outdoor', 'personalized-city-tour' ), 'mixed' => __( 'Mixed', 'personalized-city-tour' ) ), __( '— unknown —', 'personalized-city-tour' ) ) );
		self::field( '_pct_family', __( 'Family friendly', 'personalized-city-tour' ), '<label><input type="checkbox" name="_pct_family" value="1" ' . checked( (bool) $m( '_pct_family' ), true, false ) . ' /> ' . esc_html__( 'Suitable for children', 'personalized-city-tour' ) . '</label>' );
		echo '</table>';
		self::render_socials( $post );
	}

	public static function render_event( $post ) {
		wp_nonce_field( 'pct_save_meta', 'pct_meta_nonce' );
		$m = function ( $k, $d = '' ) use ( $post ) {
			$v = get_post_meta( $post->ID, $k, true );
			return '' === $v ? $d : $v;
		};
		echo '<table class="form-table pct-form-table">';
		self::field( '_pct_start', __( 'Starts', 'personalized-city-tour' ), self::text( '_pct_start', $m( '_pct_start' ), 'text', 'data-pct-date="datetime" dir="ltr"' ) );
		self::field( '_pct_end', __( 'Ends', 'personalized-city-tour' ), self::text( '_pct_end', $m( '_pct_end' ), 'text', 'data-pct-date="datetime" dir="ltr"' ) );
		self::field( '_pct_organizer_name', __( 'Organizer', 'personalized-city-tour' ), self::text( '_pct_organizer_name', $m( '_pct_organizer_name' ) ) );
		self::field( '_pct_organizer_url', __( 'Organizer link', 'personalized-city-tour' ), self::text( '_pct_organizer_url', $m( '_pct_organizer_url' ), 'url', 'class="large-text"' ) );
		self::field( '_pct_event_url', __( 'Public event page', 'personalized-city-tour' ), self::text( '_pct_event_url', $m( '_pct_event_url' ), 'url', 'class="large-text"' ) );
		self::field( '_pct_ar_url', __( 'Augmented reality service link', 'personalized-city-tour' ), self::text( '_pct_ar_url', $m( '_pct_ar_url' ), 'url', 'class="large-text" dir="ltr"' ), __( 'Optional public AR experience URL for this event.', 'personalized-city-tour' ) );
		self::field( '_pct_ar_label', __( 'AR button label', 'personalized-city-tour' ), self::text( '_pct_ar_label', $m( '_pct_ar_label', __( 'Augmented reality', 'personalized-city-tour' ) ) ) );
		self::field( '_pct_venue', __( 'Venue', 'personalized-city-tour' ), self::text( '_pct_venue', $m( '_pct_venue' ) ) );
		self::render_common_location( $post );
		self::field( '_pct_duration', __( 'Duration (minutes)', 'personalized-city-tour' ), self::text( '_pct_duration', $m( '_pct_duration' ), 'number', 'min="0" step="5"' ) );
		self::field( '_pct_family', __( 'Family friendly', 'personalized-city-tour' ), '<label><input type="checkbox" name="_pct_family" value="1" ' . checked( (bool) $m( '_pct_family' ), true, false ) . ' /> ' . esc_html__( 'Suitable for children', 'personalized-city-tour' ) . '</label>' );
		echo '</table>';
		self::render_socials( $post );
	}

	private static function render_socials( $post ) {
		echo '<h3>' . esc_html__( 'Social media links', 'personalized-city-tour' ) . '</h3>';
		echo '<p class="description">' . esc_html__( 'Instagram, Telegram, X, website… of this place or event.', 'personalized-city-tour' ) . '</p>';
		self::links_repeater( 'pct-socials', '_pct_socials', (array) get_post_meta( $post->ID, '_pct_socials', true ), __( 'Profile URL', 'personalized-city-tour' ), __( 'Add another link', 'personalized-city-tour' ) );
	}

	public static function render_tour( $post ) {
		$form   = (array) get_post_meta( $post->ID, '_pct_form', true );
		$result = (array) get_post_meta( $post->ID, '_pct_result', true );
		$token  = get_post_meta( $post->ID, '_pct_token', true );
		$fields = PCT_Form_Builder::field_map( PCT_Form_Builder::get_schema() );
		include PCT_DIR . 'admin/views/tour.php';
	}

	/* ---------------------------------------------------------------- */

	private static function can_save( $post_id, $post ) {
		if ( ! isset( $_POST['pct_meta_nonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['pct_meta_nonce'] ), 'pct_save_meta' ) ) {
			return false;
		}
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return false;
		}
		if ( wp_is_post_revision( $post_id ) || 'auto-draft' === $post->post_status ) {
			return false;
		}
		return current_user_can( 'edit_post', $post_id );
	}

	private static function post_text( $key ) {
		return isset( $_POST[ $key ] ) ? sanitize_text_field( wp_unslash( $_POST[ $key ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
	}

	public static function save_city( $post_id, $post ) {
		if ( ! self::can_save( $post_id, $post ) ) {
			return;
		}
		update_post_meta( $post_id, '_pct_country', self::post_text( '_pct_country' ) );
		update_post_meta( $post_id, '_pct_timezone', self::post_text( '_pct_timezone' ) );
		update_post_meta( $post_id, '_pct_lat', PCT_Rest::sanitize_coord( self::post_text( '_pct_lat' ), 90 ) );
		update_post_meta( $post_id, '_pct_lng', PCT_Rest::sanitize_coord( self::post_text( '_pct_lng' ), 180 ) );
		update_post_meta( $post_id, '_pct_zoom', max( 3, min( 19, absint( self::post_text( '_pct_zoom' ) ?: 13 ) ) ) );
		update_post_meta( $post_id, '_pct_gmaps_url', esc_url_raw( self::post_text( '_pct_gmaps_url' ) ) );
		update_post_meta( $post_id, '_pct_ar_url', esc_url_raw( self::post_text( '_pct_ar_url' ) ) );
		update_post_meta( $post_id, '_pct_ar_label', self::post_text( '_pct_ar_label' ) ?: __( 'Augmented reality', 'personalized-city-tour' ) );
		update_post_meta( $post_id, '_pct_channels', self::sanitize_links( wp_unslash( $_POST['_pct_channels'] ?? array() ) ) ); // phpcs:ignore WordPress.Security.NonceVerification
		update_post_meta( $post_id, '_pct_mymaps', self::sanitize_links( wp_unslash( $_POST['_pct_mymaps'] ?? array() ) ) ); // phpcs:ignore WordPress.Security.NonceVerification
	}

	public static function save_item( $post_id, $post ) {
		if ( ! self::can_save( $post_id, $post ) ) {
			return;
		}
		$acc = array_intersect( array_map( 'sanitize_key', (array) ( $_POST['_pct_accessibility'] ?? array() ) ), array_keys( PCT_Data::accessibility_options() ) ); // phpcs:ignore WordPress.Security.NonceVerification
		$meta = array(
			'_pct_city_id'       => absint( self::post_text( '_pct_city_id' ) ),
			'_pct_address'       => self::post_text( '_pct_address' ),
			'_pct_lat'           => PCT_Rest::sanitize_coord( self::post_text( '_pct_lat' ), 90 ),
			'_pct_lng'           => PCT_Rest::sanitize_coord( self::post_text( '_pct_lng' ), 180 ),
			'_pct_link'          => esc_url_raw( self::post_text( '_pct_link' ) ),
			'_pct_gmaps_url'     => esc_url_raw( self::post_text( '_pct_gmaps_url' ) ),
			'_pct_ar_url'        => esc_url_raw( self::post_text( '_pct_ar_url' ) ),
			'_pct_ar_label'      => self::post_text( '_pct_ar_label' ) ?: __( 'Augmented reality', 'personalized-city-tour' ),
			'_pct_socials'       => self::sanitize_links( wp_unslash( $_POST['_pct_socials'] ?? array() ) ), // phpcs:ignore WordPress.Security.NonceVerification
			'_pct_price_tier'    => array_key_exists( self::post_text( '_pct_price_tier' ), PCT_Data::price_tiers() ) ? self::post_text( '_pct_price_tier' ) : '',
			'_pct_accessibility' => array_values( $acc ),
			'_pct_duration'      => absint( self::post_text( '_pct_duration' ) ),
			'_pct_family'        => empty( $_POST['_pct_family'] ) ? 0 : 1, // phpcs:ignore WordPress.Security.NonceVerification
		);
		if ( PCT_Post_Types::PLACE === $post->post_type ) {
			$best = array_intersect( array_map( 'sanitize_key', (array) ( $_POST['_pct_best_time'] ?? array() ) ), array_keys( PCT_Data::best_times() ) ); // phpcs:ignore WordPress.Security.NonceVerification
			$meta['_pct_hours']     = self::post_text( '_pct_hours' );
			$meta['_pct_best_time'] = array_values( $best );
			$meta['_pct_indoor']    = in_array( self::post_text( '_pct_indoor' ), array( 'indoor', 'outdoor', 'mixed' ), true ) ? self::post_text( '_pct_indoor' ) : '';
		} else {
			$meta['_pct_start']          = PCT_Rest::sanitize_datetime( self::post_text( '_pct_start' ) );
			$meta['_pct_end']            = PCT_Rest::sanitize_datetime( self::post_text( '_pct_end' ) );
			$meta['_pct_organizer_name'] = self::post_text( '_pct_organizer_name' );
			$meta['_pct_organizer_url']  = esc_url_raw( self::post_text( '_pct_organizer_url' ) );
			$meta['_pct_event_url']      = esc_url_raw( self::post_text( '_pct_event_url' ) );
			$meta['_pct_ar_url']         = esc_url_raw( self::post_text( '_pct_ar_url' ) );
			$meta['_pct_ar_label']       = self::post_text( '_pct_ar_label' ) ?: __( 'Augmented reality', 'personalized-city-tour' );
			$meta['_pct_venue']          = self::post_text( '_pct_venue' );
		}
		foreach ( $meta as $k => $v ) {
			update_post_meta( $post_id, $k, $v );
		}
	}
}
