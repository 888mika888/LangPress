<?php
/**
 * Output escaping across every admin screen, export/import round-trips,
 * and proof that the release tooling rejects broken input.
 */

const LP_XSS_PAYLOADS = [
	'"><script>alert(1)</script>',
	"'><img src=x onerror=alert(2)>",
	'javascript:alert(3)',
	'</textarea><svg onload=alert(4)>',
];

lp_test( 'xss: hostile stored strings are escaped on every admin screen', function () {
	$db = LP_Database::instance();
	foreach ( LP_XSS_PAYLOADS as $i => $p ) {
		$db->upsert_translation( "Original $i $p", 'en', "Translated $i $p", 'active', 'https://example.test/?q=' . rawurlencode( $p ) );
		$db->upsert_translation( "Original $i $p", 'uk', '', 'pending', 'javascript:alert(9)' );
	}
	$page_id = wp_insert_post( [ 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => LP_XSS_PAYLOADS[0] ] );

	wp_set_current_user( 1 );
	$admin = LP_Admin::instance();
	try {
		foreach ( [ 'page_settings', 'page_languages', 'page_translations', 'page_design', 'page_import_export', 'page_debug' ] as $screen ) {
			ob_start();
			$admin->$screen();
			$html = ob_get_clean();
			assert_true( strlen( $html ) > 200, "$screen rendered nothing" );
			foreach ( [ '<script>alert(1)', '<img src=x onerror', '<svg onload', 'href="javascript:', "href='javascript:" ] as $sink ) {
				assert_not_contains( $sink, $html, "$screen" );
			}
		}
	} finally {
		wp_delete_post( $page_id, true );
		wp_set_current_user( 0 );
	}
	global $wpdb;
	assert_same( '', (string) $wpdb->get_var( 'SELECT page_url FROM ' . lp_table() . " WHERE language_code = 'uk' LIMIT 1" ), 'javascript: URL stored as page_url' );
} );

lp_test( 'xss: admin AJAX responses are JSON, never HTML', function () {
	LP_Database::instance()->upsert_translation( 'Payload', 'en', LP_XSS_PAYLOADS[0], 'active' );
	$auth = lp_http_auth( 1, 'lp_admin_nonce' );
	$r    = lp_http( 'POST', '/wp-admin/admin-ajax.php', [ 'Cookie' => $auth['cookie'] ], 'action=lp_get_translation&nonce=' . $auth['nonce'] . '&original=Payload' );
	assert_true( (bool) preg_match( '#^Content-Type: application/json#mi', $r['headers'] ), $r['headers'] );
	assert_same( LP_XSS_PAYLOADS[0], json_decode( $r['body'], true )['data']['translations']['en'] ?? null );
} );

lp_test( 'import/export: an export re-imports byte-for-byte', function () {
	$db   = LP_Database::instance();
	$rows = [
		[ 'Prices < 5 € & 100%AB off', 'en', 'Preise < 5 € & 100%AB Rabatt', 'active' ],
		[ 'Say "hi" to <b>everyone</b>', 'uk', 'Скажіть «привіт» усім 👋', 'active' ],
		[ "Line one\nLine two", 'en', "Zeile eins\nZeile zwei", 'ignored' ],
		[ 'Noch offen', 'en', '', 'pending' ],
	];
	foreach ( $rows as $i => [ $o, $l, $t, $s ] ) {
		$db->upsert_translation( $o, $l, $t, $s, home_url( "/?p=$i&x=%D0%B0" ) );
	}
	$snapshot = fn() => $GLOBALS['wpdb']->get_results( 'SELECT original_text, text_hash, language_code, translated_text, status, page_url FROM ' . lp_table() . ' ORDER BY text_hash, language_code', ARRAY_A );
	$before   = $snapshot();

	$auth   = lp_http_auth( 1, 'lp_admin_nonce' );
	$export = lp_http( 'GET', '/wp-admin/admin-ajax.php?action=lp_export&nonce=' . $auth['nonce'], [ 'Cookie' => $auth['cookie'] ] );
	assert_true( is_array( json_decode( $export['body'], true ) ), 'export is not valid JSON' );

	lp_reset();
	$msg = lp_import_json( $export['body'] );
	assert_contains( '4', $msg, $msg );
	assert_same( $before, $snapshot(), 'round-trip changed data' );

	// Importing the same file again changes nothing and corrupts nothing.
	lp_import_json( $export['body'] );
	assert_same( $before, $snapshot() );
} );

lp_test( 'import: a bad entry never changes existing translations', function () {
	LP_Database::instance()->upsert_translation( 'Keep', 'en', 'Behalten', 'active' );
	lp_import_json( wp_json_encode( [
		[ 'original_text' => 'Keep', 'language_code' => 'en', 'translated_text' => [ 'not', 'a', 'string' ] ],
		[ 'original_text' => 'Keep', 'language_code' => 'xx', 'translated_text' => 'Hijack' ],
		[ 'original_text' => 'Keep', 'language_code' => 'en' ],
	] ) );
	assert_same( 'Behalten', $GLOBALS['wpdb']->get_var( 'SELECT translated_text FROM ' . lp_table() . " WHERE language_code = 'en'" ) );
	assert_same( 1, lp_count_rows() );
} );

