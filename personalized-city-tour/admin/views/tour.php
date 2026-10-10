<?php
/**
 * Generated tour inspection. Variables: $post, $form, $result, $token, $fields.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
$share = '';
if ( $token ) {
	$page  = PCT_Frontend::frontend_url();
	$share = $page ? add_query_arg( array( 'pct_tour' => $post->ID, 'pct_token' => $token ), $page ) : '';
}
?>
<div class="pct-tour-admin">
	<?php if ( $share ) : ?>
		<p><strong><?php esc_html_e( 'Shareable link:', 'personalized-city-tour' ); ?></strong> <a href="<?php echo esc_url( $share ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $share ); ?></a></p>
	<?php endif; ?>

	<h3><?php esc_html_e( 'Submitted preferences', 'personalized-city-tour' ); ?></h3>
	<table class="widefat striped pct-form-dump">
		<tbody>
		<?php foreach ( $form as $key => $value ) : ?>
			<?php
			$field = $fields[ $key ] ?? array( 'id' => $key, 'type' => 'text', 'label' => $key, 'options' => array() );
			$shown = PCT_Form_Builder::display_value( $field, $value );
			if ( '' === $shown ) {
				continue;
			}
			?>
			<tr>
				<th scope="row"><?php echo esc_html( $field['label'] ?: $key ); ?></th>
				<td><?php echo esc_html( $shown ); ?></td>
			</tr>
		<?php endforeach; ?>
		</tbody>
	</table>

	<h3><?php esc_html_e( 'Generated tour', 'personalized-city-tour' ); ?></h3>
	<?php if ( empty( $result['days'] ) ) : ?>
		<p><?php esc_html_e( 'No days were generated.', 'personalized-city-tour' ); ?></p>
	<?php endif; ?>
	<?php foreach ( (array) ( $result['days'] ?? array() ) as $day ) : ?>
		<div class="pct-panel">
			<h4><?php echo esc_html( $day['title'] ); ?></h4>
			<p><?php echo esc_html( $day['summary'] ); ?></p>
			<ol>
			<?php foreach ( (array) $day['stops'] as $stop ) : ?>
				<li>
					<strong><?php echo esc_html( $stop['time'] ? $stop['time'] . ' · ' : '' ); ?><a href="<?php echo esc_url( get_edit_post_link( $stop['item_id'] ) ); ?>"><?php echo esc_html( $stop['name'] ); ?></a></strong>
					<?php if ( $stop['tags'] ) : ?><span class="pct-muted"> — <?php echo esc_html( implode( ', ', $stop['tags'] ) ); ?></span><?php endif; ?>
					<?php if ( $stop['note'] ) : ?><br><em><?php echo esc_html( $stop['note'] ); ?></em><?php endif; ?>
				</li>
			<?php endforeach; ?>
			</ol>
			<?php if ( ! empty( $day['alternatives'] ) ) : ?>
				<p class="pct-muted"><?php esc_html_e( 'Alternatives:', 'personalized-city-tour' ); ?> <?php echo esc_html( implode( ', ', wp_list_pluck( $day['alternatives'], 'name' ) ) ); ?></p>
			<?php endif; ?>
			<?php if ( ! empty( $day['serendipity'] ) ) : ?>
				<div class="pct-panel"><strong><?php esc_html_e( 'Serendipity discoveries', 'personalized-city-tour' ); ?></strong>
				<?php foreach ( (array) $day['serendipity'] as $ser ) : ?>
					<p><a href="<?php echo esc_url( $ser['link'] ?? '' ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $ser['name'] ); ?></a> — <?php echo esc_html( $ser['note'] ); ?></p>
				<?php endforeach; ?>
				</div>
			<?php endif; ?>
			<?php if ( ! empty( $day['accessibility_notes'] ) ) : ?>
				<p><strong><?php esc_html_e( 'Accessibility:', 'personalized-city-tour' ); ?></strong> <?php echo esc_html( $day['accessibility_notes'] ); ?></p>
			<?php endif; ?>
			<?php if ( ! empty( $day['quick_search_hints'] ) ) : ?>
				<p><strong><?php esc_html_e( 'Quick search hints:', 'personalized-city-tour' ); ?></strong> <?php echo esc_html( implode( ' · ', $day['quick_search_hints'] ) ); ?></p>
			<?php endif; ?>
			<p>
				<a href="<?php echo esc_url( $day['map']['google_url'] ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Google Maps route', 'personalized-city-tour' ); ?></a> ·
				<a href="<?php echo esc_url( $day['map']['osm_url'] ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'OpenStreetMap route', 'personalized-city-tour' ); ?></a>
			</p>
		</div>
	<?php endforeach; ?>
</div>
