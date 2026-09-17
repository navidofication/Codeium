<?php
/**
 * Activation / deactivation / upgrade routines.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PCT_Install {

	public static function activate() {
		PCT_Post_Types::register();
		PCT_Roles::add_roles();
		PCT_Post_Types::insert_default_terms();

		if ( false === get_option( 'pct_settings' ) ) {
			add_option( 'pct_settings', PCT_Settings::defaults() );
		}
		if ( false === get_option( 'pct_form_schema' ) ) {
			add_option( 'pct_form_schema', PCT_Form_Builder::default_schema() );
		}

		update_option( 'pct_version', PCT_VERSION );
		flush_rewrite_rules();
	}

	public static function deactivate() {
		flush_rewrite_rules();
	}

	public static function maybe_upgrade() {
		if ( get_option( 'pct_version' ) !== PCT_VERSION ) {
			PCT_Roles::add_roles();
			PCT_Post_Types::insert_default_terms();
			update_option( 'pct_version', PCT_VERSION );
		}
	}
}
