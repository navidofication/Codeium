<?php
/**
 * Plugin Name: Personalized City Tour
 * Plugin URI:  https://rahimghazi.ir
 * Description: City databases, local-guide event registration, a travel preference form builder, a rule based planner and AI-generated multilingual personalized tours with online route maps and navigation.
 * Version:     2.2.26
 * Author:      Rahimghazi.ir
 * Author URI:  https://rahimghazi.ir
 * Credits:     RahimGhazi & Devin/R&D HumanAI
 * License:     GPL-2.0-or-later
 * Text Domain: personalized-city-tour
 * Domain Path: /languages
 * Requires at least: 6.0
 * Requires PHP: 7.4
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'PCT_VERSION', '2.2.26' );
define( 'PCT_AUTHOR', 'Rahimghazi.ir' );
define( 'PCT_CREDITS', 'RahimGhazi & Devin/R&D HumanAI' );
define( 'PCT_FILE', __FILE__ );
define( 'PCT_DIR', plugin_dir_path( __FILE__ ) );
define( 'PCT_URL', plugin_dir_url( __FILE__ ) );

spl_autoload_register(
	function ( $class ) {
		if ( 0 !== strpos( $class, 'PCT_' ) ) {
			return;
		}
		$file = PCT_DIR . 'includes/class-' . str_replace( '_', '-', strtolower( $class ) ) . '.php';
		if ( file_exists( $file ) ) {
			require_once $file;
		}
	}
);

register_activation_hook( __FILE__, array( 'PCT_Install', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'PCT_Install', 'deactivate' ) );

add_action( 'plugins_loaded', array( 'PCT_Plugin', 'instance' ) );
