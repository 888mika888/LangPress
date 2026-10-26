<?php
/**
 * Scan limits, schema migrations from every historical layout, schema cache,
 * and real (multi-process) concurrent writes.
 */

lp_test( 'db: scan limits are exact and long pages are completed by later scans', function () {
	$t = LP_Translator::instance();

	foreach ( [ 4999 => 1, 5000 => 1, 5001 => 0 ] as $len => $expected ) {
		lp_reset();
		$t->register_texts( [ str_repeat( 'ä', $len ) ] ); // multibyte: the limit counts characters
		assert_same( $expected, lp_count_rows( "language_code = 'en'" ), "length $len" );
	}

	lp_reset();
	$texts = [];
	for ( $i = 0; $i < 1000; $i++ ) {
		$texts[] = "Item $i";
	}
	assert_true( $t->register_texts( $texts ), 'exactly 1,000 new strings must complete in one scan' );
	assert_same( 1000, lp_count_rows( "language_code = 'en'" ) );

	lp_reset();
	$texts = [];
	for ( $i = 0; $i < 2500; $i++ ) {
		$texts[] = "Glossary entry $i";
	}
	$runs   = [];
	$counts = [];
	for ( $scan = 1; $scan <= 4; $scan++ ) {
		$runs[]   = $t->register_texts( $texts );
		$counts[] = lp_count_rows( "language_code = 'en'" );
	}
	assert_same( [ 1000, 2000, 2500, 2500 ], $counts, 'each scan adds at most 1,000 and later scans continue' );
	assert_same( [ false, false, true, true ], $runs );
	assert_same( 1, lp_count_rows( "original_text = 'Glossary entry 2499' AND language_code = 'uk'" ) );
} );

/** Create the translations table as it was before 1.2 (no normalized_text / post_id). */
function lp_create_pre_12_table(): void {
	global $wpdb;
	$t = lp_table();
	$wpdb->query( "DROP TABLE IF EXISTS $t" );
	$wpdb->query( "CREATE TABLE $t (
		id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
		original_text LONGTEXT NOT NULL,
		text_hash VARCHAR(64) NOT NULL,
		language_code VARCHAR(10) NOT NULL,
		translated_text LONGTEXT NOT NULL,
		status ENUM('active','ignored','pending') NOT NULL DEFAULT 'pending',
		page_url VARCHAR(2083) NOT NULL DEFAULT '',
		created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
		updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
		PRIMARY KEY (id),
		UNIQUE KEY hash_lang (text_hash(64), language_code)
	)" );
	wp_cache_flush();
}

/** Put the real schema back after a migration test. */
function lp_restore_schema(): void {
	global $wpdb;
	$wpdb->query( 'DROP TABLE IF EXISTS ' . lp_table() );
	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}cwt_translations" );
	wp_cache_flush();
	LP_Database::instance()->install();
	update_option( 'lp_db_version', LP_VERSION );
	delete_transient( 'lp_schema_retry' );
}

lp_test( 'db: a failed migration is not recorded as done and is retried', function () {
	global $wpdb;
	lp_create_pre_12_table();
	update_option( 'lp_db_version', '1.1.0' );
	delete_transient( 'lp_schema_retry' );

	// The database refuses the column change (permissions, lock timeout, disk full…).
	$refuse   = fn( $q ) => preg_match( '/ALTER TABLE \S*lp_translations ADD (COLUMN )?`?normalized_text/i', $q ) ? 'SELECT broken FROM lp_no_such_table' : $q;
	$suppress = $wpdb->suppress_errors( true );
	add_filter( 'query', $refuse );
	try {
		lp_init();
		assert_same( '1.1.0', get_option( 'lp_db_version' ), 'version recorded although the schema is incomplete' );
		assert_true( (bool) get_transient( 'lp_schema_retry' ), 'retries should be spaced out' );
		assert_true( ! LP_Database::instance()->schema_is_current() );

		lp_init(); // inside the retry window: no new attempt, still not marked done
		assert_same( '1.1.0', get_option( 'lp_db_version' ) );
	} finally {
		remove_filter( 'query', $refuse );
		$wpdb->suppress_errors( $suppress );
	}

	try {
		delete_transient( 'lp_schema_retry' ); // window passed; the database accepts the change now
		lp_init();
		assert_same( LP_VERSION, get_option( 'lp_db_version' ) );
		assert_true( LP_Database::instance()->schema_is_current() );
		assert_true( in_array( 'normalized_text', $wpdb->get_col( 'SHOW COLUMNS FROM ' . lp_table() ), true ) );
	} finally {
		lp_restore_schema();
	}
} );

