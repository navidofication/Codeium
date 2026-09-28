<?php
/**
 * Settings view (tabbed). Variables: $settings, $providers, $tabs, $tab, $msg, $messages.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$secret_keys = PCT_Settings::secret_keys();

/**
 * Password field for an encrypted secret with "clear" checkbox and constant hint.
 */
$secret_field = function ( $key, $id, $description = '' ) use ( $settings, $secret_keys ) {
	$const    = isset( $secret_keys[ $key ] ) ? $secret_keys[ $key ] : '';
	$in_const = $const && defined( $const ) && constant( $const );
	$has      = PCT_Settings::has_secret( $key );
	?>
	<input type="password" id="<?php echo esc_attr( $id ); ?>" name="pct_settings[<?php echo esc_attr( $key ); ?>]" value="" class="regular-text" autocomplete="new-password" placeholder="<?php echo $has ? esc_attr__( '•••••••• (saved)', 'personalized-city-tour' ) : ''; ?>" <?php disabled( $in_const ); ?> />
	<?php if ( $has && ! $in_const ) : ?>
		<label class="pct-inline"><input type="checkbox" name="pct_settings[<?php echo esc_attr( $key ); ?>_clear]" value="1" /> <?php esc_html_e( 'Clear saved value', 'personalized-city-tour' ); ?></label>
	<?php endif; ?>
	<p class="description">
		<?php echo esc_html( $description ); ?>
		<?php if ( $in_const ) : ?>
			<strong><?php echo esc_html( sprintf( /* translators: %s: constant name */ __( 'Defined via the %s constant in wp-config.php (overrides the stored value).', 'personalized-city-tour' ), $const ) ); ?></strong>
		<?php elseif ( $const ) : ?>
			<?php echo esc_html( sprintf( /* translators: %s: constant name */ __( 'Leave empty to keep the saved value. Stored encrypted; or define %s in wp-config.php to keep it out of the database.', 'personalized-city-tour' ), $const ) ); ?>
		<?php endif; ?>
	</p>
	<?php
};
$tab_url = function ( $key ) {
	return add_query_arg( array( 'page' => 'pct-settings', 'tab' => $key ), admin_url( 'admin.php' ) );
};
?>
<div class="wrap pct-wrap">
	<h1><?php esc_html_e( 'City Tour Settings', 'personalized-city-tour' ); ?></h1>
	<?php settings_errors(); ?>
	<?php if ( $msg && isset( $messages[ $msg ] ) ) : ?>
		<div class="notice <?php echo false !== strpos( $msg, 'error' ) || 'drive_partial' === $msg ? 'notice-error' : 'notice-success'; ?> is-dismissible"><p><?php echo esc_html( $messages[ $msg ] ); ?></p></div>
	<?php endif; ?>

	<nav class="nav-tab-wrapper pct-tabs">
		<?php foreach ( $tabs as $key => $label ) : ?>
			<a href="<?php echo esc_url( $tab_url( $key ) ); ?>" class="nav-tab <?php echo $tab === $key ? 'nav-tab-active' : ''; ?>"><?php echo esc_html( $label ); ?></a>
		<?php endforeach; ?>
	</nav>

	<form method="post" action="options.php" class="pct-settings">
		<?php settings_fields( 'pct_settings_group' ); ?>
		<input type="hidden" name="pct_settings[_tab]" value="<?php echo esc_attr( $tab ); ?>" />

		<?php if ( 'general' === $tab ) : ?>
		<h2><?php esc_html_e( 'Access control', 'personalized-city-tour' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'Who can plan tours', 'personalized-city-tour' ); ?></th>
				<td>
					<label><input type="radio" name="pct_settings[access_mode]" value="public" <?php checked( $settings['access_mode'], 'public' ); ?> /> <?php esc_html_e( 'Everyone (public)', 'personalized-city-tour' ); ?></label><br>
					<label><input type="radio" name="pct_settings[access_mode]" value="logged_in" <?php checked( $settings['access_mode'], 'logged_in' ); ?> /> <?php esc_html_e( 'Logged-in users only', 'personalized-city-tour' ); ?></label>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Local guides', 'personalized-city-tour' ); ?></th>
				<td>
					<label><input type="checkbox" name="pct_settings[guide_registration]" value="1" <?php checked( $settings['guide_registration'] ); ?> /> <?php esc_html_e( 'Allow visitors to register as local guides from the frontend (no WordPress login needed — name + mobile number)', 'personalized-city-tour' ); ?></label><br>
					<label><input type="checkbox" name="pct_settings[guide_require_mobile]" value="1" <?php checked( $settings['guide_require_mobile'] ); ?> /> <?php esc_html_e( 'Organizer mobile number is required for every event', 'personalized-city-tour' ); ?></label><br>
					<label><input type="checkbox" name="pct_settings[guide_auto_publish]" value="1" <?php checked( $settings['guide_auto_publish'] ); ?> /> <?php esc_html_e( 'Publish events of verified guides immediately (otherwise every event waits for review)', 'personalized-city-tour' ); ?></label><br>
					<label><input type="checkbox" name="pct_settings[guide_public_events]" value="1" <?php checked( $settings['guide_public_events'] ); ?> /> <?php esc_html_e( 'Show organizer contact details on public event output', 'personalized-city-tour' ); ?></label><br>
					<label><input type="checkbox" name="pct_settings[notify_admin_guides]" value="1" <?php checked( $settings['notify_admin_guides'] ); ?> /> <?php esc_html_e( 'Email the site admin about new guide registrations', 'personalized-city-tour' ); ?></label>
					<p class="description"><?php esc_html_e( 'New guides stay “pending” until an administrator verifies them by phone and clicks Approve on the Local Guides screen. Guides are notified by SMS/e-mail without any login link.', 'personalized-city-tour' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Submissions', 'personalized-city-tour' ); ?></th>
				<td>
					<label><input type="checkbox" name="pct_settings[store_submissions]" value="1" <?php checked( $settings['store_submissions'] ); ?> /> <?php esc_html_e( 'Store submitted forms and generated tours (Generated Tours screen, shareable result links, analytics)', 'personalized-city-tour' ); ?></label>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="pct_stops_per_day"><?php esc_html_e( 'Suggested stops per day', 'personalized-city-tour' ); ?></label></th>
				<td><input type="number" id="pct_stops_per_day" name="pct_settings[stops_per_day]" value="<?php echo esc_attr( $settings['stops_per_day'] ); ?>" min="1" max="8" class="small-text" />
				<p class="description"><?php esc_html_e( 'Adjusted automatically by the traveller’s chosen pace.', 'personalized-city-tour' ); ?></p></td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Calendar', 'personalized-city-tour' ); ?></th>
				<td>
					<select name="pct_settings[calendar]">
						<option value="both" <?php selected( $settings['calendar'], 'both' ); ?>><?php esc_html_e( 'Follow the language (Persian → Jalali, others → Gregorian)', 'personalized-city-tour' ); ?></option>
						<option value="jalali" <?php selected( $settings['calendar'], 'jalali' ); ?>><?php esc_html_e( 'Always Jalali (شمسی)', 'personalized-city-tour' ); ?></option>
						<option value="gregorian" <?php selected( $settings['calendar'], 'gregorian' ); ?>><?php esc_html_e( 'Always Gregorian', 'personalized-city-tour' ); ?></option>
					</select>
					<p class="description"><?php esc_html_e( 'Applies to date pickers in the admin event editor and the traveller form, and to dates in tour titles.', 'personalized-city-tour' ); ?></p>
				</td>
			</tr>
		</table>

		<h2><?php esc_html_e( 'Analytics center', 'personalized-city-tour' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'Roles with access', 'personalized-city-tour' ); ?></th>
				<td>
					<?php foreach ( wp_roles()->roles as $role_key => $role ) : ?>
						<label class="pct-inline"><input type="checkbox" name="pct_settings[analytics_roles][]" value="<?php echo esc_attr( $role_key ); ?>" <?php checked( in_array( $role_key, (array) $settings['analytics_roles'], true ) || 'administrator' === $role_key ); ?> <?php disabled( 'administrator' === $role_key ); ?> /> <?php echo esc_html( translate_user_role( $role['name'] ) ); ?></label>
					<?php endforeach; ?>
					<p class="description"><?php esc_html_e( 'Only these roles see the Analytics menu and can request analyses or download exports. Every action is written to the AI audit log.', 'personalized-city-tour' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="pct_analytics_retention"><?php esc_html_e( 'Keep reports for (days)', 'personalized-city-tour' ); ?></label></th>
				<td><input type="number" id="pct_analytics_retention" name="pct_settings[analytics_retention_days]" value="<?php echo esc_attr( $settings['analytics_retention_days'] ); ?>" min="0" max="3650" class="small-text" /> <span class="description"><?php esc_html_e( '0 = keep forever', 'personalized-city-tour' ); ?></span></td>
			</tr>
		</table>
		<?php endif; ?>

		<?php if ( 'ai' === $tab ) : ?>
		<p class="description"><?php esc_html_e( 'The AI analyses the submitted travel preference form and composes tour options strictly from your city database. Responses are validated against a strict JSON schema; invalid answers are logged and retried once with the validation errors. Without a provider (or when the AI fails) the built-in rule based planner is used.', 'personalized-city-tour' ); ?></p>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="pct_ai_provider"><?php esc_html_e( 'Provider', 'personalized-city-tour' ); ?></label></th>
				<td>
					<select id="pct_ai_provider" name="pct_settings[ai_provider]">
						<?php foreach ( $providers as $key => $label ) : ?>
							<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $settings['ai_provider'], $key ); ?>><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
					<label class="pct-inline"><input type="checkbox" name="pct_settings[ai_sandbox]" value="1" <?php checked( $settings['ai_sandbox'] ); ?> /> <?php esc_html_e( 'Sandbox / mock mode (no external calls, deterministic sample answers — for testing)', 'personalized-city-tour' ); ?></label>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="pct_ai_api_key"><?php esc_html_e( 'API key', 'personalized-city-tour' ); ?></label></th>
				<td><?php $secret_field( 'ai_api_key', 'pct_ai_api_key', __( 'Devin.ai: personal API token from app.devin.ai → Settings → API keys.', 'personalized-city-tour' ) ); ?></td>
			</tr>
			<tr>
				<th scope="row"><label for="pct_ai_model"><?php esc_html_e( 'Model', 'personalized-city-tour' ); ?></label></th>
				<td>
					<?php
					$model_lists = array();
					foreach ( array_keys( $providers ) as $p ) {
						$model_lists[ $p ] = PCT_AI::models( $p );
					}
					$current_models = $model_lists[ $settings['ai_provider'] ] ?? array();
					$known          = false;
					foreach ( $current_models as $m ) {
						if ( $m['model'] === $settings['ai_model'] ) {
							$known = true;
						}
					}
					?>
					<select id="pct_ai_model" name="pct_settings[ai_model]" data-models="<?php echo esc_attr( wp_json_encode( $model_lists ) ); ?>" data-current="<?php echo esc_attr( $settings['ai_model'] ); ?>">
						<?php foreach ( $current_models as $m ) : ?>
							<option value="<?php echo esc_attr( $m['model'] ); ?>" <?php selected( $settings['ai_model'], $m['model'] ); ?>><?php echo esc_html( $m['label'] ? $m['label'] . ' — ' . $m['model'] : $m['model'] ); ?></option>
						<?php endforeach; ?>
						<option value="__custom" <?php selected( ! $known ); ?>><?php esc_html_e( 'Other (type below)', 'personalized-city-tour' ); ?></option>
					</select>
					<input type="text" id="pct_ai_model_custom" name="pct_settings[ai_model_custom]" value="<?php echo $known ? '' : esc_attr( $settings['ai_model'] ); ?>" class="regular-text" placeholder="<?php esc_attr_e( 'custom model id', 'personalized-city-tour' ); ?>" />
					<p class="description"><?php esc_html_e( 'The list follows the selected provider and includes your provider → model mappings below.', 'personalized-city-tour' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="pct_ai_deployment"><?php esc_html_e( 'Deployment (Azure / gateways)', 'personalized-city-tour' ); ?></label></th>
				<td><input type="text" id="pct_ai_deployment" name="pct_settings[ai_deployment]" value="<?php echo esc_attr( $settings['ai_deployment'] ); ?>" class="regular-text" />
				<p class="description"><?php esc_html_e( 'Optional. Overrides the deployment from the model mapping.', 'personalized-city-tour' ); ?></p></td>
			</tr>
			<tr>
				<th scope="row"><label for="pct_ai_endpoint"><?php esc_html_e( 'Endpoint URL', 'personalized-city-tour' ); ?></label></th>
				<td><input type="url" id="pct_ai_endpoint" name="pct_settings[ai_endpoint]" value="<?php echo esc_attr( $settings['ai_endpoint'] ); ?>" class="large-text" />
				<p class="description"><?php esc_html_e( 'Optional for Devin.ai / OpenAI / Anthropic. Required for Azure OpenAI (https://RESOURCE.openai.azure.com) and custom OpenAI-compatible servers (…/v1).', 'personalized-city-tour' ); ?></p></td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Provider → model → deployment mapping', 'personalized-city-tour' ); ?></th>
				<td>
					<table class="widefat pct-repeater" id="pct-model-map">
						<thead><tr><th><?php esc_html_e( 'Provider', 'personalized-city-tour' ); ?></th><th><?php esc_html_e( 'Model id', 'personalized-city-tour' ); ?></th><th><?php esc_html_e( 'Deployment', 'personalized-city-tour' ); ?></th><th><?php esc_html_e( 'Label', 'personalized-city-tour' ); ?></th><th></th></tr></thead>
						<tbody>
						<?php
						$rows = array();
						foreach ( (array) $settings['ai_model_map'] as $p => $list ) {
							foreach ( (array) $list as $row ) {
								$rows[] = array_merge( array( 'provider' => $p ), $row );
							}
						}
						$rows[] = array( 'provider' => '', 'model' => '', 'deployment' => '', 'label' => '' );
						foreach ( $rows as $i => $row ) :
							?>
							<tr>
								<td><select name="pct_settings[ai_model_map][<?php echo (int) $i; ?>][provider]">
									<option value=""><?php esc_html_e( '— provider —', 'personalized-city-tour' ); ?></option>
									<?php foreach ( $providers as $key => $label ) : if ( 'none' === $key ) { continue; } ?>
										<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $row['provider'], $key ); ?>><?php echo esc_html( $key ); ?></option>
									<?php endforeach; ?>
								</select></td>
								<td><input type="text" name="pct_settings[ai_model_map][<?php echo (int) $i; ?>][model]" value="<?php echo esc_attr( $row['model'] ); ?>" /></td>
								<td><input type="text" name="pct_settings[ai_model_map][<?php echo (int) $i; ?>][deployment]" value="<?php echo esc_attr( $row['deployment'] ); ?>" /></td>
								<td><input type="text" name="pct_settings[ai_model_map][<?php echo (int) $i; ?>][label]" value="<?php echo esc_attr( $row['label'] ); ?>" /></td>
								<td><button type="button" class="button-link-delete pct-repeater-remove" aria-label="<?php esc_attr_e( 'Remove', 'personalized-city-tour' ); ?>">&times;</button></td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
					<button type="button" class="button pct-repeater-add" data-target="pct-model-map"><?php esc_html_e( 'Add mapping', 'personalized-city-tour' ); ?></button>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Privacy & consent', 'personalized-city-tour' ); ?></th>
				<td>
					<label><input type="checkbox" name="pct_settings[ai_consent_city_db]" value="1" <?php checked( $settings['ai_consent_city_db'] ); ?> /> <strong><?php esc_html_e( 'I allow city database content (places, events, channels) to be sent to the selected AI provider', 'personalized-city-tour' ); ?></strong></label>
					<p class="description"><?php esc_html_e( 'Required for AI tour generation. Only the fields ticked below are transmitted.', 'personalized-city-tour' ); ?></p>
					<?php foreach ( PCT_Tour_Generator::extract_fields() as $fk => $fl ) : ?>
						<label class="pct-inline"><input type="checkbox" name="pct_settings[ai_allowed_fields][]" value="<?php echo esc_attr( $fk ); ?>" <?php checked( in_array( $fk, (array) $settings['ai_allowed_fields'], true ) ); ?> /> <?php echo esc_html( $fl ); ?></label>
					<?php endforeach; ?>
					<br>
					<fieldset class="pct-consent-links">
						<legend><?php esc_html_e( 'Send city database — access links', 'personalized-city-tour' ); ?></legend>
						<label><input type="checkbox" name="pct_settings[ai_attach_drive_links]" value="1" <?php checked( $settings['ai_attach_drive_links'] ); ?> /> <?php esc_html_e( 'Attach access links to the newest city-database files stored in Google Drive', 'personalized-city-tour' ); ?></label><br>
						<label><input type="checkbox" name="pct_settings[ai_attach_upload_links]" value="1" <?php checked( $settings['ai_attach_upload_links'] ); ?> /> <?php esc_html_e( 'Attach access links to the newest exports written to the uploads folder (wp-content/uploads/pct-exports)', 'personalized-city-tour' ); ?></label>
						<p class="description"><?php esc_html_e( 'Only active together with the consent above. Drive files are shared as "anyone with the link (viewer)"; uploads links are signed and expire after 24 hours. Exports are refreshed automatically after every save of a city, place or event (see the Google Drive tab), so the AI reads the current database when generating tours.', 'personalized-city-tour' ); ?>
						<?php $pct_latest = PCT_Drive::latest(); if ( $pct_latest ) : ?>
							<br><?php esc_html_e( 'Newest exports:', 'personalized-city-tour' ); ?>
							<?php foreach ( $pct_latest as $lt => $lrow ) : ?>
								<code><?php echo esc_html( $lt . ' · ' . wp_date( 'Y-m-d H:i', (int) $lrow['time'] ) . ( ! empty( $lrow['drive_id'] ) ? ' · Drive' : '' ) . ( ! empty( $lrow['path'] ) ? ' · uploads' : '' ) ); ?></code>
							<?php endforeach; ?>
						<?php else : ?>
							<br><?php esc_html_e( 'No export exists yet — save any city or use "Refresh now" in the Google Drive tab.', 'personalized-city-tour' ); ?>
						<?php endif; ?>
						</p>
					</fieldset>
					<label><input type="checkbox" name="pct_settings[ai_share_name]" value="1" <?php checked( $settings['ai_share_name'] ); ?> /> <?php esc_html_e( 'Include the traveller’s name in the request title / prompt', 'personalized-city-tour' ); ?></label><br>
					<label><input type="checkbox" name="pct_settings[ai_share_contact]" value="1" <?php checked( $settings['ai_share_contact'] ); ?> /> <?php esc_html_e( 'Include e-mail / phone in the prompt (off by default)', 'personalized-city-tour' ); ?></label><br>
					<label><input type="checkbox" name="pct_settings[ai_redact_pii]" value="1" <?php checked( $settings['ai_redact_pii'] ); ?> /> <?php esc_html_e( 'Redact e-mails, phone numbers and secrets in the audit log', 'personalized-city-tour' ); ?></label>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="pct_ai_log_level"><?php esc_html_e( 'Logging level', 'personalized-city-tour' ); ?></label></th>
				<td>
					<select id="pct_ai_log_level" name="pct_settings[ai_log_level]">
						<option value="none" <?php selected( $settings['ai_log_level'], 'none' ); ?>><?php esc_html_e( 'None', 'personalized-city-tour' ); ?></option>
						<option value="minimal" <?php selected( $settings['ai_log_level'], 'minimal' ); ?>><?php esc_html_e( 'Minimal (status, model, duration, title)', 'personalized-city-tour' ); ?></option>
						<option value="full" <?php selected( $settings['ai_log_level'], 'full' ); ?>><?php esc_html_e( 'Full (also prompts and raw responses)', 'personalized-city-tour' ); ?></option>
					</select>
					<input type="number" name="pct_settings[ai_log_retention_days]" value="<?php echo esc_attr( $settings['ai_log_retention_days'] ); ?>" min="0" max="3650" class="small-text" /> <span class="description"><?php esc_html_e( 'days retention (0 = forever)', 'personalized-city-tour' ); ?></span>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=pct-ai-log' ) ); ?>" class="button"><?php esc_html_e( 'Open audit log', 'personalized-city-tour' ); ?></a>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Limits & reliability', 'personalized-city-tour' ); ?></th>
				<td>
					<label><?php esc_html_e( 'Global AI calls per hour', 'personalized-city-tour' ); ?> <input type="number" name="pct_settings[ai_rate_limit]" value="<?php echo esc_attr( $settings['ai_rate_limit'] ); ?>" min="0" class="small-text" /></label>
					<label><?php esc_html_e( 'Per visitor per hour', 'personalized-city-tour' ); ?> <input type="number" name="pct_settings[ai_rate_limit_ip]" value="<?php echo esc_attr( $settings['ai_rate_limit_ip'] ); ?>" min="0" class="small-text" /></label>
					<label><?php esc_html_e( 'Timeout (s)', 'personalized-city-tour' ); ?> <input type="number" name="pct_settings[ai_timeout]" value="<?php echo esc_attr( $settings['ai_timeout'] ); ?>" min="15" max="600" class="small-text" /></label>
					<label><?php esc_html_e( 'Temperature', 'personalized-city-tour' ); ?> <input type="number" name="pct_settings[ai_temperature]" value="<?php echo esc_attr( $settings['ai_temperature'] ); ?>" min="0" max="2" step="0.1" class="small-text" /></label>
					<label><?php esc_html_e( 'Max database items per request', 'personalized-city-tour' ); ?> <input type="number" name="pct_settings[ai_max_items]" value="<?php echo esc_attr( $settings['ai_max_items'] ); ?>" min="10" max="300" class="small-text" /></label>
					<br><label><input type="checkbox" name="pct_settings[ai_retry_invalid]" value="1" <?php checked( $settings['ai_retry_invalid'] ); ?> /> <?php esc_html_e( 'Retry once with the schema errors when the response is invalid', 'personalized-city-tour' ); ?></label>
					<p class="description"><?php esc_html_e( '0 = unlimited. When a limit is hit the visitor still receives a tour from the rule based planner. Analysis reports run through the WP-Cron queue.', 'personalized-city-tour' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'On-demand usage controls', 'personalized-city-tour' ); ?></th>
				<td>
					<label><?php esc_html_e( 'Cache generated tours for', 'personalized-city-tour' ); ?> <input type="number" name="pct_settings[ai_cache_ttl]" value="<?php echo esc_attr( $settings['ai_cache_ttl'] ); ?>" min="0" max="43200" class="small-text" /> <?php esc_html_e( 'minutes', 'personalized-city-tour' ); ?></label>
					<label><?php esc_html_e( 'Batching window', 'personalized-city-tour' ); ?> <input type="number" name="pct_settings[ai_batch_window]" value="<?php echo esc_attr( $settings['ai_batch_window'] ); ?>" min="0" max="120" class="small-text" /> <?php esc_html_e( 'seconds', 'personalized-city-tour' ); ?></label>
					<p class="description"><?php esc_html_e( 'Identical preference payloads (same city, answers, language, rules and model) reuse the cached AI plan for the TTL; the traveller\'s name and contact details are never part of the cache key. Within the batching window, duplicate requests wait for the in-flight answer instead of calling the provider again. The cache is cleared automatically when city data or planner rules change. 0 disables either control.', 'personalized-city-tour' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="pct_ai_prompt_template"><?php esc_html_e( 'Extra prompt instructions', 'personalized-city-tour' ); ?></label></th>
				<td><textarea id="pct_ai_prompt_template" name="pct_settings[ai_prompt_template]" rows="4" class="large-text"><?php echo esc_textarea( $settings['ai_prompt_template'] ); ?></textarea>
				<p class="description"><?php esc_html_e( 'Appended to the system prompt (tone, house rules, must-mention info). Planner rules are added automatically.', 'personalized-city-tour' ); ?></p></td>
			</tr>
			<tr>
				<th scope="row"></th>
				<td>
					<button type="button" class="button" id="pct-ai-test"><?php esc_html_e( 'Test connection (uses saved settings)', 'personalized-city-tour' ); ?></button>
					<button type="button" class="button pct-diagnose" data-target="ai" data-result="pct-diag-ai"><?php esc_html_e( 'Diagnostic', 'personalized-city-tour' ); ?></button>
					<span id="pct-ai-test-result"></span>
					<div id="pct-diag-ai" class="pct-diag" hidden></div>
				</td>
			</tr>
		</table>
		<?php endif; ?>

		<?php if ( 'languages' === $tab ) : ?>
		<?php $langs = PCT_I18n::languages(); $stats = PCT_I18n::bundle_stats(); ?>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="pct_language"><?php esc_html_e( 'Default language', 'personalized-city-tour' ); ?></label></th>
				<td>
					<select id="pct_language" name="pct_settings[language]">
						<?php foreach ( $langs as $code => $meta ) : ?>
							<option value="<?php echo esc_attr( $code ); ?>" <?php selected( $settings['language'], $code ); ?>><?php echo esc_html( $meta['label'] . ' (' . $meta['english'] . ')' ); ?></option>
						<?php endforeach; ?>
					</select>
					<p class="description"><?php esc_html_e( 'Used for the frontend until the visitor picks another language, and as the fallback for the admin. Each admin user can pick their own language from the top bar.', 'personalized-city-tour' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Languages offered to visitors', 'personalized-city-tour' ); ?></th>
				<td>
					<?php foreach ( $langs as $code => $meta ) : ?>
						<label class="pct-inline"><input type="checkbox" name="pct_settings[enabled_languages][]" value="<?php echo esc_attr( $code ); ?>" <?php checked( in_array( $code, (array) $settings['enabled_languages'], true ) ); ?> /> <?php echo esc_html( $meta['label'] ); ?><?php echo $meta['rtl'] ? ' <small>(RTL)</small>' : ''; ?></label>
					<?php endforeach; ?>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="pct_translation_provider"><?php esc_html_e( 'Online translation (optional)', 'personalized-city-tour' ); ?></label></th>
				<td>
					<select id="pct_translation_provider" name="pct_settings[translation_provider]">
						<?php foreach ( PCT_I18n::translation_providers() as $key => $label ) : ?>
							<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $settings['translation_provider'], $key ); ?>><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
					<input type="url" name="pct_settings[translation_endpoint]" value="<?php echo esc_attr( $settings['translation_endpoint'] ); ?>" class="regular-text" placeholder="<?php esc_attr_e( 'Endpoint (LibreTranslate / custom)', 'personalized-city-tour' ); ?>" />
					<br><?php $secret_field( 'translation_api_key', 'pct_translation_api_key', __( 'Strings missing from the bundles are translated on demand and cached.', 'personalized-city-tour' ) ); ?>
					<label><input type="checkbox" name="pct_settings[translate_ai_output]" value="1" <?php checked( $settings['translate_ai_output'] ); ?> /> <?php esc_html_e( 'Also translate rule based planner output into the visitor’s language', 'personalized-city-tour' ); ?></label>
				</td>
			</tr>
		</table>
		<h2><?php esc_html_e( 'Language bundles', 'personalized-city-tour' ); ?></h2>
		<p class="description"><?php esc_html_e( 'Built-in bundles ship in /languages as JSON (source string → translation). Upload a .json or .po file to add or override strings for a language; uploads are stored in the database and survive plugin updates.', 'personalized-city-tour' ); ?></p>
		<table class="widefat striped">
			<thead><tr><th><?php esc_html_e( 'Language', 'personalized-city-tour' ); ?></th><th><?php esc_html_e( 'Built-in strings', 'personalized-city-tour' ); ?></th><th><?php esc_html_e( 'Uploaded strings', 'personalized-city-tour' ); ?></th><th></th></tr></thead>
			<tbody>
			<?php foreach ( $langs as $code => $meta ) : ?>
				<tr>
					<td><?php echo esc_html( $meta['label'] . ' — ' . $meta['english'] ); ?> <code><?php echo esc_html( $code ); ?></code></td>
					<td><?php echo 'en' === $code ? esc_html__( 'source language', 'personalized-city-tour' ) : (int) $stats[ $code ]['built_in']; ?></td>
					<td><?php echo (int) $stats[ $code ]['uploaded']; ?></td>
					<td><?php if ( $stats[ $code ]['uploaded'] ) : ?><button type="submit" form="pct-delete-bundle-<?php echo esc_attr( $code ); ?>" class="button-link-delete"><?php esc_html_e( 'Remove upload', 'personalized-city-tour' ); ?></button><?php endif; ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php endif; ?>

		<?php if ( 'maps' === $tab ) : ?>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'Map display', 'personalized-city-tour' ); ?></th>
				<td>
					<label><input type="radio" name="pct_settings[map_provider]" value="osm" <?php checked( $settings['map_provider'], 'osm' ); ?> /> <?php esc_html_e( 'OpenStreetMap tiles (Leaflet, no key required)', 'personalized-city-tour' ); ?></label><br>
					<label><input type="radio" name="pct_settings[map_provider]" value="mapbox" <?php checked( $settings['map_provider'], 'mapbox' ); ?> /> <?php esc_html_e( 'Mapbox tiles (token required)', 'personalized-city-tour' ); ?></label><br>
					<label><input type="radio" name="pct_settings[map_provider]" value="google" <?php checked( $settings['map_provider'], 'google' ); ?> /> <?php esc_html_e( 'Google Maps tiles (key required)', 'personalized-city-tour' ); ?></label>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Route calculation', 'personalized-city-tour' ); ?></th>
				<td>
					<label><input type="radio" name="pct_settings[routing_provider]" value="osrm" <?php checked( $settings['routing_provider'], 'osrm' ); ?> /> <?php esc_html_e( 'OSRM (open source, walking / cycling / driving)', 'personalized-city-tour' ); ?></label><br>
					<label><input type="radio" name="pct_settings[routing_provider]" value="mapbox" <?php checked( $settings['routing_provider'], 'mapbox' ); ?> /> <?php esc_html_e( 'Mapbox Directions', 'personalized-city-tour' ); ?></label><br>
					<label><input type="radio" name="pct_settings[routing_provider]" value="google" <?php checked( $settings['routing_provider'], 'google' ); ?> /> <?php esc_html_e( 'Google Directions (supports transit)', 'personalized-city-tour' ); ?></label><br>
					<label><input type="radio" name="pct_settings[routing_provider]" value="straight" <?php checked( $settings['routing_provider'], 'straight' ); ?> /> <?php esc_html_e( 'Straight lines only (no external routing service)', 'personalized-city-tour' ); ?></label>
					<p class="description"><?php esc_html_e( 'Routes are computed server-side through the plugin’s REST proxy, so Google / Mapbox routing keys are never sent to the browser. Keys are only exposed when the same provider is used for tile display.', 'personalized-city-tour' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="pct_osrm_endpoint"><?php esc_html_e( 'OSRM endpoint', 'personalized-city-tour' ); ?></label></th>
				<td><input type="url" id="pct_osrm_endpoint" name="pct_settings[osrm_endpoint]" value="<?php echo esc_attr( $settings['osrm_endpoint'] ); ?>" class="large-text" />
				<p class="description"><?php esc_html_e( 'The public demo server is fine for testing; use your own OSRM instance in production.', 'personalized-city-tour' ); ?></p></td>
			</tr>
			<tr>
				<th scope="row"><label for="pct_google_maps_api_key"><?php esc_html_e( 'Google Maps API key', 'personalized-city-tour' ); ?></label></th>
				<td><?php $secret_field( 'google_maps_api_key', 'pct_google_maps_api_key', __( 'Restrict the key to your domain when using it for tile display.', 'personalized-city-tour' ) ); ?></td>
			</tr>
			<tr>
				<th scope="row"><label for="pct_mapbox_token"><?php esc_html_e( 'Mapbox access token', 'personalized-city-tour' ); ?></label></th>
				<td><?php $secret_field( 'mapbox_token', 'pct_mapbox_token' ); ?></td>
			</tr>
			<tr>
				<th scope="row"><label for="pct_tile_url"><?php esc_html_e( 'Tile URL template', 'personalized-city-tour' ); ?></label></th>
				<td><input type="text" id="pct_tile_url" name="pct_settings[tile_url]" value="<?php echo esc_attr( $settings['tile_url'] ); ?>" class="large-text" /></td>
			</tr>
			<tr>
				<th scope="row"><label for="pct_tile_attribution"><?php esc_html_e( 'Tile attribution', 'personalized-city-tour' ); ?></label></th>
				<td><input type="text" id="pct_tile_attribution" name="pct_settings[tile_attribution]" value="<?php echo esc_attr( $settings['tile_attribution'] ); ?>" class="large-text" /></td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Google My Maps', 'personalized-city-tour' ); ?></th>
				<td>
					<label><input type="checkbox" name="pct_settings[show_mymaps_public]" value="1" <?php checked( $settings['show_mymaps_public'] ); ?> /> <?php esc_html_e( 'Show curated Google My Maps links to visitors', 'personalized-city-tour' ); ?></label>
					<p class="description"><?php esc_html_e( 'Off by default: My Maps links stay internal (city editor) and are used only as reference material. Stops link to their stored Google Maps location instead.', 'personalized-city-tour' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="pct_search_page_url"><?php esc_html_e( 'Quick search page URL', 'personalized-city-tour' ); ?></label></th>
				<td><input type="url" id="pct_search_page_url" name="pct_settings[search_page_url]" value="<?php echo esc_attr( $settings['search_page_url'] ); ?>" class="large-text" placeholder="<?php echo esc_attr( home_url( '/' ) ); ?>" />
				<p class="description"><?php esc_html_e( 'Quick search hints in tour output link to this page with ?s=… (defaults to the site search). City Channels are listed next to the hints.', 'personalized-city-tour' ); ?></p></td>
			</tr>
		</table>
		<?php endif; ?>

		<?php if ( 'drive' === $tab ) : ?>
		<p class="description"><?php esc_html_e( 'Optional backups of city data, submitted forms, generated tours and visitor session summaries to a Google Drive folder. Create an OAuth 2.0 “Web application” client in Google Cloud Console and add the redirect URI below. Nothing is stored in the plugin files — credentials live encrypted in the database or in wp-config.php constants.', 'personalized-city-tour' ); ?></p>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'Status', 'personalized-city-tour' ); ?></th>
				<td>
					<label><input type="checkbox" name="pct_settings[drive_enabled]" value="1" <?php checked( $settings['drive_enabled'] ); ?> /> <?php esc_html_e( 'Enable the Google Drive connector', 'personalized-city-tour' ); ?></label><br>
					<label><input type="checkbox" name="pct_settings[drive_sandbox]" value="1" <?php checked( $settings['drive_sandbox'] ); ?> /> <?php esc_html_e( 'Sandbox: write exports to the uploads folder instead of Drive (for testing without credentials)', 'personalized-city-tour' ); ?></label>
					<p>
						<?php if ( PCT_Drive::connected() ) : ?>
							<span class="pct-status pct-status-approved"><?php esc_html_e( 'Connected', 'personalized-city-tour' ); ?></span>
							<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=pct_drive_disconnect' ), 'pct_drive_disconnect' ) ); ?>"><?php esc_html_e( 'Disconnect', 'personalized-city-tour' ); ?></a>
						<?php elseif ( PCT_Drive::configured() ) : ?>
							<a class="button button-primary" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=pct_drive_connect' ), 'pct_drive_connect' ) ); ?>"><?php esc_html_e( 'Connect Google Drive', 'personalized-city-tour' ); ?></a>
						<?php else : ?>
							<span class="description"><?php esc_html_e( 'Save a client ID and secret first.', 'personalized-city-tour' ); ?></span>
						<?php endif; ?>
						<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=pct_drive_run' ), 'pct_drive_run' ) ); ?>"><?php esc_html_e( 'Run exports now', 'personalized-city-tour' ); ?></a>
						<button type="button" class="button pct-diagnose" data-target="drive" data-result="pct-diag-drive"><?php esc_html_e( 'Diagnostic', 'personalized-city-tour' ); ?></button>
					</p>
					<div id="pct-diag-drive" class="pct-diag" hidden></div>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="pct_drive_client_id"><?php esc_html_e( 'OAuth client ID', 'personalized-city-tour' ); ?></label></th>
				<td><input type="text" id="pct_drive_client_id" name="pct_settings[drive_client_id]" value="<?php echo esc_attr( $settings['drive_client_id'] ); ?>" class="large-text" />
				<p class="description"><?php echo esc_html( sprintf( /* translators: %s: URL */ __( 'Authorised redirect URI: %s', 'personalized-city-tour' ), PCT_Drive::redirect_uri() ) ); ?></p></td>
			</tr>
			<tr>
				<th scope="row"><label for="pct_drive_client_secret"><?php esc_html_e( 'OAuth client secret', 'personalized-city-tour' ); ?></label></th>
				<td><?php $secret_field( 'drive_client_secret', 'pct_drive_client_secret' ); ?></td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'What to export', 'personalized-city-tour' ); ?></th>
				<td>
					<?php foreach ( PCT_Drive::export_types() as $type => $label ) : ?>
						<label><input type="checkbox" name="pct_settings[drive_exports][]" value="<?php echo esc_attr( $type ); ?>" <?php checked( in_array( $type, (array) $settings['drive_exports'], true ) ); ?> /> <?php echo esc_html( $label ); ?></label>
						<input type="text" name="pct_settings[drive_folders][<?php echo esc_attr( $type ); ?>]" value="<?php echo esc_attr( $settings['drive_folders'][ $type ] ?? '' ); ?>" class="regular-text" placeholder="<?php esc_attr_e( 'Drive folder ID (optional)', 'personalized-city-tour' ); ?>" /><br>
					<?php endforeach; ?>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="pct_drive_schedule"><?php esc_html_e( 'Schedule', 'personalized-city-tour' ); ?></label></th>
				<td>
					<select id="pct_drive_schedule" name="pct_settings[drive_schedule]">
						<?php foreach ( PCT_Drive::schedules() as $key => $label ) : ?>
							<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $settings['drive_schedule'], $key ); ?>><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
					<label><?php esc_html_e( 'Keep files for (days)', 'personalized-city-tour' ); ?> <input type="number" name="pct_settings[drive_retention_days]" value="<?php echo esc_attr( $settings['drive_retention_days'] ); ?>" min="0" max="3650" class="small-text" /></label>
					<span class="description"><?php esc_html_e( '0 = never delete', 'personalized-city-tour' ); ?></span>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Automatic refresh on save', 'personalized-city-tour' ); ?></th>
				<td>
					<?php $pct_sync = PCT_Sync::status(); ?>
					<span class="pct-status pct-status-<?php echo esc_attr( 'failed' === $pct_sync['state'] ? 'rejected' : ( 'pending' === $pct_sync['state'] ? 'pending' : 'approved' ) ); ?>" id="pct-sync-state"><?php echo esc_html( $pct_sync['state'] ); ?></span>
					<span id="pct-sync-message"><?php echo esc_html( PCT_Sync::describe( $pct_sync ) ); ?></span>
					<p>
						<button type="button" class="button" id="pct-sync-run"><?php esc_html_e( 'Refresh now', 'personalized-city-tour' ); ?></button>
						<span class="description"><?php esc_html_e( 'Every save of a city, place or event re-writes the city-database export (uploads folder, plus Google Drive when connected) about a minute later via WP-Cron, with three retries. This is the data the AI is pointed to when access links are enabled.', 'personalized-city-tour' ); ?></span>
					</p>
				</td>
			</tr>
		</table>
		<?php $drive_log = PCT_Drive::log(); ?>
		<?php if ( $drive_log ) : ?>
			<h2><?php esc_html_e( 'Recent exports', 'personalized-city-tour' ); ?></h2>
			<table class="widefat striped">
				<thead><tr><th><?php esc_html_e( 'Time', 'personalized-city-tour' ); ?></th><th><?php esc_html_e( 'Type', 'personalized-city-tour' ); ?></th><th><?php esc_html_e( 'File', 'personalized-city-tour' ); ?></th><th><?php esc_html_e( 'Result', 'personalized-city-tour' ); ?></th></tr></thead>
				<tbody>
				<?php foreach ( array_slice( $drive_log, 0, 20 ) as $row ) : ?>
					<tr><td><?php echo esc_html( $row['time'] ?? '' ); ?></td><td><?php echo esc_html( $row['type'] ?? '' ); ?></td><td><?php echo esc_html( $row['file'] ?? '' ); ?></td><td><?php echo esc_html( $row['status'] ?? '' ); ?><?php echo ! empty( $row['detail'] ) ? ' <small>' . esc_html( $row['detail'] ) . '</small>' : ''; ?></td></tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
		<?php endif; ?>

		<?php if ( 'sms' === $tab ) : ?>
		<h2><?php esc_html_e( 'SMS / notifications', 'personalized-city-tour' ); ?></h2>
		<p class="description"><?php esc_html_e( 'Used for guide verification messages, visitor OTP codes and “My Trip, My Vibe” deliveries. With “Manual” the messages are queued in an outbox for you to send from your own phone.', 'personalized-city-tour' ); ?></p>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="pct_sms_provider"><?php esc_html_e( 'Delivery method', 'personalized-city-tour' ); ?></label></th>
				<td>
					<select id="pct_sms_provider" name="pct_settings[sms_provider]">
						<?php foreach ( PCT_SMS::providers() as $key => $label ) : ?>
							<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $settings['sms_provider'], $key ); ?>><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="pct_sms_endpoint"><?php esc_html_e( 'Webhook endpoint', 'personalized-city-tour' ); ?></label></th>
				<td><input type="url" id="pct_sms_endpoint" name="pct_settings[sms_endpoint]" value="<?php echo esc_attr( $settings['sms_endpoint'] ); ?>" class="large-text" />
				<p class="description"><?php esc_html_e( 'POST JSON {to, message, sender} with a Bearer token — works with most SMS gateways or an automation service.', 'personalized-city-tour' ); ?></p></td>
			</tr>
			<tr>
				<th scope="row"><label for="pct_sms_api_key"><?php esc_html_e( 'Webhook token / API key', 'personalized-city-tour' ); ?></label></th>
				<td><?php $secret_field( 'sms_api_key', 'pct_sms_api_key' ); ?></td>
			</tr>
			<tr>
				<th scope="row"><label for="pct_sms_sender"><?php esc_html_e( 'Sender name / number', 'personalized-city-tour' ); ?></label></th>
				<td><input type="text" id="pct_sms_sender" name="pct_settings[sms_sender]" value="<?php echo esc_attr( $settings['sms_sender'] ); ?>" class="regular-text" /></td>
			</tr>
			<tr>
				<th scope="row"><label for="pct_sms_country_code"><?php esc_html_e( 'Default country code', 'personalized-city-tour' ); ?></label></th>
				<td><input type="text" id="pct_sms_country_code" name="pct_settings[sms_country_code]" value="<?php echo esc_attr( $settings['sms_country_code'] ); ?>" class="small-text" dir="ltr" inputmode="numeric" />
				<p class="description"><?php esc_html_e( 'Local numbers such as 0912… are stored as +98912… so the same guide is recognised however the number is typed.', 'personalized-city-tour' ); ?></p></td>
			</tr>
			<tr>
				<th scope="row"><label for="pct_admin_mobile"><?php esc_html_e( 'Administrator mobile', 'personalized-city-tour' ); ?></label></th>
				<td><input type="text" id="pct_admin_mobile" name="pct_settings[admin_mobile]" value="<?php echo esc_attr( $settings['admin_mobile'] ); ?>" class="regular-text" dir="ltr" />
				<p class="description"><?php esc_html_e( 'Receives copies of Vibe deliveries and new-guide alerts.', 'personalized-city-tour' ); ?></p></td>
			</tr>
		</table>
		<h2><?php esc_html_e( 'Image generation (My Trip, My Vibe)', 'personalized-city-tour' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="pct_image_provider"><?php esc_html_e( 'Provider', 'personalized-city-tour' ); ?></label></th>
				<td>
					<select id="pct_image_provider" name="pct_settings[image_provider]">
						<option value="mock" <?php selected( $settings['image_provider'], 'mock' ); ?>><?php esc_html_e( 'Sandbox (generated SVG placeholder)', 'personalized-city-tour' ); ?></option>
						<option value="openai" <?php selected( $settings['image_provider'], 'openai' ); ?>><?php esc_html_e( 'OpenAI Images (gpt-image-1 / DALL·E)', 'personalized-city-tour' ); ?></option>
						<option value="custom" <?php selected( $settings['image_provider'], 'custom' ); ?>><?php esc_html_e( 'Custom OpenAI-compatible images endpoint', 'personalized-city-tour' ); ?></option>
					</select>
					<input type="text" name="pct_settings[image_model]" value="<?php echo esc_attr( $settings['image_model'] ); ?>" class="regular-text" placeholder="gpt-image-1" />
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="pct_image_endpoint"><?php esc_html_e( 'Endpoint', 'personalized-city-tour' ); ?></label></th>
				<td><input type="url" id="pct_image_endpoint" name="pct_settings[image_endpoint]" value="<?php echo esc_attr( $settings['image_endpoint'] ); ?>" class="large-text" /></td>
			</tr>
			<tr>
				<th scope="row"><label for="pct_image_api_key"><?php esc_html_e( 'Image API key', 'personalized-city-tour' ); ?></label></th>
				<td><?php $secret_field( 'image_api_key', 'pct_image_api_key', __( 'Leave empty to reuse the AI provider key.', 'personalized-city-tour' ) ); ?></td>
			</tr>
			<tr>
				<th scope="row"></th>
				<td>
					<button type="button" class="button pct-diagnose" data-target="image" data-result="pct-diag-image"><?php esc_html_e( 'Diagnostic', 'personalized-city-tour' ); ?></button>
					<span class="description"><?php esc_html_e( 'Checks configuration and authenticates against the provider without generating an image.', 'personalized-city-tour' ); ?></span>
					<div id="pct-diag-image" class="pct-diag" hidden></div>
				</td>
			</tr>
		</table>
		<?php endif; ?>

		<?php if ( 'about' === $tab ) : ?>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="pct_author_name"><?php esc_html_e( 'Author', 'personalized-city-tour' ); ?></label></th>
				<td><input type="text" id="pct_author_name" name="pct_settings[author_name]" value="<?php echo esc_attr( $settings['author_name'] ); ?>" class="regular-text" />
				<input type="url" name="pct_settings[author_url]" value="<?php echo esc_attr( $settings['author_url'] ); ?>" class="regular-text" /></td>
			</tr>
			<tr>
				<th scope="row"><label for="pct_credits"><?php esc_html_e( 'Credits', 'personalized-city-tour' ); ?></label></th>
				<td><input type="text" id="pct_credits" name="pct_settings[credits]" value="<?php echo esc_attr( $settings['credits'] ); ?>" class="regular-text" />
				<p class="description"><?php echo esc_html( sprintf( /* translators: 1: version, 2: author, 3: credits */ __( 'Personalized City Tour %1$s — built by %2$s · %3$s', 'personalized-city-tour' ), PCT_VERSION, PCT_AUTHOR, PCT_CREDITS ) ); ?></p></td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Frontend', 'personalized-city-tour' ); ?></th>
				<td><label><input type="checkbox" name="pct_settings[show_credits_frontend]" value="1" <?php checked( $settings['show_credits_frontend'] ); ?> /> <?php esc_html_e( 'Show a one-line credit under the traveller app (no logos or watermarks)', 'personalized-city-tour' ); ?></label></td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Ownership metadata', 'personalized-city-tour' ); ?></th>
				<td><p class="description"><?php esc_html_e( 'Author and credits are embedded in the plugin header, the REST API root (/pct/v1), generated tour documents and analytics exports.', 'personalized-city-tour' ); ?></p></td>
			</tr>
		</table>
		<?php endif; ?>

		<?php submit_button(); ?>
	</form>

	<?php if ( 'languages' === $tab ) : ?>
		<div class="pct-panel">
			<h2><?php esc_html_e( 'Upload a bundle', 'personalized-city-tour' ); ?></h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data" class="pct-inline-form">
				<?php wp_nonce_field( 'pct_upload_bundle' ); ?>
				<input type="hidden" name="action" value="pct_upload_bundle" />
				<select name="lang">
					<?php foreach ( $langs as $code => $meta ) : if ( 'en' === $code ) { continue; } ?>
						<option value="<?php echo esc_attr( $code ); ?>"><?php echo esc_html( $meta['label'] ); ?></option>
					<?php endforeach; ?>
				</select>
				<input type="file" name="bundle" accept=".json,.po" required />
				<button type="submit" class="button"><?php esc_html_e( 'Upload', 'personalized-city-tour' ); ?></button>
			</form>
			<p class="description"><?php esc_html_e( 'JSON format: {"English source string": "Translation", …}. Download the built-in Persian bundle from /languages/pct-fa.json as a template.', 'personalized-city-tour' ); ?></p>
		</div>
		<?php foreach ( $langs as $code => $meta ) : if ( empty( $stats[ $code ]['uploaded'] ) ) { continue; } ?>
			<form id="pct-delete-bundle-<?php echo esc_attr( $code ); ?>" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'pct_delete_bundle' ); ?>
				<input type="hidden" name="action" value="pct_delete_bundle" />
				<input type="hidden" name="lang" value="<?php echo esc_attr( $code ); ?>" />
			</form>
		<?php endforeach; ?>
	<?php endif; ?>
</div>
