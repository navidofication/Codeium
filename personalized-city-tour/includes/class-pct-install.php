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
		self::install_storage();
		self::schedule_jobs();

		update_option( 'pct_version', PCT_VERSION );
		flush_rewrite_rules();
	}

	public static function deactivate() {
		wp_clear_scheduled_hook( PCT_AI_Log::CRON_HOOK );
		wp_clear_scheduled_hook( PCT_Drive::CRON_HOOK );
		wp_clear_scheduled_hook( PCT_Analytics::CRON_HOOK );
		flush_rewrite_rules();
	}

	public static function install_storage() {
		PCT_AI_Log::install();
		PCT_Analytics::install();
	}

	public static function schedule_jobs() {
		if ( ! wp_next_scheduled( PCT_AI_Log::CRON_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', PCT_AI_Log::CRON_HOOK );
		}
		if ( ! wp_next_scheduled( PCT_Analytics::CRON_HOOK ) ) {
			wp_schedule_event( time() + 5 * MINUTE_IN_SECONDS, 'hourly', PCT_Analytics::CRON_HOOK );
		}
		PCT_Drive::reschedule();
	}

	public static function maybe_upgrade() {
		if ( get_option( 'pct_version' ) !== PCT_VERSION ) {
			PCT_Roles::add_roles();
			PCT_Post_Types::insert_default_terms();
			self::install_storage();
			self::schedule_jobs();
			update_option( 'pct_version', PCT_VERSION );
		}
	}
}
