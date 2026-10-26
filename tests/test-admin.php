<?php
/**
 * Capability boundaries, design-option validation, import, and uninstall.
 */

lp_test( 'admin: only administrators can use any LangPress admin AJAX action', function () {
	global $wpdb;
	$db = LP_Database::instance();
	$db->upsert_translation( 'Guarded', 'en', 'Bewacht', 'active' );
	$id   = (int) $wpdb->get_var( 'SELECT id FROM ' . lp_table() );
	$hash = $db->hash( 'Guarded' );

	$actions = [
		'lp_save_translation'      => [ 'original' => 'Guarded', 'lang' => 'en', 'translated' => 'pwned' ],
		'lp_update_status'         => [ 'id' => $id, 'status' => 'ignored' ],
		'lp_delete_translation'    => [ 'hash' => $hash ],
		'lp_export'                => [],
		'lp_clear_cache'           => [],
		'lp_reinstall_db'          => [],
		'lp_get_translation'       => [ 'original' => 'Guarded' ],
		'lp_get_page_translations' => [ 'lang' => 'en' ],
	];

	foreach ( [ 'subscriber', 'contributor', 'author', 'editor' ] as $role ) {
		$uid = lp_user( $role );
		wp_set_current_user( $uid );
		$nonce = wp_create_nonce( 'lp_admin_nonce' ); // even a valid nonce must not help
		foreach ( $actions as $action => $post ) {
			$r = lp_ajax( $action, $post + [ 'nonce' => $nonce ], $uid );
			assert_true( empty( $r['json']['success'] ), "$role succeeded at $action" );
			assert_not_contains( 'Bewacht', $r['body'], "$role read data via $action" );
		}
	}

	// Administrator without a nonce is refused too (CSRF).
	foreach ( $actions as $action => $post ) {
		$r = lp_ajax( $action, $post, 1 );
		assert_same( 403, $r['status'], "admin without nonce at $action" );
	}

	$row = $wpdb->get_row( 'SELECT * FROM ' . lp_table(), ARRAY_A );
	assert_same( 1, lp_count_rows() );
	assert_same( 'Bewacht', $row['translated_text'] );
	assert_same( 'active', $row['status'] );
} );

lp_test( 'admin: administrator actions work with a valid nonce', function () {
	global $wpdb;
	wp_set_current_user( 1 );
	$nonce = wp_create_nonce( 'lp_admin_nonce' );

	$r = lp_ajax( 'lp_save_translation', [ 'nonce' => $nonce, 'original' => 'Admin text', 'lang' => 'en', 'translated' => 'Admin EN' ], 1 );
	assert_true( ! empty( $r['json']['success'] ), $r['body'] );

	$r = lp_ajax( 'lp_get_translation', [ 'nonce' => $nonce, 'original' => 'Admin text' ], 1 );
	assert_same( 'Admin EN', $r['json']['data']['translations']['en'] ?? null );

	$id = (int) $wpdb->get_var( 'SELECT id FROM ' . lp_table() );
	$r  = lp_ajax( 'lp_update_status', [ 'nonce' => $nonce, 'id' => $id, 'status' => 'ignored' ], 1 );
	assert_true( ! empty( $r['json']['success'] ) );

	$r = lp_ajax( 'lp_delete_translation', [ 'nonce' => $nonce, 'hash' => LP_Database::instance()->hash( 'Admin text' ) ], 1 );
	assert_true( ! empty( $r['json']['success'] ) );
	assert_same( 0, lp_count_rows() );
} );

lp_test( 'admin: save rejects languages that are not active targets', function () {
	wp_set_current_user( 1 );
	$nonce = wp_create_nonce( 'lp_admin_nonce' );
	foreach ( [ 'de', 'fr', 'zzzzzzzzzzzz' ] as $lang ) {
		$r = lp_ajax( 'lp_save_translation', [ 'nonce' => $nonce, 'original' => 'Lang check', 'lang' => $lang, 'translated' => 'x' ], 1 );
		assert_true( empty( $r['json']['success'] ), "accepted $lang" );
	}
	assert_same( 0, lp_count_rows() );
} );

lp_test( 'admin: delete requires a well-formed hash', function () {
	wp_set_current_user( 1 );
	$nonce = wp_create_nonce( 'lp_admin_nonce' );
	$r     = lp_ajax( 'lp_delete_translation', [ 'nonce' => $nonce, 'hash' => "' OR 1=1 --" ], 1 );
	assert_true( empty( $r['json']['success'] ) );
} );

