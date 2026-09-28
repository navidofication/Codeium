<?php
/**
 * Form builder view. The editor is rendered by admin/js/form-builder.js.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="wrap pct-wrap pct-form-builder-wrap">
	<h1><?php esc_html_e( 'Travel Preference Form Builder', 'personalized-city-tour' ); ?></h1>
	<p class="description">
		<?php esc_html_e( 'Edit the sections, fields and conditional rules of the traveller questionnaire. Field keys are used by the planner: keep the default keys (group_type, nights, interests, mobility_limitations, dietary, budget, pace, activity_level, local_score, preferred_times, transport_modes, travel_style …) if you want them to influence the generated tours.', 'personalized-city-tour' ); ?>
	</p>

	<div class="pct-fb-toolbar">
		<button type="button" class="button button-primary" id="pct-fb-save"><?php esc_html_e( 'Save form', 'personalized-city-tour' ); ?></button>
		<button type="button" class="button" id="pct-fb-add-section"><?php esc_html_e( 'Add section', 'personalized-city-tour' ); ?></button>
		<button type="button" class="button" id="pct-fb-expand"><?php esc_html_e( 'Expand all', 'personalized-city-tour' ); ?></button>
		<button type="button" class="button" id="pct-fb-collapse"><?php esc_html_e( 'Collapse all', 'personalized-city-tour' ); ?></button>
		<button type="button" class="button button-link-delete" id="pct-fb-reset"><?php esc_html_e( 'Reset to default', 'personalized-city-tour' ); ?></button>
		<span id="pct-fb-status" role="status"></span>
	</div>

	<div id="pct-form-builder" class="pct-fb"></div>

	<details class="pct-fb-json">
		<summary><?php esc_html_e( 'Schema JSON (advanced: import / export)', 'personalized-city-tour' ); ?></summary>
		<textarea id="pct-fb-json" rows="12" class="large-text code"></textarea>
		<button type="button" class="button" id="pct-fb-import"><?php esc_html_e( 'Load JSON into editor', 'personalized-city-tour' ); ?></button>
	</details>
</div>
