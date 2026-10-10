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
		wp_register_script( 'pct-datepicker', PCT_URL . 'public/js/pct-datepicker.js', array(), PCT_VERSION, true );
		wp_register_style( 'pct-datepicker', PCT_URL . 'public/css/pct-datepicker.css', array(), PCT_VERSION );
		wp_register_style( 'pct-public', PCT_URL . 'public/css/public.css', array( 'pct-leaflet' ), PCT_VERSION );
		wp_register_script( 'pct-public', PCT_URL . 'public/js/public.js', array( 'pct-leaflet', 'pct-datepicker' ), PCT_VERSION, true );
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

	/**
	 * Shared Jalali/Gregorian date picker (also used by admin event/meta screens).
	 */
	public static function enqueue_datepicker() {
		if ( ! wp_script_is( 'pct-datepicker', 'registered' ) ) {
			self::register_assets();
		}
		wp_enqueue_script( 'pct-datepicker' );
		wp_enqueue_style( 'pct-datepicker' );
		wp_localize_script( 'pct-datepicker', 'pctDatepicker', self::datepicker_config() );
	}

	public static function datepicker_config() {
		return array(
			'calendar' => PCT_I18n::calendar_for( PCT_I18n::current() ),
			'rtl'      => PCT_I18n::is_rtl(),
			'lang'     => PCT_I18n::current(),
			'i18n'     => array(
				'today'       => __( 'Today', 'personalized-city-tour' ),
				'clear'       => __( 'Clear', 'personalized-city-tour' ),
				'time'        => __( 'Time', 'personalized-city-tour' ),
				'jalaliMonths' => array( 'فروردین', 'اردیبهشت', 'خرداد', 'تیر', 'مرداد', 'شهریور', 'مهر', 'آبان', 'آذر', 'دی', 'بهمن', 'اسفند' ),
				'jalaliDays'   => array( 'ش', 'ی', 'د', 'س', 'چ', 'پ', 'ج' ),
				'months'      => array_map( 'trim', explode( ',', __( 'January,February,March,April,May,June,July,August,September,October,November,December', 'personalized-city-tour' ) ) ),
				'days'        => array_map( 'trim', explode( ',', __( 'Su,Mo,Tu,We,Th,Fr,Sa', 'personalized-city-tour' ) ) ),
			),
		);
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
		self::enqueue_datepicker();
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
				'rest'         => esc_url_raw( set_url_scheme( rest_url( PCT_Rest::NS ), is_ssl() ? 'https' : 'http' ) ),
				'restFallback' => esc_url_raw( set_url_scheme( home_url( '/index.php' ), is_ssl() ? 'https' : 'http' ) ),
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
				'lang'         => PCT_I18n::client_config(),
				'calendar'     => PCT_I18n::calendar_for( PCT_I18n::current() ),
				'rtl'          => PCT_I18n::is_rtl(),
				'vibe'         => PCT_Vibe::enabled() ? array(
					'fee'      => PCT_Vibe::settings()['fee_text'],
					'vibes'    => PCT_Vibe::localized_options( 'vibes' ),
					'styles'   => PCT_Vibe::localized_options( 'styles' ),
					'subjects' => PCT_Vibe::localized_options( 'subjects' ),
					'subjectVisible' => (bool) PCT_Vibe::settings()['subject_visible'],
					'maxMb'    => (int) PCT_Vibe::settings()['max_upload_mb'],
					'requireApproval' => (bool) PCT_Vibe::settings()['require_approval'],
				) : null,
				'requireMobile' => (bool) PCT_Settings::get( 'guide_require_mobile', 1 ),
				'tourId'       => isset( $_GET['pct_tour'] ) ? absint( $_GET['pct_tour'] ) : 0, // phpcs:ignore WordPress.Security.NonceVerification
				'tourToken'    => isset( $_GET['pct_token'] ) ? sanitize_text_field( wp_unslash( $_GET['pct_token'] ) ) : '', // phpcs:ignore WordPress.Security.NonceVerification
				'i18n'         => self::strings(),
			)
		);

		$logged_only = ! PCT_Settings::visitor_allowed();
		ob_start();
		?>
		<div class="pct-app<?php echo PCT_I18n::is_rtl() ? ' is-rtl' : ''; ?>" data-view="<?php echo esc_attr( $view ); ?>" dir="<?php echo PCT_I18n::is_rtl() ? 'rtl' : 'ltr'; ?>" lang="<?php echo esc_attr( PCT_I18n::current() ); ?>">
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

	public static function strings() {
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
			'days'            => __( 'Tour options', 'personalized-city-tour' ),
			'day'             => __( 'Day %d', 'personalized-city-tour' ),
			'option'          => __( 'Option %d', 'personalized-city-tour' ),
			'chooseOption'    => __( 'Choose day / option', 'personalized-city-tour' ),
			'menu'            => __( 'Open tour menu', 'personalized-city-tour' ),
			'close'           => __( 'Close', 'personalized-city-tour' ),
			'stops'           => __( 'Suggested stops', 'personalized-city-tour' ),
			'alternatives'    => __( 'More suggestions', 'personalized-city-tour' ),
			'serendipity'     => __( 'Serendipity discoveries', 'personalized-city-tour' ),
			'serendipityNote' => __( 'Interesting current event found online; confirm the venue/location before visiting.', 'personalized-city-tour' ),
			'accessibility'   => __( 'Accessibility notes', 'personalized-city-tour' ),
			'proposalInfo'    => __( 'Tour details', 'personalized-city-tour' ),
			'travelStyle'     => __( 'Style', 'personalized-city-tour' ),
			'priority'        => __( 'Priority', 'personalized-city-tour' ),
			'preferredTime'   => __( 'Preferred time', 'personalized-city-tour' ),
			'timeWindow'      => __( 'Time window', 'personalized-city-tour' ),
			'visitTime'       => __( 'Visit time', 'personalized-city-tour' ),
			'stopCount'       => __( 'Stops', 'personalized-city-tour' ),
			'pace'            => __( 'Pace', 'personalized-city-tour' ),
			'photo'           => __( 'Photo', 'personalized-city-tour' ),
			'details'         => __( 'Details', 'personalized-city-tour' ),
			'photoSearch'     => __( 'Photos', 'personalized-city-tour' ),
			'webSearch'       => __( 'Web search', 'personalized-city-tour' ),
			'ar'              => __( 'Augmented reality', 'personalized-city-tour' ),
			'expandDescription' => __( 'Show guide description', 'personalized-city-tour' ),
			'collapseDescription' => __( 'Hide guide description', 'personalized-city-tour' ),
			'arUnavailable'   => __( 'No AR experience is configured for this stop yet.', 'personalized-city-tour' ),
			'contact'         => __( 'Contact them', 'personalized-city-tour' ),
			'hints'           => __( 'Quick search hints', 'personalized-city-tour' ),
			'previousStop'    => __( 'Previous stop', 'personalized-city-tour' ),
			'nextStopLabel'   => __( 'Next stop', 'personalized-city-tour' ),
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
			'mapUnavailable'  => __( 'The interactive map could not be loaded. Navigation links are still available.', 'personalized-city-tour' ),
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
			'optional'        => __( 'optional', 'personalized-city-tour' ),
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
			/* language */
			'chooseLanguage'  => __( 'Choose your language', 'personalized-city-tour' ),
			'language'        => __( 'Language', 'personalized-city-tour' ),
			'continue'        => __( 'Continue', 'personalized-city-tour' ),
			/* progress */
			'progressSteps'   => array(
				__( 'Checking your answers', 'personalized-city-tour' ),
				__( 'Reading the city database', 'personalized-city-tour' ),
				__( 'Composing your day-by-day plan', 'personalized-city-tour' ),
				__( 'Preparing route maps', 'personalized-city-tour' ),
			),
			'tourLink'        => __( 'Your tour link', 'personalized-city-tour' ),
			'suggestedRoute'  => __( 'Suggested route (%d short stops):', 'personalized-city-tour' ),
			'routeTags'      => __( 'Tags:', 'personalized-city-tour' ),
			'routeNote'      => __( 'Note:', 'personalized-city-tour' ),
			'cityChannels'    => __( 'City channels', 'personalized-city-tour' ),
			'cityOnGoogle'    => __( 'City on Google Maps', 'personalized-city-tour' ),
			'searchSite'      => __( 'Search on this site', 'personalized-city-tour' ),
			/* navigation */
			'plannedRoute'    => __( 'Planned tour route', 'personalized-city-tour' ),
			'yourRoute'       => __( 'Your route', 'personalized-city-tour' ),
			'savedPlaces'     => __( 'My places', 'personalized-city-tour' ),
			'savedPlacesSub'  => __( 'Accommodation · station · parking', 'personalized-city-tour' ),
			'saveHere'        => __( 'Save my current location', 'personalized-city-tour' ),
			'goTo'            => __( 'Go to', 'personalized-city-tour' ),
			'remove'          => __( 'Remove', 'personalized-city-tour' ),
			'saved'           => __( 'Saved.', 'personalized-city-tour' ),
			'needLocation'    => __( 'Start navigation first so we know where you are.', 'personalized-city-tour' ),
			'placeTypes'      => array(
				'parking'       => __( 'Parking', 'personalized-city-tour' ),
				'bus'           => __( 'Bus stop', 'personalized-city-tour' ),
				'metro'         => __( 'Metro station', 'personalized-city-tour' ),
				'accommodation' => __( 'Accommodation', 'personalized-city-tour' ),
			),
			'backToTour'      => __( 'Back to the tour route', 'personalized-city-tour' ),
			'goToStart'       => __( 'Go to the tour start', 'personalized-city-tour' ),
			'decrease'        => __( 'Decrease', 'personalized-city-tour' ),
			'increase'        => __( 'Increase', 'personalized-city-tour' ),
			/* guide without login */
			'guideRegister'   => __( 'Register as a local guide', 'personalized-city-tour' ),
			'guideRegisterSub' => __( 'No account needed. Enter your mobile number so an administrator can verify you; your events are published after verification.', 'personalized-city-tour' ),
			'guideStatus'     => __( 'Registration status', 'personalized-city-tour' ),
			'guideApproved'   => __( 'Verified local guide', 'personalized-city-tour' ),
			'guidePendingSub' => __( 'You can already register events. They stay hidden until an administrator verifies your mobile number.', 'personalized-city-tour' ),
			'guideForget'     => __( 'Forget this device', 'personalized-city-tour' ),
			'mobile'          => __( 'Mobile number', 'personalized-city-tour' ),
			'mobileHint'      => __( 'Example: 0912 123 4567 or +98 912 123 4567', 'personalized-city-tour' ),
			'organizerMobile' => __( 'Organizer mobile', 'personalized-city-tour' ),
			/* vibe */
			'vibe'            => __( 'My Trip, My Vibe', 'personalized-city-tour' ),
			'vibeSub'         => __( 'Turn a travel photo into an AI illustration in the style you love.', 'personalized-city-tour' ),
			'vibeOtpTitle'    => __( 'Verify your mobile number', 'personalized-city-tour' ),
			'vibeOtpSent'     => __( 'We sent a 6-digit code to your mobile.', 'personalized-city-tour' ),
			'sendCode'        => __( 'Send code', 'personalized-city-tour' ),
			'code'            => __( 'Verification code', 'personalized-city-tour' ),
			'verify'          => __( 'Verify', 'personalized-city-tour' ),
			'vibeForm'        => __( 'Describe your vibe', 'personalized-city-tour' ),
			'photo'           => __( 'Your photo', 'personalized-city-tour' ),
			'photoHint'       => __( 'JPEG, PNG or WEBP, up to %d MB. It is only used for this illustration.', 'personalized-city-tour' ),
			'vibeChoice'      => __( 'Vibe', 'personalized-city-tour' ),
			'styleChoice'     => __( 'Art style', 'personalized-city-tour' ),
			'subjectChoice'   => __( 'Subject', 'personalized-city-tour' ),
			'notes'           => __( 'Notes for the artist', 'personalized-city-tour' ),
			'vibeSubmit'      => __( 'Send request', 'personalized-city-tour' ),
			'vibeSent'        => __( 'Request received. You will be notified once it is approved and generated.', 'personalized-city-tour' ),
			'vibeSentAuto'    => __( 'Request received. Generation starts automatically; you will be notified when the image is ready.', 'personalized-city-tour' ),
			'vibeConfirm'    => ( 'fa' === PCT_I18n::current() ? 'دوباره ارسال را تأیید کنید.' : __( 'Please send again to confirm.', 'personalized-city-tour' ) ),
			'vibeMymap'      => ( 'fa' === PCT_I18n::current() ? 'باز کردن Google My Maps منتخب' : __( 'Open curated Google My Maps', 'personalized-city-tour' ) ),
			'myRequests'      => __( 'My requests', 'personalized-city-tour' ),
			'noRequests'      => __( 'No requests yet.', 'personalized-city-tour' ),
			'download'        => __( 'Download', 'personalized-city-tour' ),
			'share'           => __( 'Share', 'personalized-city-tour' ),
			'refresh'         => __( 'Refresh', 'personalized-city-tour' ),
			'consent'         => __( 'I agree that my photo is processed by an AI image service for this request.', 'personalized-city-tour' ),
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
