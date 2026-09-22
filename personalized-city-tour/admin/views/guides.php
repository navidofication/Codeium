<?php
/**
 * Local guides view. Variables: $guides, $profiles, $outbox, $cities, $messages, $msg.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
$status_labels = array(
	PCT_Roles::STATUS_PENDING  => __( 'Pending approval', 'personalized-city-tour' ),
	PCT_Roles::STATUS_APPROVED => __( 'Approved', 'personalized-city-tour' ),
	PCT_Roles::STATUS_REVOKED  => __( 'Revoked', 'personalized-city-tour' ),
);
?>
<div class="wrap pct-wrap">
	<h1><?php esc_html_e( 'Local Guides', 'personalized-city-tour' ); ?></h1>
	<?php if ( $msg && isset( $messages[ $msg ] ) ) : ?>
		<div class="notice <?php echo in_array( $msg, array( 'nouser', 'isadmin', 'error' ), true ) ? 'notice-error' : 'notice-success'; ?> is-dismissible"><p><?php echo esc_html( $messages[ $msg ] ); ?></p></div>
	<?php endif; ?>

	<p class="description"><?php esc_html_e( 'Local guides register from the frontend with their mobile number — no account is needed. Their events stay pending until you verify them by phone and approve the registration below. You may also grant an existing WordPress user limited guide permissions.', 'personalized-city-tour' ); ?></p>

	<h2><?php esc_html_e( 'Registrations (mobile verification)', 'personalized-city-tour' ); ?></h2>
	<table class="widefat striped pct-guides-table">
		<thead>
			<tr>
				<th><?php esc_html_e( 'Guide', 'personalized-city-tour' ); ?></th>
				<th><?php esc_html_e( 'Mobile', 'personalized-city-tour' ); ?></th>
				<th><?php esc_html_e( 'City', 'personalized-city-tour' ); ?></th>
				<th><?php esc_html_e( 'Languages / note', 'personalized-city-tour' ); ?></th>
				<th><?php esc_html_e( 'Status', 'personalized-city-tour' ); ?></th>
				<th><?php esc_html_e( 'Events', 'personalized-city-tour' ); ?></th>
				<th><?php esc_html_e( 'Actions', 'personalized-city-tour' ); ?></th>
			</tr>
		</thead>
		<tbody>
		<?php if ( empty( $profiles ) ) : ?>
			<tr><td colspan="7"><?php esc_html_e( 'No registrations yet.', 'personalized-city-tour' ); ?></td></tr>
		<?php endif; ?>
		<?php foreach ( $profiles as $p ) : ?>
			<tr>
				<td><strong><?php echo esc_html( $p['name'] ); ?></strong><br><small><?php echo esc_html( $p['created'] ); ?></small><?php echo $p['email'] ? '<br><a href="mailto:' . esc_attr( $p['email'] ) . '">' . esc_html( $p['email'] ) . '</a>' : ''; ?></td>
				<td><a href="tel:<?php echo esc_attr( $p['mobile'] ); ?>" dir="ltr"><?php echo esc_html( $p['mobile'] ); ?></a></td>
				<td><?php echo $p['city_id'] && isset( $cities[ $p['city_id'] ] ) ? esc_html( $cities[ $p['city_id'] ] ) : '—'; ?></td>
				<td><?php echo esc_html( $p['languages'] ); ?><?php if ( $p['note'] ) : ?><p class="description"><?php echo esc_html( $p['note'] ); ?></p><?php endif; ?></td>
				<td>
					<span class="pct-status pct-status-<?php echo esc_attr( $p['status'] ); ?>"><?php echo esc_html( $status_labels[ $p['status'] ] ?? $p['status'] ); ?></span>
					<?php if ( $p['verified'] ) : ?><br><small><?php echo esc_html( sprintf( /* translators: %s: datetime */ __( 'verified %s', 'personalized-city-tour' ), $p['verified'] ) ); ?></small><?php endif; ?>
				</td>
				<td><a href="<?php echo esc_url( admin_url( 'edit.php?post_type=' . PCT_Post_Types::EVENT . '&pct_guide_profile=' . $p['id'] ) ); ?>"><?php echo (int) $p['events']; ?></a></td>
				<td class="pct-actions">
					<?php foreach ( array( 'approve' => __( 'Verified — approve', 'personalized-city-tour' ), 'revoke' => __( 'Revoke', 'personalized-city-tour' ), 'remove' => __( 'Remove', 'personalized-city-tour' ) ) as $action => $label ) : ?>
						<?php if ( ( 'approve' === $action && PCT_Guide_Profiles::STATUS_APPROVED === $p['status'] ) || ( 'revoke' === $action && PCT_Guide_Profiles::STATUS_APPROVED !== $p['status'] ) ) { continue; } ?>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="pct-inline-form" <?php echo 'remove' === $action ? 'data-confirm="1"' : ''; ?>>
							<?php wp_nonce_field( 'pct_profile_action' ); ?>
							<input type="hidden" name="action" value="pct_profile_action" />
							<input type="hidden" name="profile_action" value="<?php echo esc_attr( $action ); ?>" />
							<input type="hidden" name="profile_id" value="<?php echo (int) $p['id']; ?>" />
							<?php if ( 'approve' === $action ) : ?><label class="pct-inline"><input type="checkbox" name="publish_events" value="1" /> <?php esc_html_e( 'publish pending events', 'personalized-city-tour' ); ?></label><?php endif; ?>
							<button type="submit" class="button <?php echo 'approve' === $action ? 'button-primary' : ( 'remove' === $action ? 'button-link-delete' : '' ); ?>"><?php echo esc_html( $label ); ?></button>
						</form>
					<?php endforeach; ?>
				</td>
			</tr>
		<?php endforeach; ?>
		</tbody>
	</table>

	<?php if ( $outbox ) : ?>
		<h2><?php esc_html_e( 'SMS outbox (send from your phone)', 'personalized-city-tour' ); ?></h2>
		<table class="widefat striped">
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
							<input type="hidden" name="back" value="pct-guides" />
							<button type="submit" class="button-link"><?php esc_html_e( 'Sent', 'personalized-city-tour' ); ?></button>
						</form>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>

	<h2><?php esc_html_e( 'WordPress users with guide permissions', 'personalized-city-tour' ); ?></h2>
	<div class="pct-panel">
		<h2><?php esc_html_e( 'Add a local guide', 'personalized-city-tour' ); ?></h2>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="pct-inline-form">
			<?php wp_nonce_field( 'pct_guide_add' ); ?>
			<input type="hidden" name="action" value="pct_guide_add" />
			<label for="pct-guide-user" class="screen-reader-text"><?php esc_html_e( 'Username or email', 'personalized-city-tour' ); ?></label>
			<input type="text" id="pct-guide-user" name="user" class="regular-text" placeholder="<?php esc_attr_e( 'Username or email of an existing user', 'personalized-city-tour' ); ?>" required />
			<select name="city_id">
				<option value="0"><?php esc_html_e( 'Any city', 'personalized-city-tour' ); ?></option>
				<?php foreach ( $cities as $id => $name ) : ?>
					<option value="<?php echo (int) $id; ?>"><?php echo esc_html( $name ); ?></option>
				<?php endforeach; ?>
			</select>
			<label><input type="checkbox" name="approve_now" value="1" checked /> <?php esc_html_e( 'Approve immediately', 'personalized-city-tour' ); ?></label>
			<button type="submit" class="button button-primary"><?php esc_html_e( 'Add guide', 'personalized-city-tour' ); ?></button>
		</form>
	</div>

	<table class="widefat striped pct-guides-table">
		<thead>
			<tr>
				<th><?php esc_html_e( 'User', 'personalized-city-tour' ); ?></th>
				<th><?php esc_html_e( 'City', 'personalized-city-tour' ); ?></th>
				<th><?php esc_html_e( 'Languages / note', 'personalized-city-tour' ); ?></th>
				<th><?php esc_html_e( 'Status', 'personalized-city-tour' ); ?></th>
				<th><?php esc_html_e( 'Submissions', 'personalized-city-tour' ); ?></th>
				<th><?php esc_html_e( 'Actions', 'personalized-city-tour' ); ?></th>
			</tr>
		</thead>
		<tbody>
		<?php if ( empty( $guides ) ) : ?>
			<tr><td colspan="6"><?php esc_html_e( 'No local guides yet.', 'personalized-city-tour' ); ?></td></tr>
		<?php endif; ?>
		<?php foreach ( $guides as $guide ) :
			$status  = PCT_Roles::guide_status( $guide->ID );
			$city_id = (int) get_user_meta( $guide->ID, '_pct_guide_city', true );
			$events  = count_user_posts( $guide->ID, PCT_Post_Types::EVENT, true );
			$places  = count_user_posts( $guide->ID, PCT_Post_Types::PLACE, true );
			?>
			<tr>
				<td>
					<strong><a href="<?php echo esc_url( get_edit_user_link( $guide->ID ) ); ?>"><?php echo esc_html( $guide->display_name ); ?></a></strong><br>
					<a href="mailto:<?php echo esc_attr( $guide->user_email ); ?>"><?php echo esc_html( $guide->user_email ); ?></a>
				</td>
				<td><?php echo $city_id && isset( $cities[ $city_id ] ) ? esc_html( $cities[ $city_id ] ) : '—'; ?></td>
				<td>
					<?php echo esc_html( get_user_meta( $guide->ID, '_pct_guide_languages', true ) ); ?>
					<?php $note = get_user_meta( $guide->ID, '_pct_guide_note', true ); ?>
					<?php if ( $note ) : ?><p class="description"><?php echo esc_html( $note ); ?></p><?php endif; ?>
				</td>
				<td><span class="pct-status pct-status-<?php echo esc_attr( $status ); ?>"><?php echo esc_html( $status_labels[ $status ] ?? $status ); ?></span></td>
				<td>
					<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=' . PCT_Post_Types::EVENT . '&author=' . $guide->ID ) ); ?>"><?php echo esc_html( sprintf( /* translators: %d: count */ _n( '%d event', '%d events', $events, 'personalized-city-tour' ), $events ) ); ?></a>,
					<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=' . PCT_Post_Types::PLACE . '&author=' . $guide->ID ) ); ?>"><?php echo esc_html( sprintf( /* translators: %d: count */ _n( '%d entry', '%d entries', $places, 'personalized-city-tour' ), $places ) ); ?></a>
				</td>
				<td class="pct-actions">
					<?php foreach ( array( 'approve' => __( 'Approve', 'personalized-city-tour' ), 'revoke' => __( 'Revoke', 'personalized-city-tour' ), 'remove' => __( 'Remove', 'personalized-city-tour' ) ) as $action => $label ) : ?>
						<?php if ( ( 'approve' === $action && PCT_Roles::STATUS_APPROVED === $status ) || ( 'revoke' === $action && PCT_Roles::STATUS_APPROVED !== $status ) ) { continue; } ?>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="pct-inline-form" <?php echo 'remove' === $action ? 'data-confirm="1"' : ''; ?>>
							<?php wp_nonce_field( 'pct_guide_action' ); ?>
							<input type="hidden" name="action" value="pct_guide_action" />
							<input type="hidden" name="guide_action" value="<?php echo esc_attr( $action ); ?>" />
							<input type="hidden" name="user_id" value="<?php echo (int) $guide->ID; ?>" />
							<button type="submit" class="button <?php echo 'approve' === $action ? 'button-primary' : ( 'remove' === $action ? 'button-link-delete' : '' ); ?>"><?php echo esc_html( $label ); ?></button>
						</form>
					<?php endforeach; ?>
				</td>
			</tr>
		<?php endforeach; ?>
		</tbody>
	</table>
</div>
