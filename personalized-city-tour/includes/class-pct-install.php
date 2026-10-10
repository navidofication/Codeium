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
		PCT_I18n::schedule_ai_bundles();

		update_option( 'pct_version', PCT_VERSION );
		flush_rewrite_rules();
	}

	public static function deactivate() {
		wp_clear_scheduled_hook( PCT_AI_Log::CRON_HOOK );
		wp_clear_scheduled_hook( PCT_Drive::CRON_HOOK );
		wp_clear_scheduled_hook( PCT_Analytics::CRON_HOOK );
		wp_clear_scheduled_hook( 'pct_build_language_bundle' );
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
			// 2.2.24 expands the planner ceilings. Preserve all existing user
			// settings, but migrate the old hard-coded 4-stop/8-stop ceilings so
			// the new 18-stop controls are actually available after upgrade.
			$planner = get_option( 'pct_planner_rules', array() );
			if ( is_array( $planner ) ) {
				if ( isset( $planner['strategy'] ) && is_array( $planner['strategy'] ) ) {
					if ( (int) ( $planner['strategy']['max_stops_per_proposal'] ?? 0 ) <= 4 ) {
						$planner['strategy']['max_stops_per_proposal'] = 18;
					}
				} else {
					$planner['strategy'] = array( 'max_stops_per_proposal' => 18 );
				}
				update_option( 'pct_planner_rules', $planner, false );
			}
			$settings = get_option( 'pct_settings', array() );
			if ( is_array( $settings ) && isset( $settings['stops_per_day'] ) && (int) $settings['stops_per_day'] <= 8 ) {
				$settings['stops_per_day'] = 18;
				update_option( 'pct_settings', $settings, false );
			}

			PCT_Roles::add_roles();
			PCT_Post_Types::insert_default_terms();
			self::install_storage();
			self::schedule_jobs();
			PCT_I18n::schedule_ai_bundles();
			update_option( 'pct_version', PCT_VERSION );
		}
	}
}
