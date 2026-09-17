<?php
/**
 * Frontend: shortcode, assets and the app bootstrap.
 *
 * Shortcode: [personalized_city_tour view="both|traveler|guide" city="ID"]
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PCT_Frontend {

	const SHORTCODE = 'personalized_city_tour';
	const PAGE_META = '_pct_has_frontend';

	public static function init() {
		add_shortcode( self::SHORTCODE, array( __CLASS__, 'shortcode' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'register_assets' ) );
		add_action( 'save_post', array( __CLASS__, 'remember_page' ), 10, 2 );
	}

	public static function register_assets() {
		wp_register_style( 'pct-leaflet', PCT_URL . 'public/vendor/leaflet/leaflet.css', array(), '1.9.4' );
		wp_register_script( 'pct-leaflet', PCT_URL . 'public/vendor/leaflet/leaflet.js', array(), '1.9.4', true );
		wp_register_style( 'pct-public', PCT_URL . 'public/css/public.css', array( 'pct-leaflet' ), PCT_VERSION );
		wp_register_script( 'pct-public', PCT_URL . 'public/js/public.js', array( 'pct-leaflet' ), PCT_VERSION, true );
	}

	/**
	 * Leaflet is also used by the admin map picker.
	 */
	public static function enqueue_leaflet() {
		if ( ! wp_style_is( 'pct-leaflet', 'registered' ) ) {
			self::register_assets();
		}
		wp_enqueue_style( 'pct-leaflet' );
		wp_enqueue_script( 'pct-leaflet' );
	}

	public static function shortcode( $atts ) {
		$atts = shortcode_atts(
			array(
				'view' => 'both',
				'city' => 0,
			),
			$atts,
			self::SHORTCODE
		);
		$view = in_array( $atts['view'], array( 'both', 'traveler', 'traveller', 'guide' ), true ) ? $atts['view'] : 'both';
		if ( 'traveller' === $view ) {
			$view = 'traveler';
		}

		self::enqueue_leaflet();
		wp_enqueue_style( 'pct-public' );
		wp_enqueue_script( 'pct-public' );

		$maps = PCT_Maps::client_config();
		if ( 'google' === $maps['provider'] && $maps['googleKey'] ) {
			wp_enqueue_script( 'pct-google-maps', add_query_arg( array( 'key' => $maps['googleKey'], 'loading' => 'async' ), 'https://maps.googleapis.com/maps/api/js' ), array(), null, true ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion
		}

		$user = wp_get_current_user();
		wp_localize_script(
			'pct-public',
			'pctConfig',
			array(
				'rest'         => esc_url_raw( rest_url( PCT_Rest::NS ) ),
				'nonce'        => wp_create_nonce( 'wp_rest' ),
				'view'         => $view,
				'cityId'       => (int) $atts['city'],
				'loggedIn'     => is_user_logged_in(),
				'userName'     => $user->exists() ? $user->display_name : '',
				'userEmail'    => $user->exists() ? $user->user_email : '',
				'allowed'      => PCT_Settings::visitor_allowed(),
				'loginUrl'     => wp_login_url( get_permalink() ),
				'canApply'     => (bool) PCT_Settings::get( 'guide_registration' ),
				'maps'         => $maps,
				'locale'       => str_replace( '_', '-', get_locale() ),
				'tourId'       => isset( $_GET['pct_tour'] ) ? absint( $_GET['pct_tour'] ) : 0, // phpcs:ignore WordPress.Security.NonceVerification
				'tourToken'    => isset( $_GET['pct_token'] ) ? sanitize_text_field( wp_unslash( $_GET['pct_token'] ) ) : '', // phpcs:ignore WordPress.Security.NonceVerification
				'i18n'         => self::strings(),
			)
		);

		$logged_only = ! PCT_Settings::visitor_allowed();
		ob_start();
		?>
		<div class="pct-app" data-view="<?php echo esc_attr( $view ); ?>">
			<?php if ( $logged_only && 'guide' !== $view ) : ?>
				<div class="pct-notice">
					<?php
					printf(
						/* translators: %s: login link */
						esc_html__( 'Please %s to plan your personalized city tour.', 'personalized-city-tour' ),
						'<a href="' . esc_url( wp_login_url( get_permalink() ) ) . '">' . esc_html__( 'log in', 'personalized-city-tour' ) . '</a>'
					);
					?>
				</div>
			<?php endif; ?>
			<noscript><p><?php esc_html_e( 'This tool requires JavaScript.', 'personalized-city-tour' ); ?></p></noscript>
			<div class="pct-loading" aria-live="polite"><?php esc_html_e( 'Loading…', 'personalized-city-tour' ); ?></div>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Remember the first page that contains the shortcode so admin views can link to it.
	 */
	public static function remember_page( $post_id, $post ) {
		if ( ! $post || wp_is_post_revision( $post_id ) || 'publish' !== $post->post_status ) {
			return;
		}
		if ( has_shortcode( (string) $post->post_content, self::SHORTCODE ) ) {
			update_option( 'pct_frontend_page', $post_id, false );
		} elseif ( (int) get_option( 'pct_frontend_page' ) === (int) $post_id ) {
			delete_option( 'pct_frontend_page' );
		}
	}

	public static function frontend_url() {
		$page = (int) get_option( 'pct_frontend_page' );
		if ( $page && 'publish' === get_post_status( $page ) ) {
			return get_permalink( $page );
		}
		return '';
	}

	private static function strings() {
		return array(
			'traveler'        => __( 'I am a traveler', 'personalized-city-tour' ),
			'travelerSub'     => __( 'Set up a personalized city tour based on your interests, pace and constraints.', 'personalized-city-tour' ),
			'guide'           => __( 'I am a local guide', 'personalized-city-tour' ),
			'guideSub'        => __( 'Register events, organizers and locations for travellers visiting your city.', 'personalized-city-tour' ),
			'back'            => __( 'Back', 'personalized-city-tour' ),
			'next'            => __( 'Next', 'personalized-city-tour' ),
			'previous'        => __( 'Previous', 'personalized-city-tour' ),
			'submit'          => __( 'Create my tour', 'personalized-city-tour' ),
			'generating'      => __( 'Analysing your preferences and composing your tour…', 'personalized-city-tour' ),
			'chooseCity'      => __( 'Where are you staying?', 'personalized-city-tour' ),
			'chooseCitySub'   => __( 'Select the city of your stay.', 'personalized-city-tour' ),
			'noCities'        => __( 'No cities are available yet.', 'personalized-city-tour' ),
			'step'            => __( 'Step %1$d of %2$d', 'personalized-city-tour' ),
			'required'        => __( 'This field is required.', 'personalized-city-tour' ),
			'fixErrors'       => __( 'Please check the highlighted fields.', 'personalized-city-tour' ),
			'error'           => __( 'Something went wrong. Please try again.', 'personalized-city-tour' ),
			'yourTour'        => __( 'Your personalized tour', 'personalized-city-tour' ),
			'days'            => __( 'Days', 'personalized-city-tour' ),
			'day'             => __( 'Day %d', 'personalized-city-tour' ),
			'menu'            => __( 'Open tour menu', 'personalized-city-tour' ),
			'close'           => __( 'Close', 'personalized-city-tour' ),
			'stops'           => __( 'Suggested stops', 'personalized-city-tour' ),
			'alternatives'    => __( 'More suggestions', 'personalized-city-tour' ),
			'accessibility'   => __( 'Accessibility notes', 'personalized-city-tour' ),
			'hints'           => __( 'Quick search hints', 'personalized-city-tour' ),
			'preview'         => __( 'Preview', 'personalized-city-tour' ),
			'navigate'        => __( 'Navigate', 'personalized-city-tour' ),
			'locating'        => __( 'Finding your location…', 'personalized-city-tour' ),
			'noGeo'           => __( 'Location access is not available in this browser.', 'personalized-city-tour' ),
			'geoDenied'       => __( 'Location access was denied. Enable it to use navigation mode.', 'personalized-city-tour' ),
			'youAreHere'      => __( 'You are here', 'personalized-city-tour' ),
			'nextStop'        => __( 'Next stop', 'personalized-city-tour' ),
			'distance'        => __( '%s away', 'personalized-city-tour' ),
			'routeTotal'      => __( 'Route: %1$s · about %2$s', 'personalized-city-tour' ),
			'openGoogle'      => __( 'Open in Google Maps', 'personalized-city-tour' ),
			'openOsm'         => __( 'Open in OpenStreetMap', 'personalized-city-tour' ),
			'myMaps'          => __( 'Curated Google My Maps', 'personalized-city-tour' ),
			'website'         => __( 'Website', 'personalized-city-tour' ),
			'organizer'       => __( 'Organizer', 'personalized-city-tour' ),
			'minutes'         => __( '%d min', 'personalized-city-tour' ),
			'startOver'       => __( 'Plan another tour', 'personalized-city-tour' ),
			'copyLink'        => __( 'Copy link to this tour', 'personalized-city-tour' ),
			'copied'          => __( 'Link copied.', 'personalized-city-tour' ),
			'routeFailed'     => __( 'Route service unavailable — showing straight lines.', 'personalized-city-tour' ),
			'reached'         => __( 'You have reached this stop.', 'personalized-city-tour' ),
			'arriveAll'       => __( 'You have visited every stop of this day.', 'personalized-city-tour' ),
			'markVisited'     => __( 'Mark as visited', 'personalized-city-tour' ),
			'dragHint'        => __( 'Drag to reorder', 'personalized-city-tour' ),
			'pending'         => __( 'Awaiting review', 'personalized-city-tour' ),
			'published'       => __( 'Published', 'personalized-city-tour' ),
			'draft'           => __( 'Draft', 'personalized-city-tour' ),
			/* guide */
			'guideIntro'      => __( 'Local guide area', 'personalized-city-tour' ),
			'guideLogin'      => __( 'Log in', 'personalized-city-tour' ),
			'guideLogout'     => __( 'Log out', 'personalized-city-tour' ),
			'guideApplyTitle' => __( 'Apply to become a local guide', 'personalized-city-tour' ),
			'guideApplySub'   => __( 'Tell us about yourself. An administrator reviews every application; you will be notified by email.', 'personalized-city-tour' ),
			'guideApplied'    => __( 'Thank you! Your application is awaiting review.', 'personalized-city-tour' ),
			'guidePending'    => __( 'Your local guide application is awaiting approval.', 'personalized-city-tour' ),
			'guideRevoked'    => __( 'Your local guide access has been revoked. Please contact the site administrator.', 'personalized-city-tour' ),
			'guideClosed'     => __( 'Local guide registration is currently closed.', 'personalized-city-tour' ),
			'guideHasAccount' => __( 'Already have an account?', 'personalized-city-tour' ),
			'guideWelcome'    => __( 'Welcome, %s', 'personalized-city-tour' ),
			'newEvent'        => __( 'Register a new event', 'personalized-city-tour' ),
			'myEvents'        => __( 'My events', 'personalized-city-tour' ),
			'noEvents'        => __( 'You have not registered any events yet.', 'personalized-city-tour' ),
			'eventSaved'      => __( 'Event saved.', 'personalized-city-tour' ),
			'eventPending'    => __( 'Event submitted — it will be visible after review.', 'personalized-city-tour' ),
			'delete'          => __( 'Delete', 'personalized-city-tour' ),
			'confirmDelete'   => __( 'Delete this event?', 'personalized-city-tour' ),
			'save'            => __( 'Save event', 'personalized-city-tour' ),
			'saving'          => __( 'Saving…', 'personalized-city-tour' ),
			'apply'           => __( 'Send application', 'personalized-city-tour' ),
			'find'            => __( 'Find', 'personalized-city-tour' ),
			'mapHint'         => __( 'Click on the map to set the location, or search an address.', 'personalized-city-tour' ),
			'dashboard'       => __( 'Open the dashboard', 'personalized-city-tour' ),
			'labels'          => array(
				'name'           => __( 'Your name', 'personalized-city-tour' ),
				'email'          => __( 'Email', 'personalized-city-tour' ),
				'city'           => __( 'City', 'personalized-city-tour' ),
				'languages'      => __( 'Languages you speak', 'personalized-city-tour' ),
				'note'           => __( 'About you / your expertise', 'personalized-city-tour' ),
				'title'          => __( 'Event title', 'personalized-city-tour' ),
				'description'    => __( 'Description', 'personalized-city-tour' ),
				'categories'     => __( 'Categories', 'personalized-city-tour' ),
				'start'          => __( 'Starts', 'personalized-city-tour' ),
				'end'            => __( 'Ends', 'personalized-city-tour' ),
				'organizer_name' => __( 'Organizer', 'personalized-city-tour' ),
				'organizer_url'  => __( 'Organizer link', 'personalized-city-tour' ),
				'event_url'      => __( 'Public event page', 'personalized-city-tour' ),
				'venue'          => __( 'Venue', 'personalized-city-tour' ),
				'address'        => __( 'Address', 'personalized-city-tour' ),
				'location'       => __( 'Location', 'personalized-city-tour' ),
				'price_tier'     => __( 'Price', 'personalized-city-tour' ),
				'accessibility'  => __( 'Accessibility', 'personalized-city-tour' ),
				'select'         => __( '— select —', 'personalized-city-tour' ),
				'yes'            => __( 'Yes', 'personalized-city-tour' ),
				'no'             => __( 'No', 'personalized-city-tour' ),
				'selected'       => __( 'Your priorities', 'personalized-city-tour' ),
				'available'      => __( 'Available', 'personalized-city-tour' ),
			),
			'priceTiers'      => PCT_Data::price_tiers(),
			'accessOptions'   => PCT_Data::accessibility_options(),
		);
	}
}
