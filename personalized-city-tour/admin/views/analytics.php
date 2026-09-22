<?php
/**
 * Center for Statistics, Information & Analysis. Variables: $jobs, $report, $cities, $fields, $formats, $msg.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
$notices = array(
	'queued'  => __( 'Analysis queued. It runs through WP-Cron within a few minutes (or immediately with “Run now”).', 'personalized-city-tour' ),
	'ran'     => __( 'Queue processed.', 'personalized-city-tour' ),
	'deleted' => __( 'Report deleted.', 'personalized-city-tour' ),
	'error'   => __( 'The request could not be processed.', 'personalized-city-tour' ),
);
$status_labels = array(
	'queued'  => __( 'Queued', 'personalized-city-tour' ),
	'running' => __( 'Running', 'personalized-city-tour' ),
	'done'    => __( 'Done', 'personalized-city-tour' ),
);
$result  = $report ? $report['result'] : array();
$ai_on   = PCT_AI::enabled();
$page_url = admin_url( 'admin.php?page=pct-analytics' );
?>
<div class="wrap pct-wrap pct-analytics">
	<h1><?php esc_html_e( 'Center for Statistics, Information & Analysis', 'personalized-city-tour' ); ?></h1>
	<?php if ( $msg && isset( $notices[ $msg ] ) ) : ?>
		<div class="notice <?php echo 'error' === $msg ? 'notice-error' : 'notice-success'; ?> is-dismissible"><p><?php echo esc_html( $notices[ $msg ] ); ?></p></div>
	<?php endif; ?>

	<div class="pct-analytics-grid">
		<div class="pct-panel">
			<h2><?php esc_html_e( 'Request an analysis', 'personalized-city-tour' ); ?></h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'pct_analytics_request' ); ?>
				<input type="hidden" name="action" value="pct_analytics_request" />
				<p><label><?php esc_html_e( 'Title', 'personalized-city-tour' ); ?><br><input type="text" name="title" class="regular-text" placeholder="<?php esc_attr_e( 'e.g. Spring season overview', 'personalized-city-tour' ); ?>" /></label></p>
				<p><label><?php esc_html_e( 'Question for the analysis', 'personalized-city-tour' ); ?><br><textarea name="question" rows="3" class="large-text" placeholder="<?php esc_attr_e( 'Which interests are most requested by families, and which cities are growing?', 'personalized-city-tour' ); ?>"></textarea></label></p>
				<p>
					<label><?php esc_html_e( 'Period (days)', 'personalized-city-tour' ); ?> <input type="number" name="days" value="90" min="1" max="730" class="small-text" /></label>
					<label><?php esc_html_e( 'City', 'personalized-city-tour' ); ?>
						<select name="city_id">
							<option value="0"><?php esc_html_e( 'All cities', 'personalized-city-tour' ); ?></option>
							<?php foreach ( $cities as $city_id => $city_name ) : ?>
								<option value="<?php echo (int) $city_id; ?>"><?php echo esc_html( $city_name ); ?></option>
							<?php endforeach; ?>
						</select>
					</label>
				</p>
				<p><?php esc_html_e( 'Form fields to break down (answers only, never names or contacts):', 'personalized-city-tour' ); ?><br>
					<?php foreach ( $fields as $fk => $fd ) : if ( ! PCT_Analytics::is_aggregatable_field( $fk, $fields ) ) { continue; } ?>
						<label class="pct-inline"><input type="checkbox" name="fields[]" value="<?php echo esc_attr( $fk ); ?>" <?php checked( in_array( $fk, array( 'group_type', 'interests', 'budget_tier', 'travel_style' ), true ) ); ?> /> <?php echo esc_html( $fd['label'] ); ?></label>
					<?php endforeach; ?>
				</p>
				<p>
					<label><input type="checkbox" name="use_ai" value="1" <?php checked( $ai_on ); ?> <?php disabled( ! $ai_on ); ?> /> <?php esc_html_e( 'Ask the configured AI to interpret the aggregated data', 'personalized-city-tour' ); ?></label>
					<?php if ( ! $ai_on ) : ?><span class="description"><?php esc_html_e( '(no AI provider configured — a deterministic report is produced)', 'personalized-city-tour' ); ?></span><?php endif; ?>
					<br><label><input type="checkbox" name="run_now" value="1" checked /> <?php esc_html_e( 'Run immediately instead of waiting for the queue', 'personalized-city-tour' ); ?></label>
				</p>
				<p><button type="submit" class="button button-primary"><?php esc_html_e( 'Request analysis', 'personalized-city-tour' ); ?></button></p>
			</form>
		</div>

		<div class="pct-panel">
			<h2><?php esc_html_e( 'Reports', 'personalized-city-tour' ); ?></h2>
			<?php if ( ! $jobs ) : ?>
				<p class="description"><?php esc_html_e( 'No reports yet.', 'personalized-city-tour' ); ?></p>
			<?php else : ?>
				<table class="widefat striped">
					<thead><tr><th><?php esc_html_e( 'Title', 'personalized-city-tour' ); ?></th><th><?php esc_html_e( 'Created', 'personalized-city-tour' ); ?></th><th><?php esc_html_e( 'Status', 'personalized-city-tour' ); ?></th><th></th></tr></thead>
					<tbody>
					<?php foreach ( $jobs as $job ) : ?>
						<tr class="<?php echo $report && $report['id'] === $job['id'] ? 'pct-row-active' : ''; ?>">
							<td><a href="<?php echo esc_url( add_query_arg( 'report', $job['id'], $page_url ) ); ?>"><?php echo esc_html( $job['title'] ); ?></a></td>
							<td><?php echo esc_html( PCT_I18n::format_date( strtotime( $job['created'] ), null, true ) ); ?></td>
							<td><span class="pct-status pct-status-<?php echo esc_attr( $job['status'] ); ?>"><?php echo esc_html( $status_labels[ $job['status'] ] ?? $job['status'] ); ?></span></td>
							<td>
								<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" data-confirm class="pct-inline-form">
									<?php wp_nonce_field( 'pct_analytics_delete' ); ?>
									<input type="hidden" name="action" value="pct_analytics_delete" />
									<input type="hidden" name="report" value="<?php echo (int) $job['id']; ?>" />
									<button type="submit" class="button-link-delete"><?php esc_html_e( 'Delete', 'personalized-city-tour' ); ?></button>
								</form>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="pct-inline-form">
					<?php wp_nonce_field( 'pct_analytics_run_now' ); ?>
					<input type="hidden" name="action" value="pct_analytics_run_now" />
					<button type="submit" class="button"><?php esc_html_e( 'Process queue now', 'personalized-city-tour' ); ?></button>
				</form>
			<?php endif; ?>
		</div>
	</div>

	<?php if ( $report && 'done' === $report['status'] ) : ?>
	<div class="pct-panel pct-report" id="pct-report" data-report="<?php echo esc_attr( wp_json_encode( array( 'charts' => $result['charts'] ?? array() ) ) ); ?>">
		<div class="pct-report-head">
			<h2><?php echo esc_html( $report['title'] ); ?></h2>
			<div class="pct-report-actions">
				<?php foreach ( $formats as $fmt => $label ) : ?>
					<a class="button" href="<?php echo esc_url( wp_nonce_url( add_query_arg( array( 'action' => 'pct_analytics_export', 'report' => $report['id'], 'format' => $fmt ), admin_url( 'admin-post.php' ) ), 'pct_analytics_export' ) ); ?>"><?php echo esc_html( $label ); ?></a>
				<?php endforeach; ?>
				<button type="button" class="button" id="pct-report-png"><?php esc_html_e( 'PNG (charts)', 'personalized-city-tour' ); ?></button>
			</div>
		</div>
		<p class="description">
			<?php echo esc_html( sprintf( /* translators: 1: source, 2: period, 3: finish time */ __( 'Source: %1$s · period: %2$d days · finished %3$s', 'personalized-city-tour' ), $result['source'] ?? 'rules', $result['dataset']['scope']['days'] ?? 0, $report['finished'] ) ); ?>
			<?php if ( ! empty( $result['dataset']['scope']['city'] ) ) : ?> · <?php echo esc_html( $result['dataset']['scope']['city'] ); ?><?php endif; ?>
		</p>
		<?php if ( $report['error'] ) : ?><div class="notice notice-warning inline"><p><?php echo esc_html( $report['error'] ); ?></p></div><?php endif; ?>

		<h3><?php esc_html_e( 'Summary', 'personalized-city-tour' ); ?></h3>
		<p class="pct-report-summary"><?php echo esc_html( $result['summary'] ?? '' ); ?></p>
		<?php if ( ! empty( $result['insights'] ) ) : ?>
			<ul class="pct-report-insights">
				<?php foreach ( (array) $result['insights'] as $line ) : ?><li><?php echo esc_html( $line ); ?></li><?php endforeach; ?>
			</ul>
		<?php endif; ?>

		<?php if ( ! empty( $result['charts'] ) ) : ?>
			<h3><?php esc_html_e( 'Charts', 'personalized-city-tour' ); ?></h3>
			<div class="pct-charts">
				<?php foreach ( (array) $result['charts'] as $i => $chart ) : ?>
					<figure class="pct-chart">
						<figcaption><?php echo esc_html( $chart['title'] ?? '' ); ?></figcaption>
						<canvas data-chart="<?php echo (int) $i; ?>" width="480" height="260" role="img" aria-label="<?php echo esc_attr( $chart['title'] ?? '' ); ?>"></canvas>
					</figure>
				<?php endforeach; ?>
			</div>
		<?php endif; ?>

		<?php foreach ( (array) ( $result['tables'] ?? array() ) as $table ) : ?>
			<h3><?php echo esc_html( $table['title'] ?? '' ); ?></h3>
			<table class="widefat striped pct-report-table">
				<thead><tr><?php foreach ( (array) ( $table['columns'] ?? array() ) as $col ) : ?><th><?php echo esc_html( $col ); ?></th><?php endforeach; ?></tr></thead>
				<tbody>
				<?php foreach ( (array) ( $table['rows'] ?? array() ) as $row ) : ?>
					<tr><?php foreach ( (array) $row as $cell ) : ?><td><?php echo esc_html( is_scalar( $cell ) ? (string) $cell : wp_json_encode( $cell ) ); ?></td><?php endforeach; ?></tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		<?php endforeach; ?>
	</div>
	<?php elseif ( $report ) : ?>
		<div class="pct-panel"><p><?php echo esc_html( sprintf( /* translators: %s: status */ __( 'Report “%1$s” is %2$s. Reload this page in a moment or process the queue now.', 'personalized-city-tour' ), $report['title'], $status_labels[ $report['status'] ] ?? $report['status'] ) ); ?></p></div>
	<?php endif; ?>
</div>
