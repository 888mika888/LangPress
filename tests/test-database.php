<?php
/**
 * Database layer: atomic upserts, schema cache, migrations.
 */

function lp_raw_insert( string $text, string $lang, string $translated = '' ): void {
	global $wpdb;
	$db = LP_Database::instance();
	$wpdb->insert( lp_table(), [
		'original_text'   => $text,
		'normalized_text' => $db->normalize( $text ),
		'text_hash'       => $db->hash( $text ),
		'language_code'   => $lang,
		'translated_text' => $translated,
		'status'          => $translated === '' ? 'pending' : 'active',
		'page_url'        => '',
		'created_at'      => '2026-01-01 00:00:00',
		'updated_at'      => '2026-01-01 00:00:00',
	] );
}

/**
 * Simulate a concurrent request that inserts the same (hash, language) row
 * after our code checked for it but before our own INSERT reaches the DB.
 */
function lp_with_concurrent_insert( string $text, string $lang, string $their_translation, callable $fn ) {
	$fired = false;
	$cb    = function ( $q ) use ( &$fired, $text, $lang, $their_translation ) {
		if ( ! $fired && preg_match( '/^\s*INSERT\b/i', $q ) && strpos( $q, lp_table() ) !== false ) {
			$fired = true;
			lp_raw_insert( $text, $lang, $their_translation );
		}
		return $q;
	};
	add_filter( 'query', $cb );
	try {
		return $fn();
	} finally {
		remove_filter( 'query', $cb );
	}
}

lp_test( 'db: concurrent first save of a translation is not lost', function () {
	global $wpdb;
	$ok = lp_with_concurrent_insert( 'Race text', 'en', '', function () {
		return LP_Database::instance()->upsert_translation( 'Race text', 'en', 'Race EN', 'active' );
	} );
	$wpdb->suppress_errors( false );
	assert_true( $ok, 'upsert reported failure on duplicate key' );
	assert_same( 1, lp_count_rows( "language_code = 'en'" ) );
	assert_same( 'Race EN', $wpdb->get_var( 'SELECT translated_text FROM ' . lp_table() ) );
	assert_same( 'active', $wpdb->get_var( 'SELECT status FROM ' . lp_table() ) );
} );

lp_test( 'db: concurrent registration never overwrites a translation', function () {
	global $wpdb;
	$ok = lp_with_concurrent_insert( 'Race two', 'en', 'Already translated', function () {
		return LP_Database::instance()->upsert_translation( 'Race two', 'en' );
	} );
	assert_true( $ok, 'registration reported failure on duplicate key' );
	assert_same( 1, lp_count_rows() );
	assert_same( 'Already translated', $wpdb->get_var( 'SELECT translated_text FROM ' . lp_table() ) );
} );

lp_test( 'db: upsert keeps id, created_at and page_url when updating', function () {
	global $wpdb;
	$db = LP_Database::instance();
	$db->upsert_translation( 'Stable row', 'en', '', 'pending', 'http://127.0.0.1:8899/?p=1', 7 );
	$before = $wpdb->get_row( 'SELECT * FROM ' . lp_table(), ARRAY_A );

	assert_true( $db->upsert_translation( 'Stable row', 'en', 'Stabile Zeile', 'active' ) );
	$after = $wpdb->get_row( 'SELECT * FROM ' . lp_table(), ARRAY_A );

	assert_same( $before['id'], $after['id'] );
	assert_same( $before['created_at'], $after['created_at'] );
	assert_same( 'http://127.0.0.1:8899/?p=1', $after['page_url'], 'page_url wiped by save' );
	assert_same( '7', (string) $after['post_id'], 'post_id wiped by save' );
	assert_same( 'Stabile Zeile', $after['translated_text'] );
	assert_same( 'active', $after['status'] );

	// Re-registering must be a no-op.
	assert_true( $db->upsert_translation( 'Stable row', 'en' ) );
	assert_same( 'Stabile Zeile', $wpdb->get_var( 'SELECT translated_text FROM ' . lp_table() ) );
} );

