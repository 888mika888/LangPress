<?php
/**
 * Plugin Name: LangPress
 * Plugin URI:  https://github.com/888mika888/LangPress
 * Description: Multilingual WordPress plugin with a visual translation editor. Supports German, English and Ukrainian without creating duplicate pages.
 * Version:     1.5.0
 * Author:      888mika888
 * Author URI:  https://github.com/888mika888
 * Text Domain: langpress
 * Domain Path: /languages
 * Requires at least: 6.0
 * Requires PHP: 8.1
 * License:     GPL v2 or later
 */

defined( 'ABSPATH' ) || exit;

define( 'LP_VERSION',      '1.5.0' );
define( 'LP_PLUGIN_FILE',  __FILE__ );
define( 'LP_PLUGIN_DIR',   plugin_dir_path( __FILE__ ) );
define( 'LP_PLUGIN_URL',   plugin_dir_url( __FILE__ ) );
define( 'LP_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

// Simple class map autoloader that keeps the includes folder tidy.
spl_autoload_register( function ( string $class ): void {
	$map = [
		'LP_Activator'         => LP_PLUGIN_DIR . 'includes/class-lp-activator.php',
		'LP_Database'          => LP_PLUGIN_DIR . 'includes/class-lp-database.php',
		'LP_Translator'        => LP_PLUGIN_DIR . 'includes/class-lp-translator.php',
		'LP_Language_Switcher' => LP_PLUGIN_DIR . 'includes/class-lp-language-switcher.php',
		'LP_Frontend'          => LP_PLUGIN_DIR . 'includes/class-lp-frontend.php',
		'LP_Admin'             => LP_PLUGIN_DIR . 'includes/class-lp-admin.php',
	];
	if ( isset( $map[ $class ] ) ) {
		require_once $map[ $class ];
	}
} );

register_activation_hook( __FILE__, [ 'LP_Activator', 'activate' ] );
register_deactivation_hook( __FILE__, [ 'LP_Activator', 'deactivate' ] );

function lp_init(): void {
	load_plugin_textdomain( 'langpress', false, dirname( LP_PLUGIN_BASENAME ) . '/languages' );

	LP_Database::instance();

	// Run dbDelta whenever the plugin version advances so new columns
	// are added automatically, with no manual deactivate/reactivate needed.
	$stored = get_option( 'lp_db_version', '0' );
	if ( version_compare( $stored, LP_VERSION, '<' ) && ! get_transient( 'lp_schema_retry' ) ) {
		// Before 1.4.0 the scan throttle created one transient per unique request
		// URL, so sites may carry thousands of them. They are only a cache.
		if ( version_compare( $stored, '1.4.0', '<' ) ) {
			lp_delete_transients( 'lp_scanned_' );
		}

		// Only record the new version once the schema really is current, so a
		// failed ALTER is retried instead of being forgotten. Retries are spaced
		// out so a database that keeps refusing doesn't slow every request.
		if ( LP_Database::instance()->install() ) {
			update_option( 'lp_db_version', LP_VERSION );
		} else {
			set_transient( 'lp_schema_retry', 1, HOUR_IN_SECONDS );
		}
	}

	LP_Translator::instance();
	LP_Language_Switcher::instance();
	LP_Frontend::instance();

	if ( is_admin() ) {
		LP_Admin::instance();
	}
}
add_action( 'plugins_loaded', 'lp_init' );

/**
 * Delete every transient whose name starts with $prefix (e.g. 'lp_scanned_').
 * With an external object cache, transients live there and simply expire.
 * uninstall.php runs without this file loaded and has its own copy.
 */
function lp_delete_transients( string $prefix ): void {
	global $wpdb;
	foreach ( [ '_transient_', '_transient_timeout_' ] as $kind ) {
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
				$wpdb->esc_like( $kind . $prefix ) . '%'
			)
		);
	}
}
