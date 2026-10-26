<?php
/**
 * More end-to-end request tests: throttle key bounds, real concurrency,
 * pretty permalinks with non-ASCII slugs, translated page integrity, cookies.
 */

lp_test( 'http: routing-only query variations cannot multiply scans of one post', function () {
	$id = lp_hello_world_id();
	foreach ( [ 'paged', 'cpage', 'orderby', 'order', 'cat' ] as $var ) {
		for ( $i = 1; $i <= 8; $i++ ) {
			lp_http( 'GET', "/?p=$id&$var=" . ( $i * 3 ) );
		}
	}
	assert_same( 1, lp_count_scan_transients(), '40 variations of one post must share one throttle entry' );
} );

lp_test( 'http: archive pages beyond the last page are 404 and never scanned', function () {
	foreach ( [ 2, 50, 999 ] as $paged ) {
		$r = lp_http( 'GET', "/?paged=$paged" );
		assert_same( 404, $r['status'] );
	}
	assert_same( 0, lp_count_scan_transients() );
} );

lp_test( 'http: concurrent first visits from several server processes scan once, cleanly', function () {
	$id    = lp_hello_world_id();
	$ports = [ LP_HTTP_PORT + 11, LP_HTTP_PORT + 12, LP_HTTP_PORT + 13 ];
	$extra = array_map( 'lp_start_server', $ports );
	try {
		$socks = [];
		foreach ( array_merge( [ LP_HTTP_PORT ], $ports ) as $port ) {
			foreach ( [ 1, 2 ] as $unused ) {
				$s = stream_socket_client( 'tcp://' . LP_HTTP_HOST . ":$port", $errno, $errstr, 10 );
				fwrite( $s, "GET /?p=$id HTTP/1.0\r\nHost: " . LP_HTTP_HOST . ':' . LP_HTTP_PORT . "\r\n\r\n" );
				$socks[] = $s;
			}
		}
		foreach ( $socks as $s ) {
			stream_set_timeout( $s, 60 );
			assert_same( '200', substr( stream_get_contents( $s ), 9, 3 ) );
			fclose( $s );
		}
	} finally {
		array_map( 'proc_terminate', $extra );
	}
	global $wpdb;
	$dupes = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM (SELECT text_hash FROM ' . lp_table() . ' GROUP BY text_hash, language_code HAVING COUNT(*) > 1) d' );
	assert_same( 0, $dupes, 'duplicate rows' );
	assert_true( lp_count_rows( "original_text = 'Hello world!'" ) === 2, 'page not registered' );
	assert_same( 1, lp_count_scan_transients() );
} );

lp_test( 'http: Cyrillic permalinks keep their encoded path in hreflang and switcher links', function () {
	global $wp_rewrite;
	$wp_rewrite->set_permalink_structure( '/%postname%/' );
	flush_rewrite_rules();
	update_option( 'lp_position_fixed', 1 );
	$pid = wp_insert_post( [ 'post_title' => 'Привіт світ', 'post_status' => 'publish', 'post_content' => '<p>Тест</p>' ] );
	try {
		$path = wp_parse_url( get_permalink( $pid ), PHP_URL_PATH );
		assert_contains( '%d0%bf', $path, 'expected a percent-encoded slug' );

		$r = lp_http( 'GET', $path . '?q=%D0%B0' );
		assert_same( 200, $r['status'] );
		preg_match( '/hreflang="uk" href="([^"]*)"/', $r['body'], $m );
		assert_same( home_url( $path ) . '?q=%D0%B0&#038;lp_lang=uk', $m[1] ?? '' );
		preg_match( '/<a href="([^"]*lp_lang=en[^"]*)" class="lp-switcher__link"/', $r['body'], $m );
		assert_same( home_url( $path ) . '?q=%D0%B0&#038;lp_lang=en', $m[1] ?? '' );

		// Following the link really shows that post in English.
		$r = lp_http( 'GET', wp_parse_url( html_entity_decode( $m[1] ), PHP_URL_PATH ) . '?q=%D0%B0&lp_lang=en' );
		assert_same( 200, $r['status'] );
		assert_contains( '<html lang="en"', $r['body'] );
		assert_contains( 'Привіт світ', $r['body'] );
	} finally {
		wp_delete_post( $pid, true );
		update_option( 'lp_position_fixed', 0 );
		$wp_rewrite->set_permalink_structure( '' );
		flush_rewrite_rules();
	}
} );

lp_test( 'http: translated pages are served in standards mode (doctype first, no parser marker)', function () {
	LP_Database::instance()->upsert_translation( 'Hello world!', 'en', 'Hello world (EN)!', 'active' );
	$r = lp_http( 'GET', '/?p=' . lp_hello_world_id() . '&lp_lang=en' );
	assert_contains( 'Hello world (EN)!', $r['body'], 'translation not applied' );
	assert_same( 0, stripos( $r['body'], '<!DOCTYPE html>' ), 'starts with: ' . substr( $r['body'], 0, 40 ) );
	assert_not_contains( '<?xml', $r['body'] );
} );

lp_test( 'http: language cookie is Secure on HTTPS and drives later page views', function () {
	$id = lp_hello_world_id();
	$r  = lp_http( 'GET', "/?p=$id&lp_lang=en", [ 'X-LP-Test-HTTPS' => '1' ] );
	assert_true( (bool) preg_match( '/^Set-Cookie: lp_language=en;[^\r\n]*; secure; HttpOnly; SameSite=Lax/mi', $r['headers'] ), $r['headers'] );

	$r = lp_http( 'GET', "/?p=$id", [ 'Cookie' => 'lp_language=uk' ] );
	assert_contains( '<html lang="uk"', $r['body'], 'cookie language not applied' );

	// Back to the default language, as the switcher does it: AJAX reset, then the clean URL.
	$r = lp_http( 'POST', '/wp-admin/admin-ajax.php', [ 'Cookie' => 'lp_language=uk' ], 'action=lp_switch_lang&lang=de' );
	assert_true( (bool) preg_match( '/^Set-Cookie: lp_language=de;/mi', $r['headers'] ) );
	$r = lp_http( 'GET', "/?p=$id", [ 'Cookie' => 'lp_language=de' ] );
	assert_not_contains( '<html lang="uk"', $r['body'] );
	assert_not_contains( '<html lang="en"', $r['body'] );

	// Garbage cookies are ignored and never echoed back.
	$r = lp_http( 'GET', "/?p=$id", [ 'Cookie' => 'lp_language=uk%22%3E%3Cscript%3E' ] );
	assert_not_contains( '<html lang="uk', $r['body'] );
	assert_not_contains( 'Set-Cookie: lp_language', $r['headers'] );

	// Logged-in visitors use the same public endpoint.
	$auth = lp_http_auth( 1, 'unused' );
	$r    = lp_http( 'POST', '/wp-admin/admin-ajax.php', [ 'Cookie' => $auth['cookie'] ], 'action=lp_switch_lang&lang=uk' );
	assert_contains( '"success":true', $r['body'] );
} );
