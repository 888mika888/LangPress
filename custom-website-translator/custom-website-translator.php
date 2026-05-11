<?php
/**
 * Plugin Name: Custom Website Translator
 * Plugin URI:  https://example.com/custom-website-translator
 * Description: Professionelles mehrsprachiges Übersetzungs-Plugin mit manueller Verwaltung. Unterstützt Deutsch, Englisch und Ukrainisch ohne Seitenduplikate.
 * Version:     1.0.0
 * Author:      Your Name
 * Author URI:  https://example.com
 * Text Domain: custom-website-translator
 * Domain Path: /languages
 * Requires at least: 6.0
 * Requires PHP: 8.1
 * License:     GPL v2 or later
 */

defined( 'ABSPATH' ) || exit;

// Plugin-Konstanten
define( 'CWT_VERSION',     '1.0.0' );
define( 'CWT_PLUGIN_FILE', __FILE__ );
define( 'CWT_PLUGIN_DIR',  plugin_dir_path( __FILE__ ) );
define( 'CWT_PLUGIN_URL',  plugin_dir_url( __FILE__ ) );
define( 'CWT_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

// Autoloader
spl_autoload_register( function ( string $class ): void {
    $prefix = 'CWT_';
    if ( strncmp( $class, $prefix, strlen( $prefix ) ) !== 0 ) {
        return;
    }
    $map = [
        'CWT_Activator'        => CWT_PLUGIN_DIR . 'includes/class-cwt-activator.php',
        'CWT_Database'         => CWT_PLUGIN_DIR . 'includes/class-cwt-database.php',
        'CWT_Translator'       => CWT_PLUGIN_DIR . 'includes/class-cwt-translator.php',
        'CWT_Language_Switcher'=> CWT_PLUGIN_DIR . 'includes/class-cwt-language-switcher.php',
        'CWT_Frontend'         => CWT_PLUGIN_DIR . 'includes/class-cwt-frontend.php',
        'CWT_Admin'            => CWT_PLUGIN_DIR . 'includes/class-cwt-admin.php',
    ];
    if ( isset( $map[ $class ] ) ) {
        require_once $map[ $class ];
    }
} );

// Aktivierungs- / Deaktivierungs-Hooks
register_activation_hook( __FILE__, [ 'CWT_Activator', 'activate' ] );
register_deactivation_hook( __FILE__, [ 'CWT_Activator', 'deactivate' ] );

/**
 * Plugin-Bootstrap – wird nach 'plugins_loaded' ausgeführt.
 */
function cwt_init(): void {
    // Textdomain laden
    load_plugin_textdomain(
        'custom-website-translator',
        false,
        dirname( CWT_PLUGIN_BASENAME ) . '/languages'
    );

    // Kernklassen initialisieren
    CWT_Database::instance();
    CWT_Translator::instance();
    CWT_Language_Switcher::instance();
    CWT_Frontend::instance();

    if ( is_admin() ) {
        CWT_Admin::instance();
    }
}
add_action( 'plugins_loaded', 'cwt_init' );
