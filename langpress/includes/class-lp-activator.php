<?php
defined( 'ABSPATH' ) || exit;

class LP_Activator {

	public static function activate(): void {
		require_once LP_PLUGIN_DIR . 'includes/class-lp-database.php';
		LP_Database::instance()->install();
		self::set_default_settings();
		flush_rewrite_rules();
	}

	public static function deactivate(): void {
		flush_rewrite_rules();
	}

	private static function set_default_settings(): void {
		$defaults = [
			'lp_default_language'  => 'de',
			'lp_active_languages'  => [ 'de', 'en', 'uk' ],
			'lp_switcher_position' => 'bottom-right',
			'lp_switcher_display'  => 'all',       // all | specific | exclude
			'lp_switcher_pages'    => [],
			'lp_switcher_style'    => 'dropdown',  // dropdown | buttons
			'lp_display_mode'      => 'text',      // text | flag | both
			'lp_bg_color'          => '#ffffff',
			'lp_text_color'        => '#333333',
			'lp_border_color'      => '#cccccc',
			'lp_hover_color'       => '#f0f0f0',
			'lp_border_radius'     => '4',
			'lp_font_size'         => '14',
			'lp_padding'           => '8',
			'lp_position_fixed'    => false,
			'lp_version'           => LP_VERSION,
		];

		foreach ( $defaults as $key => $value ) {
			if ( false === get_option( $key ) ) {
				add_option( $key, $value );
			}
		}
	}
}