lp_test( 'switcher: malformed stored design options cannot inject CSS', function () {
	$bad = [
		'lp_bg_color'      => 'red;background-image:url(https://evil.test/x)',
		'lp_text_color'    => '#fff" onmouseover="alert(1)',
		'lp_border_color'  => 'expression(alert(1))',
		'lp_hover_color'   => '#12345',
		'lp_border_radius' => '4px;position:fixed',
		'lp_font_size'     => '999',
		'lp_padding'       => '-5',
		'lp_switcher_style'    => 'dropdown evil',
		'lp_switcher_position' => 'x" onclick="y',
	];
	$saved = [];
	foreach ( $bad as $k => $v ) {
		$saved[ $k ] = get_option( $k );
		update_option( $k, $v );
	}
	update_option( 'lp_position_fixed', 1 );
	try {
		$html = LP_Language_Switcher::instance()->render();
		foreach ( [ 'evil.test', 'url(', 'expression', 'position:fixed', 'onmouseover', 'onclick', '999px', '-5px', 'evil' ] as $needle ) {
			assert_not_contains( $needle, $html );
		}
		assert_contains( '--lp-bg:#ffffff', $html );
		assert_contains( '--lp-font-size:32px', $html );
		assert_contains( '--lp-padding:0px', $html );
		assert_contains( 'lp-switcher--dropdown', $html );
	} finally {
		foreach ( $saved as $k => $v ) {
			false === $v ? delete_option( $k ) : update_option( $k, $v );
		}
		update_option( 'lp_position_fixed', 0 );
	}
} );

lp_test( 'switcher: public endpoint works for visitors without a nonce (page-cache safe)', function () {
	$r = lp_ajax( 'lp_switch_lang', [ 'lang' => 'en' ], 0, true );
	assert_true( ! empty( $r['json']['success'] ), $r['body'] );
	$r = lp_ajax( 'lp_switch_lang', [ 'lang' => 'zz' ], 0, true );
	assert_true( empty( $r['json']['success'] ) );
	$r = lp_ajax( 'lp_switch_lang', [ 'lang' => [ 'en' ] ], 0, true );
	assert_true( empty( $r['json']['success'] ) );
} );

/** Run the import handler against an in-memory JSON document. */
function lp_import_json( string $json ): string {
	$tmp = wp_tempnam( 'lp-import' );
	file_put_contents( $tmp, $json );
	wp_set_current_user( 1 );
	$_POST  = [ 'lp_do_import' => '1', '_wpnonce' => wp_create_nonce( 'lp_import_nonce' ) ];
	$_FILES = [ 'lp_import_file' => [ 'name' => 'x.json', 'tmp_name' => $tmp, 'error' => UPLOAD_ERR_OK, 'size' => strlen( $json ) ] ];
	$GLOBALS['wp_settings_errors'] = [];
	LP_Admin::instance()->handle_form_submissions();
	$_POST = $_FILES = [];
	@unlink( $tmp );
	wp_set_current_user( 0 );
	return implode( ' | ', wp_list_pluck( get_settings_errors( 'lp' ), 'message' ) );
}

lp_test( 'admin: import validates every entry', function () {
	global $wpdb;
	$msg = lp_import_json( wp_json_encode( [
		[ 'original_text' => 'Imported ok', 'language_code' => 'en', 'translated_text' => 'Importiert', 'status' => 'active' ],
		[ 'original_text' => [ 'array' ], 'language_code' => 'en', 'translated_text' => 'x' ],
		[ 'original_text' => '', 'language_code' => 'en', 'translated_text' => 'empty original' ],
		[ 'original_text' => 'Bad lang', 'language_code' => 'zz-evil', 'translated_text' => 'x' ],
		[ 'original_text' => 'Default lang', 'language_code' => 'de', 'translated_text' => 'x' ],
		[ 'original_text' => 'Weird status', 'language_code' => 'uk', 'translated_text' => 'y', 'status' => [ 'x' ] ],
		'not an object',
		[ 'original_text' => 'Html <b>kept</b> as text', 'language_code' => 'en', 'translated_text' => '<script>alert(1)</script>' ],
	] ) );
	assert_contains( '3', $msg, "import message: $msg" );
	assert_same( 3, lp_count_rows() );
	assert_same( 'active', $wpdb->get_var( 'SELECT status FROM ' . lp_table() . " WHERE original_text = 'Weird status'" ) );

	// Script text is stored as data and rendered as escaped text.
	LP_Translator::instance()->invalidate_cache();
	lp_set_lang( 'en' );
	$out = LP_Translator::instance()->translate_html( '<p>Html &lt;b&gt;kept&lt;/b&gt; as text</p>' );
	assert_contains( '&lt;script&gt;', $out );
	assert_not_contains( '<script>', $out );
} );

