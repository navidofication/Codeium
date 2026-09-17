<?php
/**
 * Settings view. Variables: $settings, $providers.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
$has_ai_key  = '' !== (string) $settings['ai_api_key'];
$has_g_key   = '' !== (string) $settings['google_maps_api_key'];
$key_const   = defined( 'PCT_AI_API_KEY' ) && PCT_AI_API_KEY;
?>
<div class="wrap pct-wrap">
	<h1><?php esc_html_e( 'City Tour Settings', 'personalized-city-tour' ); ?></h1>
	<?php settings_errors(); ?>

	<form method="post" action="options.php" class="pct-settings">
		<?php settings_fields( 'pct_settings_group' ); ?>

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
					<label><input type="checkbox" name="pct_settings[guide_registration]" value="1" <?php checked( $settings['guide_registration'] ); ?> /> <?php esc_html_e( 'Allow visitors to apply as local guides from the frontend', 'personalized-city-tour' ); ?></label><br>
					<label><input type="checkbox" name="pct_settings[guide_auto_publish]" value="1" <?php checked( $settings['guide_auto_publish'] ); ?> /> <?php esc_html_e( 'Publish guide submissions immediately (otherwise they wait for review)', 'personalized-city-tour' ); ?></label><br>
					<label><input type="checkbox" name="pct_settings[notify_admin_guides]" value="1" <?php checked( $settings['notify_admin_guides'] ); ?> /> <?php esc_html_e( 'Email the site admin about new guide applications', 'personalized-city-tour' ); ?></label>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Submissions', 'personalized-city-tour' ); ?></th>
				<td>
					<label><input type="checkbox" name="pct_settings[store_submissions]" value="1" <?php checked( $settings['store_submissions'] ); ?> /> <?php esc_html_e( 'Store submitted forms and generated tours (Generated Tours screen, shareable result links)', 'personalized-city-tour' ); ?></label>
				</td>
			</tr>
		</table>

		<h2><?php esc_html_e( 'AI integration', 'personalized-city-tour' ); ?></h2>
		<p class="description"><?php esc_html_e( 'The AI analyses the submitted travel preference form and composes tour options strictly from your city database. Without a provider the built-in rule based planner is used.', 'personalized-city-tour' ); ?></p>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="pct_ai_provider"><?php esc_html_e( 'Provider', 'personalized-city-tour' ); ?></label></th>
				<td>
					<select id="pct_ai_provider" name="pct_settings[ai_provider]">
						<?php foreach ( $providers as $key => $label ) : ?>
							<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $settings['ai_provider'], $key ); ?>><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="pct_ai_api_key"><?php esc_html_e( 'API key', 'personalized-city-tour' ); ?></label></th>
				<td>
					<input type="password" id="pct_ai_api_key" name="pct_settings[ai_api_key]" value="" class="regular-text" autocomplete="new-password" placeholder="<?php echo $has_ai_key ? esc_attr__( '•••••••• (saved)', 'personalized-city-tour' ) : ''; ?>" />
					<?php if ( $has_ai_key ) : ?>
						<label class="pct-inline"><input type="checkbox" name="pct_settings[ai_api_key_clear]" value="1" /> <?php esc_html_e( 'Clear saved key', 'personalized-city-tour' ); ?></label>
					<?php endif; ?>
					<p class="description">
						<?php esc_html_e( 'Leave empty to keep the saved key.', 'personalized-city-tour' ); ?>
						<?php if ( $key_const ) : ?>
							<strong><?php esc_html_e( 'A key defined via the PCT_AI_API_KEY constant in wp-config.php is currently in use and overrides this field.', 'personalized-city-tour' ); ?></strong>
						<?php else : ?>
							<?php esc_html_e( 'Tip: define PCT_AI_API_KEY in wp-config.php to keep the key out of the database.', 'personalized-city-tour' ); ?>
						<?php endif; ?>
					</p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="pct_ai_model"><?php esc_html_e( 'Model / deployment', 'personalized-city-tour' ); ?></label></th>
				<td><input type="text" id="pct_ai_model" name="pct_settings[ai_model]" value="<?php echo esc_attr( $settings['ai_model'] ); ?>" class="regular-text" />
				<p class="description"><?php esc_html_e( 'e.g. gpt-4o-mini, gpt-4.1, claude-3-5-haiku-latest, llama-3.1-70b. For Azure the deployment is part of the endpoint.', 'personalized-city-tour' ); ?></p></td>
			</tr>
			<tr>
				<th scope="row"><label for="pct_ai_endpoint"><?php esc_html_e( 'Endpoint URL', 'personalized-city-tour' ); ?></label></th>
				<td><input type="url" id="pct_ai_endpoint" name="pct_settings[ai_endpoint]" value="<?php echo esc_attr( $settings['ai_endpoint'] ); ?>" class="large-text" />
				<p class="description"><?php esc_html_e( 'Optional for OpenAI / Anthropic. Required for Azure OpenAI (https://RESOURCE.openai.azure.com/openai/deployments/DEPLOYMENT) and custom OpenAI-compatible servers (…/v1).', 'personalized-city-tour' ); ?></p></td>
			</tr>
			<tr>
				<th scope="row"><label for="pct_ai_temperature"><?php esc_html_e( 'Creativity (temperature)', 'personalized-city-tour' ); ?></label></th>
				<td><input type="number" id="pct_ai_temperature" name="pct_settings[ai_temperature]" value="<?php echo esc_attr( $settings['ai_temperature'] ); ?>" min="0" max="2" step="0.1" class="small-text" /></td>
			</tr>
			<tr>
				<th scope="row"><label for="pct_ai_max_items"><?php esc_html_e( 'Max database items sent per request', 'personalized-city-tour' ); ?></label></th>
				<td><input type="number" id="pct_ai_max_items" name="pct_settings[ai_max_items]" value="<?php echo esc_attr( $settings['ai_max_items'] ); ?>" min="10" max="300" class="small-text" />
				<p class="description"><?php esc_html_e( 'Best matching entries are pre-selected by interest, budget and accessibility to keep prompts small.', 'personalized-city-tour' ); ?></p></td>
			</tr>
			<tr>
				<th scope="row"><label for="pct_stops_per_day"><?php esc_html_e( 'Suggested stops per day', 'personalized-city-tour' ); ?></label></th>
				<td><input type="number" id="pct_stops_per_day" name="pct_settings[stops_per_day]" value="<?php echo esc_attr( $settings['stops_per_day'] ); ?>" min="1" max="8" class="small-text" />
				<p class="description"><?php esc_html_e( 'Adjusted automatically by the traveller’s chosen pace.', 'personalized-city-tour' ); ?></p></td>
			</tr>
			<tr>
				<th scope="row"></th>
				<td><button type="button" class="button" id="pct-ai-test"><?php esc_html_e( 'Test connection (uses saved settings)', 'personalized-city-tour' ); ?></button> <span id="pct-ai-test-result"></span></td>
			</tr>
		</table>

		<h2><?php esc_html_e( 'Maps & routing', 'personalized-city-tour' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'Map display', 'personalized-city-tour' ); ?></th>
				<td>
					<label><input type="radio" name="pct_settings[map_provider]" value="osm" <?php checked( $settings['map_provider'], 'osm' ); ?> /> <?php esc_html_e( 'OpenStreetMap (Leaflet, no key required)', 'personalized-city-tour' ); ?></label><br>
					<label><input type="radio" name="pct_settings[map_provider]" value="google" <?php checked( $settings['map_provider'], 'google' ); ?> /> <?php esc_html_e( 'Google Maps JavaScript API', 'personalized-city-tour' ); ?></label>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Route calculation', 'personalized-city-tour' ); ?></th>
				<td>
					<label><input type="radio" name="pct_settings[routing_provider]" value="osrm" <?php checked( $settings['routing_provider'], 'osrm' ); ?> /> <?php esc_html_e( 'OSRM (open source, walking / cycling / driving)', 'personalized-city-tour' ); ?></label><br>
					<label><input type="radio" name="pct_settings[routing_provider]" value="google" <?php checked( $settings['routing_provider'], 'google' ); ?> /> <?php esc_html_e( 'Google Directions (supports transit)', 'personalized-city-tour' ); ?></label><br>
					<label><input type="radio" name="pct_settings[routing_provider]" value="straight" <?php checked( $settings['routing_provider'], 'straight' ); ?> /> <?php esc_html_e( 'Straight lines only (no external routing service)', 'personalized-city-tour' ); ?></label>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="pct_osrm_endpoint"><?php esc_html_e( 'OSRM endpoint', 'personalized-city-tour' ); ?></label></th>
				<td><input type="url" id="pct_osrm_endpoint" name="pct_settings[osrm_endpoint]" value="<?php echo esc_attr( $settings['osrm_endpoint'] ); ?>" class="large-text" />
				<p class="description"><?php esc_html_e( 'The public demo server is fine for testing; use your own OSRM instance in production.', 'personalized-city-tour' ); ?></p></td>
			</tr>
			<tr>
				<th scope="row"><label for="pct_google_maps_api_key"><?php esc_html_e( 'Google Maps API key', 'personalized-city-tour' ); ?></label></th>
				<td>
					<input type="password" id="pct_google_maps_api_key" name="pct_settings[google_maps_api_key]" value="" class="regular-text" autocomplete="new-password" placeholder="<?php echo $has_g_key ? esc_attr__( '•••••••• (saved)', 'personalized-city-tour' ) : ''; ?>" />
					<?php if ( $has_g_key ) : ?>
						<label class="pct-inline"><input type="checkbox" name="pct_settings[google_maps_api_key_clear]" value="1" /> <?php esc_html_e( 'Clear saved key', 'personalized-city-tour' ); ?></label>
					<?php endif; ?>
					<p class="description"><?php esc_html_e( 'Needed only for Google Maps display or Google Directions. Restrict the key to your domain (it is exposed to the browser).', 'personalized-city-tour' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="pct_tile_url"><?php esc_html_e( 'Tile URL template', 'personalized-city-tour' ); ?></label></th>
				<td><input type="text" id="pct_tile_url" name="pct_settings[tile_url]" value="<?php echo esc_attr( $settings['tile_url'] ); ?>" class="large-text" /></td>
			</tr>
			<tr>
				<th scope="row"><label for="pct_tile_attribution"><?php esc_html_e( 'Tile attribution', 'personalized-city-tour' ); ?></label></th>
				<td><input type="text" id="pct_tile_attribution" name="pct_settings[tile_attribution]" value="<?php echo esc_attr( $settings['tile_attribution'] ); ?>" class="large-text" /></td>
			</tr>
		</table>

		<?php submit_button(); ?>
	</form>
</div>