lp_test( 'db: the oldest schema (cwt_translations, 1.0) migrates with its data', function () {
	global $wpdb;
	$new = lp_table();
	$old = $wpdb->prefix . 'cwt_translations';
	$wpdb->query( "DROP TABLE IF EXISTS $new" );
	$wpdb->query( "DROP TABLE IF EXISTS $old" );
	// As created by the initial commit (9e6cf7e), whose hash was sha256(trim(text)).
	$wpdb->query( "CREATE TABLE $old (
		id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
		original_text LONGTEXT NOT NULL,
		text_hash VARCHAR(64) NOT NULL,
		language_code VARCHAR(10) NOT NULL,
		translated_text LONGTEXT NOT NULL,
		status ENUM('active','ignored','pending') NOT NULL DEFAULT 'pending',
		page_url VARCHAR(2083) NOT NULL DEFAULT '',
		created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
		updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
		PRIMARY KEY (id),
		UNIQUE KEY hash_lang (text_hash(64), language_code),
		KEY status_idx (status),
		KEY language_idx (language_code)
	)" );
	$legacy = [
		[ 101, 'Kontakt', 'en', 'Contact' ],
		[ 102, 'Über  uns', 'en', 'About us' ], // double space: the old hash differs from today's
		[ 103, 'Привіт', 'uk', '' ],
	];
	foreach ( $legacy as [ $id, $orig, $lang, $tr ] ) {
		$wpdb->insert( $old, [
			'id'              => $id,
			'original_text'   => $orig,
			'text_hash'       => hash( 'sha256', trim( $orig ) ),
			'language_code'   => $lang,
			'translated_text' => $tr,
			'status'          => $tr === '' ? 'pending' : 'active',
			'page_url'        => 'http://example.test/old',
		] );
	}
	wp_cache_flush();

	try {
		assert_true( LP_Database::instance()->install(), 'install() reported an incomplete schema' );
		assert_true( LP_Database::instance()->install(), 'a second run must also succeed' );

		// MySQL/MariaDB rename the table; where that is impossible (SQLite driver)
		// the rows are copied and the old table is kept untouched as a backup.
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $old ) ) === $old ) {
			assert_same( 3, (int) $wpdb->get_var( "SELECT COUNT(*) FROM $old" ), 'backup table must be untouched' );
		}
		assert_same( 3, lp_count_rows(), 'rows must not be copied twice by the second install()' );
		$cols = $wpdb->get_col( "SHOW COLUMNS FROM $new" );
		foreach ( [ 'post_id', 'normalized_text', 'text_hash', 'page_url' ] as $c ) {
			assert_true( in_array( $c, $cols, true ), "missing column $c" );
		}
		$indexes = array_unique( wp_list_pluck( $wpdb->get_results( "SHOW INDEX FROM $new" ), 'Key_name' ) );
		foreach ( [ 'PRIMARY', 'hash_lang', 'status_idx', 'language_idx', 'post_id_idx' ] as $k ) {
			assert_true( in_array( $k, $indexes, true ), "missing index $k (have: " . implode( ',', $indexes ) . ')' );
		}

		$rows = $wpdb->get_results( "SELECT id, translated_text, page_url, normalized_text FROM $new ORDER BY id", ARRAY_A );
		assert_same( [ '101', '102', '103' ], array_column( $rows, 'id' ) );
		assert_same( [ 'Contact', 'About us', '' ], array_column( $rows, 'translated_text' ) );
		assert_same( 'http://example.test/old', $rows[0]['page_url'] );
		assert_same( null, $rows[0]['normalized_text'], 'added column is NULL on old rows' );

		// Old rows are still found: by hash, and via the original_text fallback for whitespace variants.
		$db = LP_Database::instance();
		assert_same( 'Contact', $db->get_translation( $db->hash( 'Kontakt' ), 'en' ) );
		assert_same( 'About us', $db->get_translation_by_original_text( 'Über  uns', 'en' ) );

		// The current write path works on the migrated table.
		assert_true( $db->upsert_translation( 'Kontakt', 'uk', 'Контакт', 'active', '', 5 ) );
		assert_same( 'Контакт', $db->get_translation( $db->hash( 'Kontakt' ), 'uk' ) );
	} finally {
		lp_restore_schema();
	}
} );