/** Run a repository script with bash; returns [exit code, output]. */
function lp_bash( array $args, ?string $cwd = null, array $env = [] ): array {
	$proc = proc_open( array_merge( [ 'bash' ], $args ), [ 1 => [ 'pipe', 'w' ], 2 => [ 'pipe', 'w' ] ], $pipes, $cwd ?? dirname( __DIR__ ), $env ? $env + getenv() : null );
	$out  = stream_get_contents( $pipes[1] ) . stream_get_contents( $pipes[2] );
	return [ proc_close( $proc ), $out ];
}

lp_test( 'tooling: check-package.sh rejects every kind of broken ZIP', function () {
	if ( ! class_exists( 'ZipArchive' ) ) {
		lp_fail( 'ZipArchive extension is required for this test' );
	}
	$dir  = sys_get_temp_dir() . '/lp-pkg-' . uniqid();
	mkdir( $dir );
	$good = "$dir/good.zip";
	[ $code, $out ] = lp_bash( [ 'bin/build-zip.sh', '--worktree' ], null, [ 'OUT' => $good ] );
	assert_same( 0, $code, $out );
	preg_match( '/from ([0-9a-f]{40})/', $out, $m );
	$tree = $m[1] ?? '';
	assert_true( $tree !== '', 'tree id not reported' );

	$variants = [
		'stale content'    => [ fn( ZipArchive $z ) => $z->addFromString( 'langpress/langpress.php', "<?php\n// edited after the build\n" ), 'differs' ],
		'BOM in PHP'       => [ fn( ZipArchive $z ) => $z->addFromString( 'langpress/uninstall.php', "\xEF\xBB\xBF" . $z->getFromName( 'langpress/uninstall.php' ) ), 'BOM' ],
		'backslash paths'  => [ fn( ZipArchive $z ) => $z->renameName( 'langpress/includes/class-lp-admin.php', 'langpress\\includes\\class-lp-admin.php' ), 'backslash' ],
		'missing file'     => [ fn( ZipArchive $z ) => $z->deleteName( 'langpress/uninstall.php' ), 'missing' ],
		'hidden directory' => [ fn( ZipArchive $z ) => $z->addFromString( 'langpress/.vscode/settings.json', '{}' ), 'development or local files' ],
		'secrets file'     => [ fn( ZipArchive $z ) => $z->addFromString( 'langpress/.env', 'DB_PASSWORD=x' ), 'development or local files' ],
		'wrong root'       => [ fn( ZipArchive $z ) => $z->addFromString( 'other/readme.txt', 'x' ), 'outside the langpress/ root' ],
	];
	try {
		[ $code, $out ] = lp_bash( [ 'bin/check-package.sh', $good, $tree ] );
		assert_same( 0, $code, "good package rejected: $out" );

		foreach ( $variants as $name => [ $mutate, $expect ] ) {
			$bad = "$dir/bad.zip";
			copy( $good, $bad );
			$z = new ZipArchive();
			$z->open( $bad );
			$mutate( $z );
			$z->close();
			[ $code, $out ] = lp_bash( [ 'bin/check-package.sh', $bad, $tree ] );
			assert_true( $code !== 0, "$name was accepted" );
			assert_contains( $expect, $out, $name );
		}
	} finally {
		array_map( 'unlink', glob( "$dir/*" ) );
		rmdir( $dir );
	}
} );

lp_test( 'tooling: check-source.sh rejects a BOM, leading output and a syntax error', function () {
	$dir = sys_get_temp_dir() . '/lp-src-' . uniqid();
	mkdir( "$dir/langpress", 0777, true );
	mkdir( "$dir/tests" );
	mkdir( "$dir/bin" );
	copy( dirname( __DIR__ ) . '/bin/check-source.sh', "$dir/bin/check-source.sh" );
	file_put_contents( "$dir/langpress/ok.php", "<?php\necho 1;\n" );
	$php = php_ini_loaded_file() ? PHP_BINARY . ' -c ' . php_ini_loaded_file() : PHP_BINARY;
	$wrapper = "$dir/php.sh";
	file_put_contents( $wrapper, "#!/bin/sh\nexec " . str_replace( '\\', '/', $php ) . " \"\$@\"\n" );
	lp_bash( [ '-c', 'git init -q . && chmod +x php.sh' ], $dir );
	try {
		[ $code, $out ] = lp_bash( [ 'bin/check-source.sh' ], $dir, [ 'PHP' => './php.sh' ] );
		assert_same( 0, $code, "clean fixture rejected: $out" );

		$bad = [
			'bom.php'     => [ "\xEF\xBB\xBF<?php\n", 'does not start with <?php' ],
			'leading.php' => [ "\n<?php\n", 'does not start with <?php' ],
			'syntax.php'  => [ "<?php\nfunction (\n", 'Parse error' ],
		];
		foreach ( $bad as $file => [ $content, $expect ] ) {
			file_put_contents( "$dir/langpress/$file", $content );
			[ $code, $out ] = lp_bash( [ 'bin/check-source.sh' ], $dir, [ 'PHP' => './php.sh' ] );
			unlink( "$dir/langpress/$file" );
			assert_true( $code !== 0, "$file was accepted" );
			assert_contains( $expect, $out, $file );
		}
		file_put_contents( "$dir/langpress/x.js", "\xEF\xBB\xBFvar a;" );
		[ $code, $out ] = lp_bash( [ 'bin/check-source.sh' ], $dir, [ 'PHP' => './php.sh' ] );
		assert_true( $code !== 0 && strpos( $out, 'BOM' ) !== false, 'JS BOM accepted' );
	} finally {
		lp_bash( [ '-c', 'rm -rf "$0"', $dir ] );
	}
} );
