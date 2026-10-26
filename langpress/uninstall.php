<?php
/**
 * Runs when LangPress is deleted from the Plugins screen (not on deactivation).
 *
 * Always removed:  the page-scan throttle transients (a disposable cache) and
 *                  the schema-upgrade retry flag.
 * Removed only if "Delete all translations and LangPress settings when the
 * plugin is deleted" is enabled under LangPress → Settings:
 *   - the {prefix}lp_translations and {prefix}lp_settings tables
 *   - the LangPress options listed below (by exact name, never by wildcard,
 *     because other plugins also use the "lp_" prefix)
 *
 * Translations are kept by default so that reinstalling the plugin restores
 * them. On multisite the same rules are applied to every site, each according
 * to its own setting.
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

$lp_uninstall_site = static function (): void {
	global $wpdb;

	foreach ( [ 'lp_scan_', 'lp_scanned_' ] as $prefix ) {
		foreach ( [ '_transient_', '_transient_timeout_' ] as $kind ) {
			$wpdb->query(
				$wpdb->prepare(
					"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
					$wpdb->esc_like( $kind . $prefix ) . '%'
				)
			);
		}
	}

	delete_transient( 'lp_schema_retry' );

	if ( ! get_option( 'lp_delete_data_on_uninstall' ) ) {
		return;
	}

	// phpcs:disable WordPress.DB.DirectDatabaseQuery.SchemaChange
	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}lp_translations" );
	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}lp_settings" );
	// phpcs:enable

	$options = [
		'lp_default_language', 'lp_active_languages',
		'lp_switcher_position', 'lp_switcher_display', 'lp_switcher_pages', 'lp_switcher_style',
		'lp_display_mode', 'lp_position_fixed',
		'lp_bg_color', 'lp_text_color', 'lp_border_color', 'lp_hover_color',
		'lp_border_radius', 'lp_font_size', 'lp_padding',
		'lp_dark_mode', 'lp_auto_scan', 'lp_delete_data_on_uninstall',
		'lp_version', 'lp_db_version',
	];
	foreach ( $options as $option ) {
		delete_option( $option );
	}
};

if ( is_multisite() ) {
	foreach ( get_sites( [ 'fields' => 'ids', 'number' => 0 ] ) as $lp_site_id ) {
		switch_to_blog( $lp_site_id );
		$lp_uninstall_site();
		restore_current_blog();
	}
} else {
	$lp_uninstall_site();
}
