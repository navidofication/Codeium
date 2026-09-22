<?php
/**
 * AI audit log viewer (administrators only). Variables: $log, $entry, $stats, $args, $msg.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
$page_url = admin_url( 'admin.php?page=pct-ai-log' );
$kinds    = array(
	''         => __( 'All kinds', 'personalized-city-tour' ),
	'tour'     => __( 'Tour generation', 'personalized-city-tour' ),
	'analysis' => __( 'Analytics', 'personalized-city-tour' ),
	'image'    => __( 'Image generation', 'personalized-city-tour' ),
	'translate' => __( 'Translation', 'personalized-city-tour' ),
	'test'     => __( 'Connection test', 'personalized-city-tour' ),
	'audit'    => __( 'Admin actions', 'personalized-city-tour' ),
);
$statuses = array(
	''        => __( 'All statuses', 'personalized-city-tour' ),
	'ok'      => __( 'OK', 'personalized-city-tour' ),
	'invalid' => __( 'Invalid JSON / schema', 'personalized-city-tour' ),
	'error'   => __( 'Error', 'personalized-city-tour' ),
	'info'    => __( 'Info', 'personalized-city-tour' ),
);
$level = PCT_AI_Log::level();
?>
<div class="wrap pct-wrap pct-ai-log">
	<h1><?php esc_html_e( 'AI Audit Log', 'personalized-city-tour' ); ?></h1>
	<?php if ( 'purged' === $msg ) : ?>
		<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Log purged.', 'personalized-city-tour' ); ?></p></div>
	<?php endif; ?>
	<p class="description">
		<?php echo esc_html( sprintf( /* translators: 1: level, 2: retention days */ __( 'Logging level: %1$s · retention: %2$s days. Personal data is redacted when enabled in the AI settings.', 'personalized-city-tour' ), $level, (int) PCT_Settings::get( 'ai_log_retention_days', 30 ) ) ); ?>
		<a href="<?php echo esc_url( admin_url( 'admin.php?page=pct-settings&tab=ai' ) ); ?>"><?php esc_html_e( 'Change', 'personalized-city-tour' ); ?></a>
	</p>

	<div class="pct-stats">
		<div class="pct-stat"><span class="pct-stat-value"><?php echo (int) $stats['total']; ?></span><span class="pct-stat-label"><?php esc_html_e( 'AI requests (30 days)', 'personalized-city-tour' ); ?></span></div>
		<div class="pct-stat"><span class="pct-stat-value"><?php echo (int) $stats['ok']; ?></span><span class="pct-stat-label"><?php esc_html_e( 'Valid', 'personalized-city-tour' ); ?></span></div>
		<div class="pct-stat"><span class="pct-stat-value"><?php echo (int) $stats['invalid']; ?></span><span class="pct-stat-label"><?php esc_html_e( 'Invalid responses', 'personalized-city-tour' ); ?></span></div>
		<div class="pct-stat"><span class="pct-stat-value"><?php echo (int) $stats['error']; ?></span><span class="pct-stat-label"><?php esc_html_e( 'Errors', 'personalized-city-tour' ); ?></span></div>
		<div class="pct-stat"><span class="pct-stat-value"><?php echo esc_html( $stats['avg_ms'] >= 1000 ? round( $stats['avg_ms'] / 1000, 1 ) . ' s' : $stats['avg_ms'] . ' ms' ); ?></span><span class="pct-stat-label"><?php esc_html_e( 'Average duration', 'personalized-city-tour' ); ?></span></div>
	</div>

	<form method="get" class="pct-inline-form pct-log-filters">
		<input type="hidden" name="page" value="pct-ai-log" />
		<select name="kind"><?php foreach ( $kinds as $k => $l ) : ?><option value="<?php echo esc_attr( $k ); ?>" <?php selected( $args['kind'], $k ); ?>><?php echo esc_html( $l ); ?></option><?php endforeach; ?></select>
		<select name="status"><?php foreach ( $statuses as $k => $l ) : ?><option value="<?php echo esc_attr( $k ); ?>" <?php selected( $args['status'], $k ); ?>><?php echo esc_html( $l ); ?></option><?php endforeach; ?></select>
		<button type="submit" class="button"><?php esc_html_e( 'Filter', 'personalized-city-tour' ); ?></button>
	</form>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="pct-inline-form" data-confirm>
		<?php wp_nonce_field( 'pct_purge_log' ); ?>
		<input type="hidden" name="action" value="pct_purge_log" />
		<button type="submit" class="button-link-delete"><?php esc_html_e( 'Purge all entries', 'personalized-city-tour' ); ?></button>
	</form>

	<?php if ( $entry ) : ?>
		<div class="pct-panel pct-log-entry">
			<h2><?php echo esc_html( sprintf( '#%d · %s · %s', $entry['id'], $entry['kind'], $entry['title'] ) ); ?></h2>
			<p><?php echo esc_html( sprintf( '%s · %s · %s · %s · %d ms · attempt %d', $entry['created'], $entry['provider'], $entry['model'], $entry['status'], $entry['duration_ms'], $entry['attempt'] ) ); ?>
				<?php if ( $entry['user_id'] ) : $u = get_userdata( (int) $entry['user_id'] ); ?> · <?php echo esc_html( $u ? $u->display_name : '#' . $entry['user_id'] ); ?><?php endif; ?></p>
			<?php if ( $entry['error'] ) : ?><h3><?php esc_html_e( 'Error / validation', 'personalized-city-tour' ); ?></h3><pre><?php echo esc_html( $entry['error'] ); ?></pre><?php endif; ?>
			<?php if ( null !== $entry['request'] && '' !== $entry['request'] ) : ?><h3><?php esc_html_e( 'Request', 'personalized-city-tour' ); ?></h3><pre><?php echo esc_html( $entry['request'] ); ?></pre><?php endif; ?>
			<?php if ( null !== $entry['response'] && '' !== $entry['response'] ) : ?><h3><?php esc_html_e( 'Response', 'personalized-city-tour' ); ?></h3><pre><?php echo esc_html( $entry['response'] ); ?></pre><?php endif; ?>
			<?php if ( 'full' !== $level && 'audit' !== $entry['kind'] ) : ?><p class="description"><?php esc_html_e( 'Prompts and responses are only stored at the “Full” logging level.', 'personalized-city-tour' ); ?></p><?php endif; ?>
			<a href="<?php echo esc_url( $page_url ); ?>" class="button"><?php esc_html_e( 'Back to list', 'personalized-city-tour' ); ?></a>
		</div>
	<?php endif; ?>

	<table class="widefat striped">
		<thead><tr>
			<th>#</th><th><?php esc_html_e( 'Time', 'personalized-city-tour' ); ?></th><th><?php esc_html_e( 'Kind', 'personalized-city-tour' ); ?></th><th><?php esc_html_e( 'Provider / model', 'personalized-city-tour' ); ?></th><th><?php esc_html_e( 'Status', 'personalized-city-tour' ); ?></th><th><?php esc_html_e( 'Duration', 'personalized-city-tour' ); ?></th><th><?php esc_html_e( 'Title', 'personalized-city-tour' ); ?></th><th><?php esc_html_e( 'Error', 'personalized-city-tour' ); ?></th>
		</tr></thead>
		<tbody>
		<?php if ( ! $log['rows'] ) : ?>
			<tr><td colspan="8"><?php esc_html_e( 'No entries.', 'personalized-city-tour' ); ?></td></tr>
		<?php endif; ?>
		<?php foreach ( $log['rows'] as $row ) : ?>
			<tr>
				<td><a href="<?php echo esc_url( add_query_arg( 'entry', $row['id'], $page_url ) ); ?>"><?php echo (int) $row['id']; ?></a></td>
				<td><?php echo esc_html( $row['created'] ); ?></td>
				<td><?php echo esc_html( $row['kind'] ); ?><?php echo $row['attempt'] > 1 ? ' <small>(' . esc_html__( 'retry', 'personalized-city-tour' ) . ')</small>' : ''; ?></td>
				<td><?php echo esc_html( trim( $row['provider'] . ' ' . $row['model'] ) ); ?></td>
				<td><span class="pct-status pct-status-<?php echo esc_attr( $row['status'] ); ?>"><?php echo esc_html( $statuses[ $row['status'] ] ?? $row['status'] ); ?></span></td>
				<td><?php echo $row['duration_ms'] ? esc_html( $row['duration_ms'] . ' ms' ) : '–'; ?></td>
				<td><?php echo esc_html( $row['title'] ); ?></td>
				<td><small><?php echo esc_html( mb_substr( (string) $row['error'], 0, 120 ) ); ?></small></td>
			</tr>
		<?php endforeach; ?>
		</tbody>
	</table>
	<?php if ( $log['pages'] > 1 ) : ?>
		<div class="tablenav"><div class="tablenav-pages">
			<?php
			echo wp_kses_post(
				paginate_links(
					array(
						'base'    => add_query_arg( 'paged', '%#%', add_query_arg( array( 'kind' => $args['kind'], 'status' => $args['status'] ), $page_url ) ),
						'format'  => '',
						'current' => $args['page'],
						'total'   => $log['pages'],
					)
				)
			);
			?>
		</div></div>
	<?php endif; ?>
</div>
