<?php
/**
 * Plugin bootstrap.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class PCT_Plugin {

	/** @var PCT_Plugin|null */
	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		load_plugin_textdomain( 'personalized-city-tour', false, dirname( plugin_basename( PCT_FILE ) ) . '/languages' );

		PCT_Post_Types::init();
		PCT_Roles::init();
		PCT_Settings::init();
		PCT_I18n::init();
		PCT_Form_Builder::init();
		PCT_Rest::init();
		PCT_Frontend::init();
		PCT_AI_Log::init();
		PCT_Planner::init();
		PCT_Analytics::init();
		PCT_Drive::init();
		PCT_Guides::init();
		PCT_Guide_Profiles::init();
		PCT_SMS::init();
		PCT_Vibe::init();

		if ( is_admin() ) {
			PCT_Admin::init();
			PCT_Meta_Boxes::init();
		}

		add_action( 'init', array( 'PCT_Install', 'maybe_upgrade' ), 20 );
	}
}
