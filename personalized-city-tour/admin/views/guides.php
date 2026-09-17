<?php
/**
 * Local guides view. Variables: $guides, $cities, $messages, $msg.
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

	<p class="description"><?php esc_html_e( 'Local guides can register their own events and city data entries with limited permissions. Approve applications below, or add an existing user directly.', 'personalized-city-tour' ); ?></p>

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