lp_test( 'admin: import refuses non-JSON documents', function () {
	$msg = lp_import_json( 'not json at all' );
	assert_same( 0, lp_count_rows() );
	assert_true( $msg !== '', 'no error shown' );
} );

/**
 * Run uninstall.php in a child process (the way WordPress does when a plugin
 * is deleted) against the disposable test database, report what is left,
 * then restore the plugin's tables and defaults for the remaining tests.
 */
function lp_run_uninstall( bool $delete_data ): array {
	update_option( 'lp_delete_data_on_uninstall', $delete_data ? 1 : 0 );
	update_option( 'lp_unrelated_option_keep', 'yes' ); // shares the lp_ prefix but is not ours
	update_option( 'lp_bg_color', '#123456' );
	set_transient( 'lp_scan_' . md5( 'x' ), 1, 60 );
	set_transient( 'lp_scanned_' . md5( 'legacy' ), 1, 60 );
	LP_Database::instance()->upsert_translation( 'Survivor', 'en', 'Überlebender', 'active' );

	$script = <<<'PHP'
$_SERVER['HTTP_HOST'] = '127.0.0.1'; $_SERVER['REQUEST_URI'] = '/';
require getenv( 'LP_WP_DIR' ) . '/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';
uninstall_plugin( 'langpress/langpress.php' );
global $wpdb;
wp_cache_flush();
echo json_encode( [
	'tables'    => $wpdb->get_col( "SHOW TABLES LIKE '{$wpdb->prefix}lp\\_%'" ),
	'bg'        => get_option( 'lp_bg_color', null ),
	'unrelated' => get_option( 'lp_unrelated_option_keep', null ),
	'blogname'  => get_option( 'blogname', null ),
	'transient' => get_transient( 'lp_scan_' . md5( 'x' ) ),
	'legacy'    => get_transient( 'lp_scanned_' . md5( 'legacy' ) ),
	'posts'     => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts}" ),
] );
PHP;
	$cmd = [ PHP_BINARY ];
	if ( php_ini_loaded_file() ) {
		array_push( $cmd, '-c', php_ini_loaded_file() );
	}
	array_push( $cmd, '-r', $script );
	$proc = proc_open( $cmd, [ 1 => [ 'pipe', 'w' ], 2 => [ 'pipe', 'w' ] ], $pipes );
	$out  = stream_get_contents( $pipes[1] );
	$err  = stream_get_contents( $pipes[2] );
	proc_close( $proc );

	// Restore what the child removed.
	wp_cache_flush();
	LP_Database::instance()->install();
	LP_Activator::activate();
	delete_option( 'lp_unrelated_option_keep' );
	delete_option( 'lp_delete_data_on_uninstall' );
	wp_cache_flush();

	$state = json_decode( trim( $out ), true );
	if ( ! is_array( $state ) ) {
		lp_fail( "uninstall child failed: $out $err" );
	}
	return $state;
}

lp_test( 'uninstall: keeps translations and settings by default', function () {
	$s = lp_run_uninstall( false );
	assert_true( in_array( lp_table(), $s['tables'], true ), 'translations table dropped without opt-in' );
	assert_same( '#123456', $s['bg'] );
	assert_same( false, $s['transient'], 'scan transients should always be cleared' );
	assert_same( false, $s['legacy'], 'pre-1.4 scan transients should always be cleared' );
	assert_same( 'yes', $s['unrelated'] );
} );

lp_test( 'uninstall: removes only LangPress data when opted in', function () {
	$s = lp_run_uninstall( true );
	assert_same( [], $s['tables'] );
	assert_same( null, $s['bg'] );
	assert_same( false, $s['transient'] );
	assert_same( 'yes', $s['unrelated'], 'unrelated lp_ option deleted' );
	assert_same( 'LP Test', $s['blogname'] );
	assert_true( $s['posts'] > 0, 'posts table touched' );
} );
