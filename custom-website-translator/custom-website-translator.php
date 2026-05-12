<?php
/**
 * Plugin Name: Custom Website Translator
 * Plugin URI:  https://github.com/888mika888/PluginWP
 * Description: Multilingual WordPress plugin with a visual translation editor. Supports German, English and Ukrainian without creating duplicate pages.
 * Version:     1.2.0
 * Author:      888mika888
 * Author URI:  https://github.com/888mika888
 * Text Domain: custom-website-translator
 * Domain Path: /languages
 * Requires at least: 6.0
 * Requires PHP: 8.1
 * License:     GPL v2 or later
 */

defined( 'ABSPATH' ) || exit;

define( 'CWT_VERSION',      '1.2.0' );
define( 'CWT_PLUGIN_FILE',  __FILE__ );
define( 'CWT_PLUGIN_DIR',   plugin_dir_path( __FILE__ ) );
define( 'CWT_PLUGIN_URL',   plugin_dir_url( __FILE__ ) );
define( 'CWT_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

// Simple class map autoloader — keeps the includes folder tidy.
spl_autoload_register( function ( string $class ): void {
	$map = [
		'CWT_Activator'         => CWT_PLUGIN_DIR . 'includes/class-cwt-activator.php',
		'CWT_Database'          => CWT_PLUGIN_DIR . 'includes/class-cwt-database.php',
		'CWT_Translator'        => CWT_PLUGIN_DIR . 'includes/class-cwt-translator.php',
		'CWT_Language_Switcher' => CWT_PLUGIN_DIR . 'includes/class-cwt-language-switcher.php',
		'CWT_Frontend'          => CWT_PLUGIN_DIR . 'includes/class-cwt-frontend.php',
		'CWT_Admin'             => CWT_PLUGIN_DIR . 'includes/class-cwt-admin.php',
	];
	if ( isset( $map[ $class ] ) ) {
		require_once $map[ $class ];
	}
} );

register_activation_hook( __FILE__, [ 'CWT_Activator', 'activate' ] );
register_deactivation_hook( __FILE__, [ 'CWT_Activator', 'deactivate' ] );

function cwt_init(): void {
	load_plugin_textdomain( 'custom-website-translator', false, dirname( CWT_PLUGIN_BASENAME ) . '/languages' );

	CWT_Database::instance();

	// Run dbDelta whenever the plugin version advances so new columns
	// are added automatically — no manual deactivate/reactivate needed.
	$stored = get_option( 'cwt_db_version', '0' );
	if ( version_compare( $stored, CWT_VERSION, '<' ) ) {
		CWT_Database::instance()->install();
		update_option( 'cwt_db_version', CWT_VERSION );
	}

	CWT_Translator::instance();
	CWT_Language_Switcher::instance();
	CWT_Frontend::instance();

	if ( is_admin() ) {
		CWT_Admin::instance();
	}
}
add_action( 'plugins_loaded', 'cwt_init' );
