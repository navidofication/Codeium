<?php
/**
 * "My Trip, My Vibe" admin view. Variables: $vibe, $requests, $statuses, $outbox, $msg.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
$notices = array(
	'saved'     => __( 'Settings saved.', 'personalized-city-tour' ),
	'approved'  => __( 'Request approved; image generation is scheduled.', 'personalized-city-tour' ),
	'generated' => __( 'Generation finished — check the status below.', 'personalized-city-tour' ),
	'rejected'  => __( 'Request rejected and the visitor notified.', 'personalized-city-tour' ),
	'delivered' => __( 'Marked as delivered.', 'personalized-city-tour' ),
	'deleted'   => __( 'Request deleted.', 'personalized-city-tour' ),
	'push_test_ok' => __( 'Test browser notification sent to your subscribed devices.', 'personalized-city-tour' ),
	'push_test_error' => __( 'No browser notification was sent. See the diagnostic details below and re-enable notifications on this device.', 'personalized-city-tour' ),
	'sms_done'  => __( 'Message marked as sent.', 'personalized-city-tour' ),
	'error'     => __( 'The action could not be performed.', 'personalized-city-tour' ),
);
$push_test_diag = get_transient( 'pct_push_test_last_' . get_current_user_id() );
if ( is_array( $push_test_diag ) ) {
	delete_transient( 'pct_push_test_last_' . get_current_user_id() );
}

$action_form = function ( $id, $action, $label, $class = 'button', $extra = '' ) {
	?>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="pct-inline-form" <?php echo 'delete' === $action ? 'data-confirm' : ''; ?>>
		<?php wp_nonce_field( 'pct_vibe_action' ); ?>
		<input type="hidden" name="action" value="pct_vibe_action" />
		<input type="hidden" name="request_id" value="<?php echo (int) $id; ?>" />
		<input type="hidden" name="vibe_action" value="<?php echo esc_attr( $action ); ?>" />
		<?php echo $extra; // phpcs:ignore WordPress.Security.EscapeOutput -- built from escaped fragments below. ?>
		<button type="submit" class="<?php echo esc_attr( $class ); ?>"><?php echo esc_html( $label ); ?></button>
	</form>
	<?php
};
?>
<div class="wrap pct-wrap pct-vibe-admin">
	<h1><?php esc_html_e( 'My Trip, My Vibe', 'personalized-city-tour' ); ?></h1>
	<?php if ( $msg && isset( $notices[ $msg ] ) ) : ?>
		<div class="notice <?php echo 'error' === $msg ? 'notice-error' : 'notice-success'; ?> is-dismissible"><p><?php echo esc_html( $notices[ $msg ] ); ?></p></div>
	<?php endif; ?>
	<?php if ( ! empty( $push_test_diag['errors'] ) ) : ?>
		<div class="notice notice-warning"><p><strong><?php esc_html_e( 'Web Push diagnostic:', 'personalized-city-tour' ); ?></strong> <?php echo esc_html( wp_json_encode( $push_test_diag['errors'], JSON_UNESCAPED_UNICODE ) ); ?></p></div>
	<?php endif; ?>
	<p class="description"><?php echo esc_html( ! empty( $vibe['require_approval'] ) ? __( 'Visitors verify their mobile number with a one-time code, upload a photo, pick a vibe / style / subject and request an AI illustration. Requests wait here for your approval before background generation.', 'personalized-city-tour' ) : __( 'Visitors verify their mobile number with a one-time code, upload a photo, pick a vibe / style / subject and request an AI illustration. Approval is OFF: generation starts automatically after the visitor confirms the second click.', 'personalized-city-tour' ) ); ?> <?php esc_html_e( 'Delivery can be by SMS, e-mail or manually.', 'personalized-city-tour' ); ?></p>

	<div class="pct-push-admin-box">
		<h2><?php esc_html_e( 'Browser notifications', 'personalized-city-tour' ); ?></h2>
		<p class="description"><?php esc_html_e( 'Enable notifications on this administrator device. When a visitor requests an OTP, the plugin will send the notification “درخواست تولید عکس آمده است” to subscribed administrator devices.', 'personalized-city-tour' ); ?></p>
		<label class="pct-push-permission-admin">
			<input type="checkbox" id="pct-push-permission" />
			<span><?php esc_html_e( 'Allow plugin and browser to send notifications', 'personalized-city-tour' ); ?></span>
		</label>
		<p id="pct-push-status" class="description" aria-live="polite"></p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="pct-inline-form pct-push-test-form">
			<?php wp_nonce_field( 'pct_push_test' ); ?>
			<input type="hidden" name="action" value="pct_push_test" />
			<button type="submit" id="pct-push-test" class="button" disabled><?php esc_html_e( 'Test browser notification', 'personalized-city-tour' ); ?></button>
			<span class="description"><?php esc_html_e( 'First enable the checkbox above, then use this button to send a real Web Push test from the server to this administrator device.', 'personalized-city-tour' ); ?></span>
		</form>
	</div>

	<h2><?php esc_html_e( 'Requests', 'personalized-city-tour' ); ?> <span class="pct-admin-count-badge"><?php echo (int) count( $requests ); ?></span></h2>
	<?php if ( ! $requests ) : ?>
		<p class="description"><?php esc_html_e( 'No requests yet.', 'personalized-city-tour' ); ?></p>
	<?php else : ?>
		<div class="pct-vibe-table-scroll"><table class="widefat striped pct-vibe-table">
			<thead><tr>
				<th><?php esc_html_e( 'Request', 'personalized-city-tour' ); ?></th>
				<th><?php esc_html_e( 'Visitor', 'personalized-city-tour' ); ?></th>
				<th><?php esc_html_e( 'Choices', 'personalized-city-tour' ); ?></th>
				<th><?php esc_html_e( 'Images', 'personalized-city-tour' ); ?></th>
				<th><?php esc_html_e( 'Status', 'personalized-city-tour' ); ?></th>
				<th><?php esc_html_e( 'Actions', 'personalized-city-tour' ); ?></th>
			</tr></thead>
			<tbody>
			<?php foreach ( $requests as $r ) : ?>
				<tr>
					<td><strong><?php echo esc_html( $r['title'] ); ?></strong><br><small><?php echo esc_html( $r['created'] ); ?></small></td>
					<td><?php echo esc_html( $r['name'] ); ?><br><a href="tel:<?php echo esc_attr( $r['mobile'] ); ?>" dir="ltr"><?php echo esc_html( $r['mobile'] ); ?></a><?php echo $r['email'] ? '<br><small>' . esc_html( $r['email'] ) . '</small>' : ''; ?></td>
					<td>
						<?php echo esc_html( $r['city'] ); ?><br>
						<small><?php echo esc_html( $r['vibe'] . ' · ' . $r['style'] . ' · ' . $r['subject'] ); ?></small>
						<?php if ( $r['notes'] ) : ?><br><em><?php echo esc_html( $r['notes'] ); ?></em><?php endif; ?>
						<details><summary><?php esc_html_e( 'Prompt', 'personalized-city-tour' ); ?></summary><code><?php echo esc_html( $r['prompt'] ? $r['prompt'] : PCT_Vibe::prompt( $r ) ); ?></code></details>
					</td>
					<td class="pct-vibe-images">
						<?php if ( $r['photo_url'] ) : ?><a href="<?php echo esc_url( $r['photo_url'] ); ?>" target="_blank" rel="noopener"><img src="<?php echo esc_url( $r['photo_url'] ); ?>" alt="" width="64" height="64" /></a><?php endif; ?>
						<?php if ( $r['image_url'] ) : ?><a href="<?php echo esc_url( $r['image_url'] ); ?>" target="_blank" rel="noopener"><img src="<?php echo esc_url( $r['image_url'] ); ?>" alt="" width="64" height="64" /></a><?php endif; ?>
					</td>
					<td>
						<span class="pct-status pct-status-<?php echo esc_attr( $r['status'] ); ?>"><?php echo esc_html( $statuses[ $r['status'] ] ?? $r['status'] ); ?></span>
						<?php if ( $r['error'] ) : ?><br><small class="pct-error"><?php echo esc_html( $r['error'] ); ?></small><?php endif; ?>
						<?php if ( $r['delivered'] ) : ?><br><small><?php echo esc_html( $r['delivered'] ); ?></small><?php endif; ?>
					</td>
					<td class="pct-actions">
						<?php if ( 'pending' === $r['status'] ) : ?>
							<?php if ( empty( $vibe['require_approval'] ) ) : ?>
								<?php $action_form( $r['id'], 'generate', __( 'Generate now', 'personalized-city-tour' ), 'button button-primary' ); ?>
							<?php else : ?>
								<?php $action_form( $r['id'], 'approve', __( 'Approve & generate', 'personalized-city-tour' ), 'button button-primary', '<input type="hidden" name="generate_now" value="1" />' ); ?>
								<?php $action_form( $r['id'], 'reject', __( 'Reject', 'personalized-city-tour' ), 'button', '<input type="text" name="reason" placeholder="' . esc_attr__( 'Reason (optional)', 'personalized-city-tour' ) . '" />' ); ?>
							<?php endif; ?>
						<?php elseif ( 'failed' === $r['status'] || 'approved' === $r['status'] ) : ?>
							<?php $action_form( $r['id'], 'generate', __( 'Generate now', 'personalized-city-tour' ), 'button button-primary' ); ?>
						<?php elseif ( 'done' === $r['status'] ) : ?>
							<?php $action_form( $r['id'], 'deliver', __( 'Send to visitor', 'personalized-city-tour' ), 'button button-primary' ); ?>
							<?php $action_form( $r['id'], 'mark_delivered', __( 'Mark delivered', 'personalized-city-tour' ), 'button' ); ?>
							<a class="button" href="<?php echo esc_url( PCT_Vibe::result_url( $r ) ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Result link', 'personalized-city-tour' ); ?></a>
						<?php endif; ?>
						<?php $action_form( $r['id'], 'delete', __( 'Delete', 'personalized-city-tour' ), 'button-link-delete' ); ?>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table></div>
	<?php endif; ?>

	<?php if ( $outbox ) : ?>
		<h2 id="pct-sms-outbox"><?php esc_html_e( 'SMS outbox (send from your phone)', 'personalized-city-tour' ); ?> <span class="pct-admin-count-badge"><?php echo (int) count( $outbox ); ?></span></h2>
		<div class="pct-vibe-table-scroll pct-sms-outbox-scroll"><table class="widefat striped">
			<thead><tr><th><?php esc_html_e( 'Time', 'personalized-city-tour' ); ?></th><th><?php esc_html_e( 'To', 'personalized-city-tour' ); ?></th><th><?php esc_html_e( 'Message', 'personalized-city-tour' ); ?></th><th></th></tr></thead>
			<tbody>
			<?php foreach ( $outbox as $sms ) : ?>
				<tr>
					<td><?php echo esc_html( $sms['time'] ); ?></td>
					<td><a href="sms:<?php echo esc_attr( $sms['to'] ); ?>?body=<?php echo rawurlencode( $sms['message'] ); ?>" dir="ltr"><?php echo esc_html( $sms['to'] ); ?></a></td>
					<td><?php echo esc_html( $sms['message'] ); ?></td>
					<td>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="pct-inline-form">
							<?php wp_nonce_field( 'pct_sms_done' ); ?>
							<input type="hidden" name="action" value="pct_sms_done" />
							<input type="hidden" name="sms_id" value="<?php echo esc_attr( $sms['id'] ); ?>" />
							<input type="hidden" name="back" value="pct-vibe" />
							<button type="submit" class="button-link"><?php esc_html_e( 'Sent', 'personalized-city-tour' ); ?></button>
						</form>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table></div>
	<?php endif; ?>

	<h2><?php esc_html_e( 'Settings', 'personalized-city-tour' ); ?></h2>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<?php wp_nonce_field( 'pct_vibe_settings' ); ?>
		<input type="hidden" name="action" value="pct_vibe_settings" />
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'Availability', 'personalized-city-tour' ); ?></th>
				<td>
					<label><input type="checkbox" name="enabled" value="1" <?php checked( $vibe['enabled'] ); ?> /> <?php esc_html_e( 'Offer “My Trip, My Vibe” on the frontend', 'personalized-city-tour' ); ?></label><br>
					<label><input type="checkbox" name="require_approval" value="1" <?php checked( $vibe['require_approval'] ); ?> /> <?php esc_html_e( 'Require administrator approval before generating (recommended when a fee applies)', 'personalized-city-tour' ); ?></label><br>
					<label><input type="checkbox" name="subject_visible" value="1" <?php checked( $vibe['subject_visible'] ); ?> /> <?php esc_html_e( 'Show the Subject choices on the public form', 'personalized-city-tour' ); ?></label><br>
					<label><input type="checkbox" name="sandbox" value="1" <?php checked( $vibe['sandbox'] ); ?> /> <?php esc_html_e( 'Sandbox: show OTP codes on screen and generate placeholder SVG illustrations (no image API calls)', 'personalized-city-tour' ); ?></label>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="pct-vibe-fee"><?php esc_html_e( 'Fee notice shown to visitors', 'personalized-city-tour' ); ?></label></th>
				<td><textarea id="pct-vibe-fee" name="fee_text" rows="2" class="large-text"><?php echo esc_textarea( $vibe['fee_text'] ); ?></textarea></td>
			</tr>
			<tr>
				<th scope="row"><label for="pct-vibe-vibes"><?php esc_html_e( 'Vibe choices (one per line)', 'personalized-city-tour' ); ?></label></th>
				<td><textarea id="pct-vibe-vibes" name="vibes" rows="5" class="large-text"><?php echo esc_textarea( $vibe['vibes'] ); ?></textarea></td>
			</tr>
			<tr>
				<th scope="row"><label for="pct-vibe-styles"><?php esc_html_e( 'Illustration styles', 'personalized-city-tour' ); ?></label></th>
				<td><textarea id="pct-vibe-styles" name="styles" rows="5" class="large-text"><?php echo esc_textarea( $vibe['styles'] ); ?></textarea></td>
			</tr>
			<tr>
				<th scope="row"><label for="pct-vibe-subjects"><?php esc_html_e( 'Photo subjects', 'personalized-city-tour' ); ?></label></th>
				<td><textarea id="pct-vibe-subjects" name="subjects" rows="5" class="large-text"><?php echo esc_textarea( $vibe['subjects'] ); ?></textarea></td>
			</tr>
			<tr>
				<th scope="row"><label for="pct-vibe-prompt"><?php esc_html_e( 'Prompt template', 'personalized-city-tour' ); ?></label></th>
				<td><textarea id="pct-vibe-prompt" name="prompt_template" rows="3" class="large-text"><?php echo esc_textarea( $vibe['prompt_template'] ); ?></textarea>
				<p class="description"><?php esc_html_e( 'Placeholders: {style} {subject} {city} {vibe} {notes} {name}. The uploaded photo is sent as the reference image when the provider supports it.', 'personalized-city-tour' ); ?></p></td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Delivery', 'personalized-city-tour' ); ?></th>
				<td>
					<select name="delivery">
						<option value="sms" <?php selected( $vibe['delivery'], 'sms' ); ?>><?php esc_html_e( 'SMS to the visitor (uses the SMS settings)', 'personalized-city-tour' ); ?></option>
						<option value="email" <?php selected( $vibe['delivery'], 'email' ); ?>><?php esc_html_e( 'E-mail (when provided)', 'personalized-city-tour' ); ?></option>
						<option value="manual" <?php selected( $vibe['delivery'], 'manual' ); ?>><?php esc_html_e( 'Manual — I send the result link myself', 'personalized-city-tour' ); ?></option>
					</select>
					<label><?php esc_html_e( 'OTP validity (minutes)', 'personalized-city-tour' ); ?> <input type="number" name="otp_ttl" value="<?php echo esc_attr( $vibe['otp_ttl'] ); ?>" min="2" max="60" class="small-text" /></label>
					<label><?php esc_html_e( 'Max upload (MB)', 'personalized-city-tour' ); ?> <input type="number" name="max_upload_mb" value="<?php echo esc_attr( $vibe['max_upload_mb'] ); ?>" min="1" max="50" class="small-text" /></label>
					<label><?php esc_html_e( 'Requests shown', 'personalized-city-tour' ); ?> <input type="number" name="requests_per_page" value="<?php echo esc_attr( $vibe['requests_per_page'] ); ?>" min="5" max="200" class="small-text" /></label>
					<label><?php esc_html_e( 'SMS outbox shown', 'personalized-city-tour' ); ?> <input type="number" name="outbox_per_page" value="<?php echo esc_attr( $vibe['outbox_per_page'] ); ?>" min="5" max="200" class="small-text" /></label>
					<label><?php esc_html_e( 'Image size', 'personalized-city-tour' ); ?>
						<select name="image_size">
							<?php foreach ( array( '1024x1024', '1024x1536', '1536x1024', '512x512' ) as $size ) : ?>
								<option value="<?php echo esc_attr( $size ); ?>" <?php selected( $vibe['image_size'], $size ); ?>><?php echo esc_html( $size ); ?></option>
							<?php endforeach; ?>
						</select>
					</label>
					<p class="description"><?php echo esc_html( sprintf( /* translators: %s: settings URL */ __( 'SMS gateway and image provider credentials are configured under Settings → SMS & Images (%s).', 'personalized-city-tour' ), admin_url( 'admin.php?page=pct-settings&tab=sms' ) ) ); ?></p>
				</td>
			</tr>
		</table>
		<?php submit_button( __( 'Save settings', 'personalized-city-tour' ) ); ?>
	</form>
</div>
