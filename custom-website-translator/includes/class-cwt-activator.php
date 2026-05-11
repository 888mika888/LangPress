<?php
defined( 'ABSPATH' ) || exit;

/**
 * Aktivierungs- und Deaktivierungslogik.
 */
class CWT_Activator {

    /**
     * Wird bei Plugin-Aktivierung ausgeführt.
     */
    public static function activate(): void {
        require_once CWT_PLUGIN_DIR . 'includes/class-cwt-database.php';
        CWT_Database::instance()->install();
        self::set_default_settings();
        flush_rewrite_rules();
    }

    /**
     * Wird bei Plugin-Deaktivierung ausgeführt.
     */
    public static function deactivate(): void {
        flush_rewrite_rules();
    }

    /**
     * Standardeinstellungen setzen – nur wenn noch nicht vorhanden.
     */
    private static function set_default_settings(): void {
        $defaults = [
            'cwt_default_language'   => 'de',
            'cwt_active_languages'   => [ 'de', 'en', 'uk' ],
            'cwt_switcher_position'  => 'bottom-right',
            'cwt_switcher_display'   => 'all',      // all | specific | exclude
            'cwt_switcher_pages'     => [],
            'cwt_switcher_style'     => 'dropdown', // dropdown | buttons
            'cwt_display_mode'       => 'text',     // text | flag | both
            'cwt_bg_color'           => '#ffffff',
            'cwt_text_color'         => '#333333',
            'cwt_border_color'       => '#cccccc',
            'cwt_hover_color'        => '#f0f0f0',
            'cwt_border_radius'      => '4',
            'cwt_font_size'          => '14',
            'cwt_padding'            => '8',
            'cwt_position_fixed'     => false,
            'cwt_version'            => CWT_VERSION,
        ];

        foreach ( $defaults as $key => $value ) {
            if ( false === get_option( $key ) ) {
                add_option( $key, $value );
            }
        }
    }
}
