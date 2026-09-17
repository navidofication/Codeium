<?php
/**
 * Dashboard view. Variables: $counts.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="wrap pct-wrap">
	<h1><?php esc_html_e( 'Personalized City Tour', 'personalized-city-tour' ); ?></h1>

	<div class="pct-cards">
		<a class="pct-card" href="<?php echo esc_url( admin_url( 'edit.php?post_type=' . PCT_Post_Types::CITY ) ); ?>">
			<span class="pct-card-num"><?php echo (int) $counts['cities']; ?></span>
			<span><?php esc_html_e( 'Cities', 'personalized-city-tour' ); ?></span>
		</a>
		<a class="pct-card" href="<?php echo esc_url( admin_url( 'edit.php?post_type=' . PCT_Post_Types::PLACE ) ); ?>">
			<span class="pct-card-num"><?php echo (int) $counts['places']; ?></span>
			<span><?php esc_html_e( 'City data entries', 'personalized-city-tour' ); ?></span>
		</a>
		<a class="pct-card" href="<?php echo esc_url( admin_url( 'edit.php?post_type=' . PCT_Post_Types::EVENT ) ); ?>">
			<span class="pct-card-num"><?php echo (int) $counts['events']; ?></span>
			<span><?php esc_html_e( 'Published events', 'personalized-city-tour' ); ?></span>
		</a>
		<?php if ( current_user_can( 'pct_manage' ) ) : ?>
		<a class="pct-card <?php echo $counts['pending'] ? 'is-attention' : ''; ?>" href="<?php echo esc_url( admin_url( 'edit.php?post_status=pending&post_type=' . PCT_Post_Types::EVENT ) ); ?>">
			<span class="pct-card-num"><?php echo (int) $counts['pending']; ?></span>
			<span><?php esc_html_e( 'Submissions awaiting review', 'personalized-city-tour' ); ?></span>
		</a>
		<a class="pct-card <?php echo $counts['guides'] ? 'is-attention' : ''; ?>" href="<?php echo esc_url( admin_url( 'admin.php?page=pct-guides' ) ); ?>">
			<span class="pct-card-num"><?php echo (int) $counts['guides']; ?></span>
			<span><?php esc_html_e( 'Guide applications', 'personalized-city-tour' ); ?></span>
		</a>
		<a class="pct-card" href="<?php echo esc_url( admin_url( 'edit.php?post_type=' . PCT_Post_Types::TOUR ) ); ?>">
			<span class="pct-card-num"><?php echo (int) $counts['tours']; ?></span>
			<span><?php esc_html_e( 'Generated tours', 'personalized-city-tour' ); ?></span>
		</a>
		<?php endif; ?>
	</div>

	<?php if ( current_user_can( 'pct_manage' ) ) : ?>
	<div class="pct-panel">
		<h2><?php esc_html_e( 'Getting started', 'personalized-city-tour' ); ?></h2>
		<ol>
			<li><?php esc_html_e( 'Add at least one city with its centre coordinates and (optionally) Google My Maps links.', 'personalized-city-tour' ); ?></li>
			<li><?php esc_html_e( 'Fill the city database: historical, cultural, social, artistic places … each with coordinates, categories and accessibility.', 'personalized-city-tour' ); ?></li>
			<li><?php esc_html_e( 'Configure the AI provider and map settings under Settings (the built-in planner works without an AI key).', 'personalized-city-tour' ); ?></li>
			<li>
				<?php esc_html_e( 'Place the frontend on any page with the shortcode', 'personalized-city-tour' ); ?>
				<code>[personalized_city_tour]</code>
				<?php esc_html_e( '— or use', 'personalized-city-tour' ); ?>
				<code>[personalized_city_tour view="traveler"]</code> / <code>[personalized_city_tour view="guide"]</code>
				<?php esc_html_e( 'for a single entry point.', 'personalized-city-tour' ); ?>
			</li>
		</ol>
		<p>
			<?php esc_html_e( 'Status:', 'personalized-city-tour' ); ?>
			<?php echo PCT_AI::enabled() ? esc_html( sprintf( /* translators: %s: provider */ __( 'AI planner active (%s).', 'personalized-city-tour' ), PCT_Settings::get( 'ai_provider' ) ) ) : esc_html__( 'Rule-based planner (no AI key configured).', 'personalized-city-tour' ); ?>
			<?php echo 'logged_in' === PCT_Settings::get( 'access_mode' ) ? esc_html__( 'Frontend limited to logged-in users.', 'personalized-city-tour' ) : esc_html__( 'Frontend is public.', 'personalized-city-tour' ); ?>
		</p>
	</div>
	<?php else : ?>
	<div class="pct-panel">
		<h2><?php esc_html_e( 'Welcome, local guide', 'personalized-city-tour' ); ?></h2>
		<p><?php esc_html_e( 'Use the menu to register events and city data entries. Submissions are reviewed by the site administrators before they are shown to travellers.', 'personalized-city-tour' ); ?></p>
	</div>
	<?php endif; ?>
</div>