lp_test( 'db: column_exists uses the object cache across requests, for both answers', function () {
	$db   = LP_Database::instance();
	$memo = new ReflectionProperty( $db, 'columns' );
	wp_cache_flush();
	$memo->setValue( $db, [] );
	lp_call_private( $db, 'column_exists', [ 'post_id' ] );
	lp_call_private( $db, 'column_exists', [ 'nope_col' ] );

	$memo->setValue( $db, [] ); // a new request: only the (persistent) object cache remains
	$n = lp_count_queries( function () use ( $db ) {
		assert_same( true, lp_call_private( $db, 'column_exists', [ 'post_id' ] ) );
		assert_same( false, lp_call_private( $db, 'column_exists', [ 'nope_col' ] ) );
	}, '/information_schema/i' );
	assert_same( 0, $n, 'cached answers must not query information_schema' );

	// After a migration the cached answers are refreshed, not trusted.
	$n = lp_count_queries( fn() => $db->install(), '/information_schema/i' );
	assert_true( $n >= 1, 'install() must re-check the schema' );
} );

/** Run $script in $n parallel PHP processes that load the test WordPress; returns their outputs. */
function lp_parallel_php( string $script, int $n ): array {
	$cmd = [ PHP_BINARY ];
	if ( php_ini_loaded_file() ) {
		array_push( $cmd, '-c', php_ini_loaded_file() );
	}
	$boot  = '$_SERVER["HTTP_HOST"] = "127.0.0.1"; $_SERVER["REQUEST_URI"] = "/"; require getenv( "LP_WP_DIR" ) . "/wp-load.php";';
	$procs = [];
	$pipes = [];
	for ( $i = 0; $i < $n; $i++ ) {
		$procs[ $i ] = proc_open(
			array_merge( $cmd, [ '-r', $boot . str_replace( '{N}', (string) $i, $script ) ] ),
			[ 1 => [ 'pipe', 'w' ], 2 => [ 'pipe', 'w' ] ],
			$pipes[ $i ]
		);
	}
	$out = [];
	foreach ( $procs as $i => $p ) {
		$out[] = trim( stream_get_contents( $pipes[ $i ][1] ) . stream_get_contents( $pipes[ $i ][2] ) );
		proc_close( $p );
	}
	return $out;
}

lp_test( 'db: real concurrent writers (separate processes) keep one consistent row per string', function () {
	global $wpdb;
	// Six processes register and save the same 40 strings at the same time.
	$out = lp_parallel_php( '
		$db = LP_Database::instance(); $ok = 0;
		for ( $i = 0; $i < 40; $i++ ) {
			$ok += (int) $db->upsert_translation( "Shared $i", "en" );
			$ok += (int) $db->upsert_translation( "Shared $i", "en", "Writer {N} text $i", "active" );
		}
		echo $ok;', 6 );
	foreach ( $out as $o ) {
		assert_same( '80', $o, 'a writer saw failed upserts' );
	}
	assert_same( 40, lp_count_rows() );
	$dupes = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM (SELECT text_hash FROM ' . lp_table() . ' GROUP BY text_hash, language_code HAVING COUNT(*) > 1) d' );
	assert_same( 0, $dupes );
	assert_same( 40, lp_count_rows( "status = 'active' AND translated_text LIKE 'Writer % text %'" ), 'every row holds one complete save' );
} );
