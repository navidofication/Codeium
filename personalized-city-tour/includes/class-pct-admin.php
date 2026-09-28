<?php
/**
 * Admin menu, hamburger panel, dashboard, settings and list-table tweaks.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PCT_Admin {

	const MENU = 'pct-dashboard';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_filter( 'parent_file', array( __CLASS__, 'parent_file' ) );
		add_filter( 'submenu_file', array( __CLASS__, 'submenu_file' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'all_admin_notices', array( __CLASS__, 'topbar' ) );
		add_action( 'pre_get_posts', array( __CLASS__, 'filter_lists' ) );
		add_action( 'restrict_manage_posts', array( __CLASS__, 'city_filter_dropdown' ) );
		add_action( 'admin_post_pct_set_active_city', array( __CLASS__, 'handle_set_active_city' ) );
		add_action( 'admin_post_pct_purge_log', array( __CLASS__, 'handle_purge_log' ) );

		foreach ( array( PCT_Post_Types::PLACE, PCT_Post_Types::EVENT ) as $type ) {
			add_filter( "manage_{$type}_posts_columns", array( __CLASS__, 'columns' ) );
			add_action( "manage_{$type}_posts_custom_column", array( __CLASS__, 'column_content' ), 10, 2 );
		}
		add_filter( 'manage_' . PCT_Post_Types::TOUR . '_posts_columns', array( __CLASS__, 'tour_columns' ) );
		add_action( 'manage_' . PCT_Post_Types::TOUR . '_posts_custom_column', array( __CLASS__, 'tour_column_content' ), 10, 2 );
		add_filter( 'manage_' . PCT_Post_Types::CITY . '_posts_columns', array( __CLASS__, 'city_columns' ) );
		add_action( 'manage_' . PCT_Post_Types::CITY . '_posts_custom_column', array( __CLASS__, 'city_column_content' ), 10, 2 );

		add_filter( 'plugin_action_links_' . plugin_basename( PCT_FILE ), array( __CLASS__, 'plugin_links' ) );
	}

	/* ---------------------------------------------------------------- */

	public static function menu() {
		add_menu_page(
			__( 'Personalized City Tour', 'personalized-city-tour' ),
			__( 'City Tour', 'personalized-city-tour' ),
			'edit_pct_places',
			self::MENU,
			array( __CLASS__, 'render_dashboard' ),
			'dashicons-location-alt',
			27
		);
		add_submenu_page( self::MENU, __( 'Dashboard', 'personalized-city-tour' ), __( 'Dashboard', 'personalized-city-tour' ), 'edit_pct_places', self::MENU, array( __CLASS__, 'render_dashboard' ) );
		add_submenu_page( self::MENU, __( 'Cities', 'personalized-city-tour' ), __( 'Cities', 'personalized-city-tour' ), 'edit_pages', 'edit.php?post_type=' . PCT_Post_Types::CITY );
		add_submenu_page( self::MENU, __( 'City Data', 'personalized-city-tour' ), __( 'City Data', 'personalized-city-tour' ), 'edit_pct_places', 'edit.php?post_type=' . PCT_Post_Types::PLACE );
		add_submenu_page( self::MENU, __( 'Events', 'personalized-city-tour' ), __( 'Events', 'personalized-city-tour' ), 'edit_pct_events', 'edit.php?post_type=' . PCT_Post_Types::EVENT );
		add_submenu_page( self::MENU, __( 'Categories', 'personalized-city-tour' ), __( 'Categories', 'personalized-city-tour' ), 'manage_options', 'edit-tags.php?taxonomy=' . PCT_Post_Types::CATEGORY . '&post_type=' . PCT_Post_Types::PLACE );
		add_submenu_page( self::MENU, __( 'Local Guides', 'personalized-city-tour' ), __( 'Local Guides', 'personalized-city-tour' ), 'pct_manage', 'pct-guides', array( 'PCT_Guides', 'render' ) );
		add_submenu_page( self::MENU, __( 'Form Builder', 'personalized-city-tour' ), __( 'Form Builder', 'personalized-city-tour' ), 'pct_manage', 'pct-form-builder', array( __CLASS__, 'render_form_builder' ) );
		add_submenu_page( self::MENU, __( 'Generated Tours', 'personalized-city-tour' ), __( 'Generated Tours', 'personalized-city-tour' ), 'edit_pages', 'edit.php?post_type=' . PCT_Post_Types::TOUR );
		add_submenu_page( self::MENU, __( 'Rule Based Planner', 'personalized-city-tour' ), __( 'Planner', 'personalized-city-tour' ), 'pct_manage', 'pct-planner', array( __CLASS__, 'render_planner' ) );
		if ( PCT_Analytics::can_access() ) {
			add_submenu_page( self::MENU, __( 'Center for Statistics, Information & Analysis', 'personalized-city-tour' ), __( 'Analytics', 'personalized-city-tour' ), 'read', 'pct-analytics', array( __CLASS__, 'render_analytics' ) );
		}
		add_submenu_page( self::MENU, __( 'My Trip, My Vibe', 'personalized-city-tour' ), __( 'My Trip, My Vibe', 'personalized-city-tour' ), 'pct_manage', 'pct-vibe', array( __CLASS__, 'render_vibe' ) );
		add_submenu_page( self::MENU, __( 'AI Audit Log', 'personalized-city-tour' ), __( 'AI Audit Log', 'personalized-city-tour' ), 'manage_options', 'pct-ai-log', array( __CLASS__, 'render_ai_log' ) );
		add_submenu_page( self::MENU, __( 'Settings', 'personalized-city-tour' ), __( 'Settings', 'personalized-city-tour' ), 'pct_manage', 'pct-settings', array( __CLASS__, 'render_settings' ) );
	}

	public static function is_plugin_screen() {
		$screen = get_current_screen();
		if ( ! $screen ) {
			return false;
		}
		$types = array( PCT_Post_Types::CITY, PCT_Post_Types::PLACE, PCT_Post_Types::EVENT, PCT_Post_Types::TOUR );
		if ( in_array( $screen->post_type, $types, true ) ) {
			return true;
		}
		if ( in_array( $screen->taxonomy, array( PCT_Post_Types::CATEGORY, PCT_Post_Types::TAG ), true ) ) {
			return true;
		}
		return false !== strpos( (string) $screen->id, 'pct-' );
	}

	public static function parent_file( $parent ) {
		return self::is_plugin_screen() ? self::MENU : $parent;
	}

	public static function submenu_file( $submenu ) {
		$screen = get_current_screen();
		if ( ! $screen ) {
			return $submenu;
		}
		if ( PCT_Post_Types::CATEGORY === $screen->taxonomy || PCT_Post_Types::TAG === $screen->taxonomy ) {
			return 'edit-tags.php?taxonomy=' . PCT_Post_Types::CATEGORY . '&post_type=' . PCT_Post_Types::PLACE;
		}
		if ( in_array( $screen->post_type, array( PCT_Post_Types::CITY, PCT_Post_Types::PLACE, PCT_Post_Types::EVENT, PCT_Post_Types::TOUR ), true ) ) {
			return 'edit.php?post_type=' . $screen->post_type;
		}
		return $submenu;
	}

	public static function assets( $hook ) {
		if ( ! self::is_plugin_screen() ) {
			return;
		}
		wp_enqueue_style( 'pct-admin', PCT_URL . 'admin/css/admin.css', array(), PCT_VERSION );
		wp_enqueue_script( 'pct-admin', PCT_URL . 'admin/js/admin.js', array(), PCT_VERSION, true );
		wp_localize_script(
			'pct-admin',
			'pctAdmin',
			array(
				'rest'  => esc_url_raw( rest_url( PCT_Rest::NS ) ),
				'nonce' => wp_create_nonce( 'wp_rest' ),
				'i18n'  => array(
					'testing' => __( 'Testing…', 'personalized-city-tour' ),
					'ok'      => __( 'Connection OK.', 'personalized-city-tour' ),
					'confirm' => __( 'Are you sure?', 'personalized-city-tour' ),
					'running' => __( 'Running diagnostics…', 'personalized-city-tour' ),
					'allOk'   => __( 'All checks passed.', 'personalized-city-tour' ),
					'hasErr'  => __( 'Some checks failed — see details below.', 'personalized-city-tour' ),
					'sync'    => __( 'Refreshing exports…', 'personalized-city-tour' ),
				),
			)
		);

		$screen = get_current_screen();
		if ( $screen && 'post' === $screen->base && in_array( $screen->post_type, array( PCT_Post_Types::CITY, PCT_Post_Types::PLACE, PCT_Post_Types::EVENT ), true ) ) {
			PCT_Frontend::enqueue_leaflet();
			PCT_Frontend::enqueue_datepicker();
			wp_enqueue_script( 'pct-admin-map', PCT_URL . 'admin/js/map-picker.js', array( 'pct-leaflet' ), PCT_VERSION, true );
			wp_localize_script( 'pct-admin-map', 'pctMap', PCT_Maps::client_config() );
		}
		if ( false !== strpos( $hook, 'pct-form-builder' ) ) {
			wp_enqueue_script( 'pct-form-builder', PCT_URL . 'admin/js/form-builder.js', array( 'pct-admin' ), PCT_VERSION, true );
			wp_localize_script(
				'pct-form-builder',
				'pctFormBuilder',
				array(
					'schema'    => PCT_Form_Builder::get_schema(),
					'types'     => PCT_Form_Builder::field_types(),
					'operators' => PCT_Form_Builder::operators(),
					'i18n'      => array(
						'section'     => __( 'Section', 'personalized-city-tour' ),
						'title'       => __( 'Title', 'personalized-city-tour' ),
						'description' => __( 'Description', 'personalized-city-tour' ),
						'addField'    => __( 'Add field', 'personalized-city-tour' ),
						'addSection'  => __( 'Add section', 'personalized-city-tour' ),
						'remove'      => __( 'Remove', 'personalized-city-tour' ),
						'up'          => __( 'Move up', 'personalized-city-tour' ),
						'down'        => __( 'Move down', 'personalized-city-tour' ),
						'label'       => __( 'Label', 'personalized-city-tour' ),
						'key'         => __( 'Field key', 'personalized-city-tour' ),
						'type'        => __( 'Type', 'personalized-city-tour' ),
						'required'    => __( 'Required', 'personalized-city-tour' ),
						'placeholder' => __( 'Placeholder', 'personalized-city-tour' ),
						'help'        => __( 'Help text', 'personalized-city-tour' ),
						'options'     => __( 'Options (one per line, value|label)', 'personalized-city-tour' ),
						'min'         => __( 'Min', 'personalized-city-tour' ),
						'max'         => __( 'Max', 'personalized-city-tour' ),
						'step'        => __( 'Step', 'personalized-city-tour' ),
						'condition'   => __( 'Show only when', 'personalized-city-tour' ),
						'always'      => __( '— always shown —', 'personalized-city-tour' ),
						'value'       => __( 'Value', 'personalized-city-tour' ),
						'saved'       => __( 'Form saved.', 'personalized-city-tour' ),
						'saving'      => __( 'Saving…', 'personalized-city-tour' ),
						'resetConfirm' => __( 'Reset the form to the default specification? Your changes will be lost.', 'personalized-city-tour' ),
						'newSection'  => __( 'New section', 'personalized-city-tour' ),
						'newField'    => __( 'New field', 'personalized-city-tour' ),
						'fields'      => __( 'fields', 'personalized-city-tour' ),
						'sectionCondition' => __( 'Section shown only when', 'personalized-city-tour' ),
					),
				)
			);
		}
	}

	/* ---------------------------------------------------------------- */

	/**
	 * Hamburger top bar with an off-canvas panel (settings + city database selection).
	 */
	public static function topbar() {
		if ( ! self::is_plugin_screen() ) {
			return;
		}
		$cities  = PCT_Post_Types::cities();
		$active  = (int) get_user_meta( get_current_user_id(), '_pct_active_city', true );
		$manage  = current_user_can( 'pct_manage' );
		$links   = array(
			array( admin_url( 'admin.php?page=' . self::MENU ), __( 'Dashboard', 'personalized-city-tour' ), 'edit_pct_places' ),
			array( admin_url( 'edit.php?post_type=' . PCT_Post_Types::CITY ), __( 'Cities', 'personalized-city-tour' ), 'edit_pages' ),
			array( admin_url( 'edit.php?post_type=' . PCT_Post_Types::PLACE ), __( 'City Data', 'personalized-city-tour' ), 'edit_pct_places' ),
			array( admin_url( 'post-new.php?post_type=' . PCT_Post_Types::PLACE ), __( '+ Add city data entry', 'personalized-city-tour' ), 'edit_pct_places' ),
			array( admin_url( 'edit.php?post_type=' . PCT_Post_Types::EVENT ), __( 'Events', 'personalized-city-tour' ), 'edit_pct_events' ),
			array( admin_url( 'post-new.php?post_type=' . PCT_Post_Types::EVENT ), __( '+ Add event', 'personalized-city-tour' ), 'edit_pct_events' ),
			array( admin_url( 'edit-tags.php?taxonomy=' . PCT_Post_Types::CATEGORY . '&post_type=' . PCT_Post_Types::PLACE ), __( 'Categories', 'personalized-city-tour' ), 'manage_options' ),
			array( admin_url( 'admin.php?page=pct-guides' ), __( 'Local Guides', 'personalized-city-tour' ), 'pct_manage' ),
			array( admin_url( 'admin.php?page=pct-form-builder' ), __( 'Form Builder', 'personalized-city-tour' ), 'pct_manage' ),
			array( admin_url( 'edit.php?post_type=' . PCT_Post_Types::TOUR ), __( 'Generated Tours', 'personalized-city-tour' ), 'edit_pages' ),
			array( admin_url( 'admin.php?page=pct-planner' ), __( 'Rule Based Planner', 'personalized-city-tour' ), 'pct_manage' ),
			array( admin_url( 'admin.php?page=pct-analytics' ), __( 'Analytics center', 'personalized-city-tour' ), PCT_Analytics::can_access() ? 'read' : 'do_not_allow' ),
			array( admin_url( 'admin.php?page=pct-vibe' ), __( 'My Trip, My Vibe', 'personalized-city-tour' ), 'pct_manage' ),
			array( admin_url( 'admin.php?page=pct-ai-log' ), __( 'AI Audit Log', 'personalized-city-tour' ), 'manage_options' ),
			array( admin_url( 'admin.php?page=pct-settings' ), __( 'Settings', 'personalized-city-tour' ), 'pct_manage' ),
		);
		$langs        = PCT_I18n::enabled_languages();
		$current_lang = PCT_I18n::current();
		?>
		<div class="pct-topbar">
			<button type="button" class="pct-hamburger" aria-expanded="false" aria-controls="pct-drawer" aria-label="<?php esc_attr_e( 'Open City Tour menu', 'personalized-city-tour' ); ?>">
				<span></span><span></span><span></span>
			</button>
			<span class="pct-topbar-title"><?php esc_html_e( 'Personalized City Tour', 'personalized-city-tour' ); ?></span>
			<span class="pct-topbar-city">
				<?php if ( $active && isset( $cities[ $active ] ) ) : ?>
					<?php /* translators: %s: city name */ printf( esc_html__( 'Database: %s', 'personalized-city-tour' ), '<strong>' . esc_html( $cities[ $active ] ) . '</strong>' ); ?>
				<?php else : ?>
					<?php esc_html_e( 'Database: all cities', 'personalized-city-tour' ); ?>
				<?php endif; ?>
			</span>
			<form class="pct-topbar-lang" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'pct_admin_lang' ); ?>
				<input type="hidden" name="action" value="pct_admin_lang" />
				<label for="pct-admin-lang" class="screen-reader-text"><?php esc_html_e( 'Admin language', 'personalized-city-tour' ); ?></label>
				<select id="pct-admin-lang" name="lang" onchange="this.form.submit()">
					<?php foreach ( $langs as $code => $meta ) : ?>
						<option value="<?php echo esc_attr( $code ); ?>" <?php selected( $current_lang, $code ); ?>><?php echo esc_html( $meta['label'] ); ?></option>
					<?php endforeach; ?>
				</select>
			</form>
		</div>
		<div class="pct-drawer-backdrop" hidden></div>
		<aside id="pct-drawer" class="pct-drawer" hidden aria-label="<?php esc_attr_e( 'City Tour menu', 'personalized-city-tour' ); ?>">
			<div class="pct-drawer-head">
				<strong><?php esc_html_e( 'City Tour', 'personalized-city-tour' ); ?></strong>
				<button type="button" class="pct-drawer-close" aria-label="<?php esc_attr_e( 'Close', 'personalized-city-tour' ); ?>">&times;</button>
			</div>
			<form class="pct-drawer-city" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'pct_set_active_city' ); ?>
				<input type="hidden" name="action" value="pct_set_active_city" />
				<input type="hidden" name="redirect" value="<?php echo esc_url( self::current_admin_url() ); ?>" />
				<label for="pct-active-city"><?php esc_html_e( 'City database', 'personalized-city-tour' ); ?></label>
				<select id="pct-active-city" name="city_id" onchange="this.form.submit()">
					<option value="0"><?php esc_html_e( 'All cities', 'personalized-city-tour' ); ?></option>
					<?php foreach ( $cities as $id => $name ) : ?>
						<option value="<?php echo (int) $id; ?>" <?php selected( $active, $id ); ?>><?php echo esc_html( $name ); ?></option>
					<?php endforeach; ?>
				</select>
				<p class="description"><?php esc_html_e( 'Filters city data, events and tours in the lists below.', 'personalized-city-tour' ); ?></p>
			</form>
			<nav class="pct-drawer-nav">
				<?php foreach ( $links as $link ) : ?>
					<?php if ( current_user_can( $link[2] ) ) : ?>
						<a href="<?php echo esc_url( $link[0] ); ?>"><?php echo esc_html( $link[1] ); ?></a>
					<?php endif; ?>
				<?php endforeach; ?>
			</nav>
			<?php if ( $manage ) : ?>
				<div class="pct-drawer-foot">
					<?php
					$provider = PCT_Settings::get( 'ai_provider' );
					echo esc_html( sprintf(
						/* translators: 1: AI provider, 2: map provider */
						__( 'AI: %1$s · Maps: %2$s', 'personalized-city-tour' ),
						PCT_AI::enabled() ? $provider : __( 'rules only', 'personalized-city-tour' ),
						PCT_Settings::get( 'map_provider' )
					) );
					?>
				</div>
			<?php endif; ?>
		</aside>
		<?php
	}

	private static function current_admin_url() {
		return admin_url( basename( isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : 'admin.php?page=' . self::MENU ) );
	}

	public static function handle_set_active_city() {
		check_admin_referer( 'pct_set_active_city' );
		if ( ! current_user_can( 'edit_pct_places' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'personalized-city-tour' ) );
		}
		update_user_meta( get_current_user_id(), '_pct_active_city', absint( $_POST['city_id'] ?? 0 ) );
		$redirect = isset( $_POST['redirect'] ) ? esc_url_raw( wp_unslash( $_POST['redirect'] ) ) : admin_url( 'admin.php?page=' . self::MENU );
		wp_safe_redirect( $redirect );
		exit;
	}

	/**
	 * Apply active-city filter and restrict guides to their own posts.
	 */
	public static function filter_lists( WP_Query $query ) {
		if ( ! is_admin() || ! $query->is_main_query() ) {
			return;
		}
		$type = $query->get( 'post_type' );
		if ( ! in_array( $type, array( PCT_Post_Types::PLACE, PCT_Post_Types::EVENT, PCT_Post_Types::TOUR ), true ) ) {
			return;
		}
		$city = isset( $_GET['pct_city'] ) ? absint( $_GET['pct_city'] ) : (int) get_user_meta( get_current_user_id(), '_pct_active_city', true ); // phpcs:ignore WordPress.Security.NonceVerification
		if ( $city ) {
			$meta   = (array) $query->get( 'meta_query' );
			$meta[] = array(
				'key'   => '_pct_city_id',
				'value' => $city,
			);
			$query->set( 'meta_query', $meta );
		}
		$others_cap = PCT_Post_Types::EVENT === $type ? 'edit_others_pct_events' : 'edit_others_pct_places';
		if ( PCT_Post_Types::TOUR !== $type && ! current_user_can( $others_cap ) ) {
			$query->set( 'author', get_current_user_id() );
		}
	}

	public static function city_filter_dropdown( $post_type ) {
		if ( ! in_array( $post_type, array( PCT_Post_Types::PLACE, PCT_Post_Types::EVENT, PCT_Post_Types::TOUR ), true ) ) {
			return;
		}
		$current = isset( $_GET['pct_city'] ) ? absint( $_GET['pct_city'] ) : (int) get_user_meta( get_current_user_id(), '_pct_active_city', true ); // phpcs:ignore WordPress.Security.NonceVerification
		echo '<select name="pct_city"><option value="0">' . esc_html__( 'All cities', 'personalized-city-tour' ) . '</option>';
		foreach ( PCT_Post_Types::cities() as $id => $name ) {
			printf( '<option value="%d" %s>%s</option>', (int) $id, selected( $current, $id, false ), esc_html( $name ) );
		}
		echo '</select>';
	}

	/* ---------------------------------------------------------------- */

	public static function columns( $columns ) {
		$new = array();
		foreach ( $columns as $key => $label ) {
			$new[ $key ] = $label;
			if ( 'title' === $key ) {
				$new['pct_city']     = __( 'City', 'personalized-city-tour' );
				$new['pct_location'] = __( 'Location', 'personalized-city-tour' );
				$new['pct_access']   = __( 'Accessibility', 'personalized-city-tour' );
			}
		}
		return $new;
	}

	public static function column_content( $column, $post_id ) {
		switch ( $column ) {
			case 'pct_city':
				$city = (int) get_post_meta( $post_id, '_pct_city_id', true );
				echo $city ? esc_html( get_the_title( $city ) ) : '—';
				break;
			case 'pct_location':
				$lat = get_post_meta( $post_id, '_pct_lat', true );
				$lng = get_post_meta( $post_id, '_pct_lng', true );
				echo esc_html( get_post_meta( $post_id, '_pct_address', true ) );
				if ( $lat && $lng ) {
					echo '<br><code>' . esc_html( round( (float) $lat, 5 ) . ', ' . round( (float) $lng, 5 ) ) . '</code>';
				} else {
					echo '<br><span class="pct-warn">' . esc_html__( 'No coordinates', 'personalized-city-tour' ) . '</span>';
				}
				if ( PCT_Post_Types::EVENT === get_post_type( $post_id ) ) {
					echo '<br>' . esc_html( get_post_meta( $post_id, '_pct_start', true ) );
				}
				break;
			case 'pct_access':
				$acc  = (array) get_post_meta( $post_id, '_pct_accessibility', true );
				$opts = PCT_Data::accessibility_options();
				$out  = array();
				foreach ( $acc as $a ) {
					if ( isset( $opts[ $a ] ) ) {
						$out[] = $opts[ $a ];
					}
				}
				echo $out ? esc_html( implode( ', ', $out ) ) : '—';
				break;
		}
	}

	public static function city_columns( $columns ) {
		$columns['pct_stats'] = __( 'Database', 'personalized-city-tour' );
		return $columns;
	}

	public static function city_column_content( $column, $post_id ) {
		if ( 'pct_stats' !== $column ) {
			return;
		}
		$places = count( PCT_Data::places( $post_id ) );
		$events = count( PCT_Data::events( $post_id ) );
		/* translators: 1: number of places, 2: number of events */
		echo esc_html( sprintf( __( '%1$d entries · %2$d events', 'personalized-city-tour' ), $places, $events ) );
		$maps = PCT_Data::mymaps( $post_id );
		if ( $maps ) {
			/* translators: %d: number of Google My Maps */
			echo '<br>' . esc_html( sprintf( _n( '%d My Maps link', '%d My Maps links', count( $maps ), 'personalized-city-tour' ), count( $maps ) ) );
		}
	}

	public static function tour_columns( $columns ) {
		return array(
			'cb'           => $columns['cb'],
			'title'        => __( 'Tour', 'personalized-city-tour' ),
			'pct_city'     => __( 'City', 'personalized-city-tour' ),
			'pct_traveler' => __( 'Traveller', 'personalized-city-tour' ),
			'pct_provider' => __( 'Planner', 'personalized-city-tour' ),
			'date'         => __( 'Date', 'personalized-city-tour' ),
		);
	}

	public static function tour_column_content( $column, $post_id ) {
		switch ( $column ) {
			case 'pct_city':
				echo esc_html( get_the_title( (int) get_post_meta( $post_id, '_pct_city_id', true ) ) );
				break;
			case 'pct_traveler':
				$form = (array) get_post_meta( $post_id, '_pct_form', true );
				echo esc_html( trim( ( $form['name'] ?? '' ) . ' ' . ( ! empty( $form['email'] ) ? '<' . $form['email'] . '>' : '' ) ) );
				break;
			case 'pct_provider':
				echo esc_html( get_post_meta( $post_id, '_pct_provider', true ) );
				break;
		}
	}

	public static function plugin_links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( admin_url( 'admin.php?page=pct-settings' ) ) . '">' . esc_html__( 'Settings', 'personalized-city-tour' ) . '</a>' );
		return $links;
	}

	/* ---------------------------------------------------------------- */

	public static function render_dashboard() {
		$counts = array(
			'cities' => wp_count_posts( PCT_Post_Types::CITY )->publish,
			'places' => wp_count_posts( PCT_Post_Types::PLACE )->publish,
			'events' => wp_count_posts( PCT_Post_Types::EVENT )->publish,
			'pending' => wp_count_posts( PCT_Post_Types::EVENT )->pending + wp_count_posts( PCT_Post_Types::PLACE )->pending,
			'tours'  => wp_count_posts( PCT_Post_Types::TOUR )->private,
			'guides' => count( PCT_Roles::guides( PCT_Roles::STATUS_PENDING ) ),
		);
		include PCT_DIR . 'admin/views/dashboard.php';
	}

	public static function render_settings() {
		$settings  = PCT_Settings::get();
		$providers = PCT_AI::providers();
		$tabs      = PCT_Settings::tabs();
		$tab       = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'general'; // phpcs:ignore WordPress.Security.NonceVerification
		if ( ! isset( $tabs[ $tab ] ) ) {
			$tab = 'general';
		}
		$msg      = isset( $_GET['pct_msg'] ) ? sanitize_key( $_GET['pct_msg'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$messages = array(
			'drive_connected'    => __( 'Google Drive connected.', 'personalized-city-tour' ),
			'drive_disconnected' => __( 'Google Drive disconnected.', 'personalized-city-tour' ),
			'drive_exported'     => __( 'Exports finished.', 'personalized-city-tour' ),
			'drive_partial'      => __( 'Some exports failed — see the Drive log below.', 'personalized-city-tour' ),
			'drive_error'        => __( 'Google Drive authorisation failed.', 'personalized-city-tour' ),
			'bundle_saved'       => __( 'Language bundle saved.', 'personalized-city-tour' ),
			'bundle_deleted'     => __( 'Uploaded bundle removed.', 'personalized-city-tour' ),
			'bundle_error'       => __( 'The uploaded file could not be read (expected .json or .po).', 'personalized-city-tour' ),
		);
		include PCT_DIR . 'admin/views/settings.php';
	}

	public static function render_form_builder() {
		include PCT_DIR . 'admin/views/form-builder.php';
	}

	public static function render_planner() {
		$planner = PCT_Planner::get();
		$fields  = PCT_Form_Builder::field_map( PCT_Form_Builder::get_schema() );
		$msg     = isset( $_GET['pct_msg'] ) ? sanitize_key( $_GET['pct_msg'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		include PCT_DIR . 'admin/views/planner.php';
	}

	public static function render_analytics() {
		if ( ! PCT_Analytics::can_access() ) {
			wp_die( esc_html__( 'You do not have access to the analytics center.', 'personalized-city-tour' ) );
		}
		$jobs   = PCT_Analytics::jobs();
		$report = isset( $_GET['report'] ) ? PCT_Analytics::get_job( absint( $_GET['report'] ) ) : null; // phpcs:ignore WordPress.Security.NonceVerification
		if ( ! $report ) {
			foreach ( $jobs as $job ) {
				if ( 'done' === $job['status'] ) {
					$report = $job;
					break;
				}
			}
		}
		$cities  = PCT_Post_Types::cities();
		$fields  = PCT_Form_Builder::field_map( PCT_Form_Builder::get_schema() );
		$formats = PCT_Export::formats();
		$msg     = isset( $_GET['pct_msg'] ) ? sanitize_key( $_GET['pct_msg'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		include PCT_DIR . 'admin/views/analytics.php';
	}

	public static function render_vibe() {
		$vibe     = PCT_Vibe::settings();
		$requests = PCT_Vibe::all();
		$statuses = PCT_Vibe::statuses();
		$outbox   = PCT_SMS::outbox();
		$msg      = isset( $_GET['pct_msg'] ) ? sanitize_key( $_GET['pct_msg'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		include PCT_DIR . 'admin/views/vibe.php';
	}

	public static function render_ai_log() {
		$args = array(
			'kind'     => isset( $_GET['kind'] ) ? sanitize_key( $_GET['kind'] ) : '', // phpcs:ignore WordPress.Security.NonceVerification
			'status'   => isset( $_GET['status'] ) ? sanitize_key( $_GET['status'] ) : '', // phpcs:ignore WordPress.Security.NonceVerification
			'page'     => isset( $_GET['paged'] ) ? absint( $_GET['paged'] ) : 1, // phpcs:ignore WordPress.Security.NonceVerification
			'per_page' => 40,
		);
		$log   = PCT_AI_Log::query( $args );
		$entry = isset( $_GET['entry'] ) ? PCT_AI_Log::get( absint( $_GET['entry'] ) ) : null; // phpcs:ignore WordPress.Security.NonceVerification
		$stats = PCT_AI_Log::stats( 30 );
		$msg   = isset( $_GET['pct_msg'] ) ? sanitize_key( $_GET['pct_msg'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		include PCT_DIR . 'admin/views/ai-log.php';
	}

	public static function handle_purge_log() {
		check_admin_referer( 'pct_purge_log' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'personalized-city-tour' ) );
		}
		PCT_AI_Log::purge( 0 );
		PCT_AI_Log::log( array( 'kind' => 'audit', 'status' => 'info', 'title' => 'AI log purged by ' . wp_get_current_user()->user_login, 'force' => true ) );
		wp_safe_redirect( add_query_arg( array( 'page' => 'pct-ai-log', 'pct_msg' => 'purged' ), admin_url( 'admin.php' ) ) );
		exit;
	}
}
