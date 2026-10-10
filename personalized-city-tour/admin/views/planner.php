<?php
/**
 * Rule Based Planner view. Variables: $planner, $fields, $msg.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
$operators = PCT_Planner::operators();
$actions   = PCT_Planner::actions();
$targets   = PCT_Planner::targets();
$blank     = array( 'id' => '', 'name' => '', 'enabled' => 1, 'priority' => 10, 'when' => array(), 'then' => array(), 'window' => array( 'start' => '', 'end' => '' ), 'note' => '' );
$rules     = array();
foreach ( (array) $planner['rules'] as $rule ) {
	$rule           = wp_parse_args( (array) $rule, $blank );
	$rule['window'] = wp_parse_args( (array) $rule['window'], $blank['window'] );
	$rule['when']   = (array) $rule['when'];
	$rule['then']   = (array) $rule['then'];
	$rules[]        = $rule;
}
$rules[]   = $blank;
$w         = wp_parse_args( (array) $planner['weights'], PCT_Planner::defaults()['weights'] );
$c         = wp_parse_args( (array) $planner['constraints'], PCT_Planner::defaults()['constraints'] );
?>
<div class="wrap pct-wrap">
	<h1><?php esc_html_e( 'Rule Based Planner', 'personalized-city-tour' ); ?></h1>
	<?php if ( 'saved' === $msg ) : ?>
		<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Planner saved.', 'personalized-city-tour' ); ?></p></div>
	<?php endif; ?>
	<p class="description"><?php esc_html_e( 'The planner ranks city database entries for every traveller: weights and constraints apply always; if/then rules fire when the submitted form matches. Rules are applied server-side before the AI prompt is built (as candidate filtering and written instructions) and again to the AI answer, and they drive the built-in fallback when no AI is configured.', 'personalized-city-tour' ); ?></p>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="pct-planner">
		<?php wp_nonce_field( 'pct_save_planner' ); ?>
		<input type="hidden" name="action" value="pct_save_planner" />

		<h2><?php esc_html_e( 'Weights', 'personalized-city-tour' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="pct-w-distance"><?php esc_html_e( 'Distance penalty', 'personalized-city-tour' ); ?></label></th>
				<td><input type="number" id="pct-w-distance" name="planner[weights][distance_penalty]" value="<?php echo esc_attr( $w['distance_penalty'] ); ?>" step="0.1" min="0" max="100" class="small-text" /> <span class="description"><?php esc_html_e( 'points subtracted per kilometre from the previous stop', 'personalized-city-tour' ); ?></span></td>
			</tr>
			<tr>
				<th scope="row"><label for="pct-w-variety"><?php esc_html_e( 'Variety penalty', 'personalized-city-tour' ); ?></label></th>
				<td><input type="number" id="pct-w-variety" name="planner[weights][variety_penalty]" value="<?php echo esc_attr( $w['variety_penalty'] ); ?>" step="0.1" min="0" max="100" class="small-text" /> <span class="description"><?php esc_html_e( 'points subtracted when the category repeats within a day', 'personalized-city-tour' ); ?></span></td>
			</tr>
			<tr>
				<th scope="row"><label for="pct-w-event"><?php esc_html_e( 'Event bonus', 'personalized-city-tour' ); ?></label></th>
				<td><input type="number" id="pct-w-event" name="planner[weights][event_bonus]" value="<?php echo esc_attr( $w['event_bonus'] ); ?>" step="0.1" min="0" max="100" class="small-text" /> <span class="description"><?php esc_html_e( 'bonus for events happening during the stay', 'personalized-city-tour' ); ?></span></td>
			</tr>
			<tr>
				<th scope="row"><label for="pct-w-maxkm"><?php esc_html_e( 'Max distance between stops (km)', 'personalized-city-tour' ); ?></label></th>
				<td><input type="number" id="pct-w-maxkm" name="planner[weights][max_distance_km]" value="<?php echo esc_attr( $w['max_distance_km'] ); ?>" step="0.5" min="0" max="100" class="small-text" /></td>
			</tr>
			<tr><th scope="row"><?php esc_html_e( 'Interest focus multiplier', 'personalized-city-tour' ); ?></th><td><input type="number" name="planner[weights][interest_focus_weight]" value="<?php echo esc_attr( $w['interest_focus_weight'] ); ?>" step="0.05" min="0" max="3" class="small-text" /> <span class="description"><?php esc_html_e( 'Strength of the rank-1 interest; secondary interests remain available.', 'personalized-city-tour' ); ?></span></td></tr>
			<tr><th scope="row"><?php esc_html_e( 'Secondary interest multiplier', 'personalized-city-tour' ); ?></th><td><input type="number" name="planner[weights][secondary_interest_weight]" value="<?php echo esc_attr( $w['secondary_interest_weight'] ); ?>" step="0.05" min="0" max="3" class="small-text" /> <span class="description"><?php esc_html_e( 'Keeps supporting interests alive instead of treating the focus as an exclusion filter.', 'personalized-city-tour' ); ?></span></td></tr>
			<tr><th scope="row"><?php esc_html_e( 'Event relevance bonus', 'personalized-city-tour' ); ?></th><td><input type="number" name="planner[weights][event_relevance_bonus]" value="<?php echo esc_attr( $w['event_relevance_bonus'] ); ?>" step="0.1" min="0" max="100" class="small-text" /> <span class="description"><?php esc_html_e( 'Extra score for a current date/time-compatible event that matches the traveller.', 'personalized-city-tour' ); ?></span></td></tr>
			<tr><th scope="row"><?php esc_html_e( 'My Maps bonus', 'personalized-city-tour' ); ?></th><td><input type="number" name="planner[weights][mymap_bonus]" value="<?php echo esc_attr( $w['mymap_bonus'] ); ?>" step="0.1" min="0" max="100" class="small-text" /></td></tr>
			<tr><th scope="row"><?php esc_html_e( 'Channel freshness bonus', 'personalized-city-tour' ); ?></th><td><input type="number" name="planner[weights][channel_freshness_bonus]" value="<?php echo esc_attr( $w['channel_freshness_bonus'] ); ?>" step="0.1" min="0" max="100" class="small-text" /></td></tr>
			<tr><th scope="row"><?php esc_html_e( 'Source diversity bonus', 'personalized-city-tour' ); ?></th><td><input type="number" name="planner[weights][source_diversity_bonus]" value="<?php echo esc_attr( $w['source_diversity_bonus'] ); ?>" step="0.1" min="0" max="100" class="small-text" /></td></tr>
			<tr><th scope="row"><?php esc_html_e( 'Verified location bonus', 'personalized-city-tour' ); ?></th><td><input type="number" name="planner[weights][location_verified_bonus]" value="<?php echo esc_attr( $w['location_verified_bonus'] ); ?>" step="0.1" min="0" max="100" class="small-text" /></td></tr>
			<tr><th scope="row"><?php esc_html_e( 'Time-fit bonus', 'personalized-city-tour' ); ?></th><td><input type="number" name="planner[weights][time_fit_bonus]" value="<?php echo esc_attr( $w['time_fit_bonus'] ); ?>" step="0.1" min="0" max="100" class="small-text" /></td></tr>
			<tr><th scope="row"><?php esc_html_e( 'Route compactness penalty', 'personalized-city-tour' ); ?></th><td><input type="number" name="planner[weights][route_compactness_penalty]" value="<?php echo esc_attr( $w['route_compactness_penalty'] ); ?>" step="0.1" min="0" max="100" class="small-text" /> <span class="description"><?php esc_html_e( 'Controls how strongly the fallback planner avoids long geographic jumps.', 'personalized-city-tour' ); ?></span></td></tr>
		</table>

		<h2><?php esc_html_e( 'Tour generation strategy', 'personalized-city-tour' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr><th scope="row"><?php esc_html_e( 'Rotate ranked interests', 'personalized-city-tour' ); ?></th><td><label><input type="checkbox" name="planner[strategy][interest_rotation]" value="1" <?php checked( ! empty( $planner['strategy']['interest_rotation'] ) ); ?> /> <?php esc_html_e( 'Cycle the traveller’s ordered interests across proposals and days (1→2→3→4→1…), while keeping the focus as a soft priority rather than a hard filter.', 'personalized-city-tour' ); ?></label></td></tr>
			<tr><th scope="row"><?php esc_html_e( 'Use Events when available', 'personalized-city-tour' ); ?></th><td><label><input type="checkbox" name="planner[strategy][require_event]" value="1" <?php checked( ! empty( $planner['strategy']['require_event'] ) ); ?> /> <?php esc_html_e( 'Require a compatible date/time-matched event in each proposal when one exists.', 'personalized-city-tour' ); ?></label></td></tr>
			<tr><th scope="row"><?php esc_html_e( 'Use Google My Maps when available', 'personalized-city-tour' ); ?></th><td><label><input type="checkbox" name="planner[strategy][require_mymap]" value="1" <?php checked( ! empty( $planner['strategy']['require_mymap'] ) ); ?> /> <?php esc_html_e( 'Require a compatible curated My Maps point or route in each proposal when one exists.', 'personalized-city-tour' ); ?></label></td></tr>
			<tr><th scope="row"><?php esc_html_e( 'Maximum stops per proposal', 'personalized-city-tour' ); ?></th><td><input type="number" name="planner[strategy][max_stops_per_proposal]" value="<?php echo esc_attr( $planner['strategy']['max_stops_per_proposal'] ?? 18 ); ?>" min="3" max="18" class="small-text" /> <span class="description"><?php esc_html_e( 'Sets the upper ceiling per proposal. Up to 18 stops are allowed, but the AI should use fewer when pace, time, opening hours or route quality make that wiser.', 'personalized-city-tour' ); ?></span></td></tr>
		</table>

		<h2><?php esc_html_e( 'Constraints', 'personalized-city-tour' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'Day time window', 'personalized-city-tour' ); ?></th>
				<td><input type="time" name="planner[constraints][day_start]" value="<?php echo esc_attr( $c['day_start'] ); ?>" /> – <input type="time" name="planner[constraints][day_end]" value="<?php echo esc_attr( $c['day_end'] ); ?>" /></td>
			</tr>
			<tr>
				<th scope="row"><label for="pct-c-price"><?php esc_html_e( 'Maximum price tier', 'personalized-city-tour' ); ?></label></th>
				<td>
					<select id="pct-c-price" name="planner[constraints][max_price_tier]">
						<option value=""><?php esc_html_e( 'Follow the traveller’s budget', 'personalized-city-tour' ); ?></option>
						<?php foreach ( PCT_Data::price_tiers() as $tier => $label ) : ?>
							<option value="<?php echo esc_attr( $tier ); ?>" <?php selected( $c['max_price_tier'], $tier ); ?>><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Accessibility', 'personalized-city-tour' ); ?></th>
				<td><label><input type="checkbox" name="planner[constraints][require_accessible]" value="1" <?php checked( $c['require_accessible'] ); ?> /> <?php esc_html_e( 'Only wheelchair-accessible entries for travellers who report a mobility limitation', 'personalized-city-tour' ); ?></label></td>
			</tr>
		</table>

		<h2><?php esc_html_e( 'If / then rules', 'personalized-city-tour' ); ?></h2>
		<p class="description"><?php esc_html_e( 'Lower priority numbers run first. IF conditions refer to travel-form field keys. THEN actions target candidate attributes such as category, event, location status and City Channel source. Use rules as soft policy controls, not as a replacement for traveller preferences.', 'personalized-city-tour' ); ?></p>
		<div id="pct-rules">
		<?php foreach ( $rules as $i => $rule ) : ?>
			<fieldset class="pct-rule pct-panel" data-index="<?php echo (int) $i; ?>">
				<input type="hidden" name="planner[rules][<?php echo (int) $i; ?>][id]" value="<?php echo esc_attr( $rule['id'] ); ?>" />
				<div class="pct-rule-head">
					<label><input type="checkbox" name="planner[rules][<?php echo (int) $i; ?>][enabled]" value="1" <?php checked( $rule['enabled'] ); ?> /> <?php esc_html_e( 'Enabled', 'personalized-city-tour' ); ?></label>
					<input type="text" name="planner[rules][<?php echo (int) $i; ?>][name]" value="<?php echo esc_attr( $rule['name'] ); ?>" class="regular-text" placeholder="<?php esc_attr_e( 'Rule name (empty = ignored)', 'personalized-city-tour' ); ?>" />
					<label><?php esc_html_e( 'Priority', 'personalized-city-tour' ); ?> <input type="number" name="planner[rules][<?php echo (int) $i; ?>][priority]" value="<?php echo esc_attr( $rule['priority'] ); ?>" min="1" max="999" class="small-text" /></label>
					<label><?php esc_html_e( 'Time window', 'personalized-city-tour' ); ?> <input type="time" name="planner[rules][<?php echo (int) $i; ?>][window][start]" value="<?php echo esc_attr( $rule['window']['start'] ); ?>" /> – <input type="time" name="planner[rules][<?php echo (int) $i; ?>][window][end]" value="<?php echo esc_attr( $rule['window']['end'] ); ?>" /></label>
					<button type="button" class="button-link-delete pct-rule-remove"><?php esc_html_e( 'Remove rule', 'personalized-city-tour' ); ?></button>
				</div>
				<div class="pct-rule-cols">
					<div>
						<strong><?php esc_html_e( 'IF (all conditions match)', 'personalized-city-tour' ); ?></strong>
						<table class="pct-repeater" id="pct-when-<?php echo (int) $i; ?>">
							<tbody>
							<?php $whens = $rule['when']; $whens[] = array( 'field' => '', 'op' => 'equals', 'value' => '' ); ?>
							<?php foreach ( $whens as $j => $cond ) : ?>
								<tr>
									<td>
										<select name="planner[rules][<?php echo (int) $i; ?>][when][<?php echo (int) $j; ?>][field]">
											<option value=""><?php esc_html_e( '— form field —', 'personalized-city-tour' ); ?></option>
											<?php foreach ( $fields as $fk => $fd ) : ?>
												<option value="<?php echo esc_attr( $fk ); ?>" <?php selected( $cond['field'], $fk ); ?>><?php echo esc_html( $fd['label'] . ' (' . $fk . ')' ); ?></option>
											<?php endforeach; ?>
										</select>
									</td>
									<td>
										<select name="planner[rules][<?php echo (int) $i; ?>][when][<?php echo (int) $j; ?>][op]">
											<?php foreach ( $operators as $ok => $ol ) : ?>
												<option value="<?php echo esc_attr( $ok ); ?>" <?php selected( $cond['op'], $ok ); ?>><?php echo esc_html( $ol ); ?></option>
											<?php endforeach; ?>
										</select>
									</td>
									<td><input type="text" name="planner[rules][<?php echo (int) $i; ?>][when][<?php echo (int) $j; ?>][value]" value="<?php echo esc_attr( $cond['value'] ); ?>" placeholder="<?php esc_attr_e( 'value', 'personalized-city-tour' ); ?>" /></td>
									<td><button type="button" class="button-link-delete pct-repeater-remove" aria-label="<?php esc_attr_e( 'Remove', 'personalized-city-tour' ); ?>">&times;</button></td>
								</tr>
							<?php endforeach; ?>
							</tbody>
						</table>
						<button type="button" class="button-link pct-repeater-add" data-target="pct-when-<?php echo (int) $i; ?>"><?php esc_html_e( '+ condition', 'personalized-city-tour' ); ?></button>
					</div>
					<div>
						<strong><?php esc_html_e( 'THEN', 'personalized-city-tour' ); ?></strong>
						<table class="pct-repeater" id="pct-then-<?php echo (int) $i; ?>">
							<tbody>
							<?php $thens = $rule['then']; $thens[] = array( 'action' => 'boost', 'target' => 'category', 'value' => '', 'weight' => 5 ); ?>
							<?php foreach ( $thens as $j => $act ) : ?>
								<tr>
									<td>
										<select name="planner[rules][<?php echo (int) $i; ?>][then][<?php echo (int) $j; ?>][action]">
											<?php foreach ( $actions as $ak => $al ) : ?>
												<option value="<?php echo esc_attr( $ak ); ?>" <?php selected( $act['action'], $ak ); ?>><?php echo esc_html( $al ); ?></option>
											<?php endforeach; ?>
										</select>
									</td>
									<td>
										<select name="planner[rules][<?php echo (int) $i; ?>][then][<?php echo (int) $j; ?>][target]">
											<?php foreach ( $targets as $tk => $tl ) : ?>
												<option value="<?php echo esc_attr( $tk ); ?>" <?php selected( $act['target'], $tk ); ?>><?php echo esc_html( $tl ); ?></option>
											<?php endforeach; ?>
										</select>
									</td>
									<td><input type="text" name="planner[rules][<?php echo (int) $i; ?>][then][<?php echo (int) $j; ?>][value]" value="<?php echo esc_attr( $act['value'] ); ?>" placeholder="<?php esc_attr_e( 'value', 'personalized-city-tour' ); ?>" /></td>
									<td><input type="number" name="planner[rules][<?php echo (int) $i; ?>][then][<?php echo (int) $j; ?>][weight]" value="<?php echo esc_attr( $act['weight'] ); ?>" min="0" max="100" step="0.5" class="small-text" title="<?php esc_attr_e( 'Weight', 'personalized-city-tour' ); ?>" /></td>
									<td><button type="button" class="button-link-delete pct-repeater-remove" aria-label="<?php esc_attr_e( 'Remove', 'personalized-city-tour' ); ?>">&times;</button></td>
								</tr>
							<?php endforeach; ?>
							</tbody>
						</table>
						<button type="button" class="button-link pct-repeater-add" data-target="pct-then-<?php echo (int) $i; ?>"><?php esc_html_e( '+ action', 'personalized-city-tour' ); ?></button>
					</div>
				</div>
				<textarea name="planner[rules][<?php echo (int) $i; ?>][note]" rows="2" class="large-text" placeholder="<?php esc_attr_e( 'Instruction passed to the AI when this rule fires (optional)', 'personalized-city-tour' ); ?>"><?php echo esc_textarea( $rule['note'] ); ?></textarea>
			</fieldset>
		<?php endforeach; ?>
		</div>
		<button type="button" class="button" id="pct-rule-add"><?php esc_html_e( 'Add rule', 'personalized-city-tour' ); ?></button>

		<?php submit_button( __( 'Save planner', 'personalized-city-tour' ) ); ?>
	</form>
</div>