lp_test( 'db: column_exists caches negative results', function () {
	$db = LP_Database::instance();
	wp_cache_flush();
	$n = lp_count_queries( function () use ( $db ) {
		for ( $i = 0; $i < 5; $i++ ) {
			lp_call_private( $db, 'column_exists', [ 'definitely_missing_col' ] );
		}
	}, '/information_schema/i' );
	assert_same( 1, $n, 'information_schema queried repeatedly' );
	assert_same( false, lp_call_private( $db, 'column_exists', [ 'definitely_missing_col' ] ) );
	assert_same( true, lp_call_private( $db, 'column_exists', [ 'post_id' ] ) );
} );

lp_test( 'db: install() adds missing columns to a pre-1.2 table and keeps data', function () {
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
	$hash = LP_Database::instance()->hash( 'Legacy text' );
	$wpdb->query( $wpdb->prepare(
		"INSERT INTO $t (id, original_text, text_hash, language_code, translated_text, status) VALUES (42, 'Legacy text', %s, 'en', 'Old translation', 'active')",
		$hash
	) );
	wp_cache_flush();

	try {
		LP_Database::instance()->install();
		LP_Database::instance()->install(); // repeated upgrades must be safe

		$cols = $wpdb->get_col( "SHOW COLUMNS FROM $t" );
		assert_true( in_array( 'normalized_text', $cols, true ), 'normalized_text not added: ' . implode( ',', $cols ) );
		assert_true( in_array( 'post_id', $cols, true ), 'post_id not added' );

		$row = $wpdb->get_row( "SELECT * FROM $t WHERE id = 42", ARRAY_A );
		assert_same( 'Old translation', $row['translated_text'] );

		// The upgraded table must accept the current write path.
		assert_true( LP_Database::instance()->upsert_translation( 'New text', 'en', 'Neu', 'active', '', 3 ) );
		assert_same( 'Old translation', LP_Database::instance()->get_translation( $hash, 'en' ) );
	} finally {
		$wpdb->query( "DROP TABLE IF EXISTS $t" );
		wp_cache_flush();
		LP_Database::instance()->install();
	}
} );

lp_test( 'db: batch registration skips existing rows and stays bounded', function () {
	global $wpdb;
	$db = LP_Database::instance();
	$db->upsert_translation( 'Existing', 'en', 'Vorhanden', 'active' );

	$texts = [ 'Existing' ];
	for ( $i = 0; $i < 300; $i++ ) {
		$texts[] = "Sentence number $i";
	}
	$queries = lp_count_queries( function () use ( $texts ) {
		LP_Translator::instance()->register_texts( $texts, home_url( '/?p=1' ) );
	} );

	assert_same( 301 * 2, lp_count_rows() );
	assert_same( 'Vorhanden', $wpdb->get_var( 'SELECT translated_text FROM ' . lp_table() . " WHERE original_text = 'Existing' AND language_code = 'en'" ) );
	assert_true( $queries <= 10, "$queries queries for 301 strings x 2 languages" );

	// Second pass: nothing new to write.
	$queries = lp_count_queries( function () use ( $texts ) {
		LP_Translator::instance()->register_texts( $texts, home_url( '/?p=1' ) );
	}, '/^\s*INSERT/i' );
	assert_same( 0, $queries, 'no INSERTs expected on rescan' );
} );

lp_test( 'db: registration caps string count and length', function () {
	$texts = [ str_repeat( 'Very long text ', 2000 ) ];
	for ( $i = 0; $i < 3000; $i++ ) {
		$texts[] = "Bulk $i";
	}
	LP_Translator::instance()->register_texts( $texts, '' );
	assert_same( 0, lp_count_rows( "original_text LIKE 'Very long text%'" ), 'over-long string registered' );
	assert_true( lp_count_rows() <= LP_Translator::MAX_STRINGS_PER_SCAN * 2, lp_count_rows() . ' rows written' );
} );
