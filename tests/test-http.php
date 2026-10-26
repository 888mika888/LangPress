<?php
/**
 * End-to-end requests against the built-in web server (output_buffering=0, so
 * any stray output before headers is caught exactly like on a strict host).
 */

function lp_hello_world_id(): int {
	$posts = get_posts( [ 'name' => 'hello-world', 'post_type' => 'post', 'numberposts' => 1 ] );
	assert_true( ! empty( $posts ), 'default "Hello world!" post missing' );
	return $posts[0]->ID;
}

lp_test( 'http: ?lp_lang sets an HttpOnly language cookie server-side', function () {
	$r = lp_http( 'GET', '/?p=' . lp_hello_world_id() . '&lp_lang=en' );
	assert_same( 200, $r['status'] );
	assert_true( (bool) preg_match( '/^Set-Cookie: lp_language=en;[^\r\n]*HttpOnly/mi', $r['headers'] ), "no server-side cookie:\n" . $r['headers'] );
	assert_true( (bool) preg_match( '/^Set-Cookie: lp_language=en;[^\r\n]*SameSite=Lax/mi', $r['headers'] ) );
} );

lp_test( 'http: AJAX JSON responses have no leading bytes', function () {
	$r = lp_http( 'POST', '/wp-admin/admin-ajax.php', [], 'action=lp_switch_lang&lang=en' );
	assert_true( $r['body'] !== '' && $r['body'][0] === '{', 'body starts with ' . bin2hex( substr( $r['body'], 0, 4 ) ) );
} );

lp_test( 'http: forged Host header never reaches hreflang or switcher links', function () {
	update_option( 'lp_position_fixed', 1 );
	foreach ( [ '', '&lp_lang=en' ] as $lang ) {
		$r = lp_http( 'GET', '/?p=' . lp_hello_world_id() . $lang, [ 'Host' => 'evil.example', 'X-LP-Test-No-Canonical' => '1' ] );
		assert_contains( 'hreflang="x-default"', $r['body'] );
		assert_contains( 'lp-switcher__link', $r['body'] );
		assert_not_contains( 'evil.example', $r['body'], 'attacker host reflected' . $lang );
		assert_contains( 'href="http://' . LP_HTTP_HOST . ':' . LP_HTTP_PORT . '/', $r['body'] );
	}
	update_option( 'lp_position_fixed', 0 );
} );

lp_test( 'http: quotes and markup in the request target cannot break out of attributes', function () {
	update_option( 'lp_position_fixed', 1 );
	$r = lp_http( 'GET', '/?p=' . lp_hello_world_id() . '&x="><script>alert(1)</script>&y=\'onmouseover=\'alert(2)' );
	assert_not_contains( '<script>alert(1)', $r['body'] );
	assert_not_contains( "'onmouseover='", $r['body'] );
	assert_not_contains( '"onmouseover', $r['body'] );
	update_option( 'lp_position_fixed', 0 );
} );

lp_test( 'http: absolute-form request target cannot replace the site origin', function () {
	update_option( 'lp_position_fixed', 1 );
	$r = lp_http( 'GET', 'http://evil.example/?p=' . lp_hello_world_id(), [ 'X-LP-Test-No-Canonical' => '1' ] );
	update_option( 'lp_position_fixed', 0 );
	// Only LangPress output is checked: WordPress core's own cancel-comment-reply
	// link also echoes the raw request target, which is outside this plugin.
	preg_match_all( '/<link rel="alternate" hreflang="[^"]*" href="[^"]*"|<a href="[^"]*" class="lp-switcher__link"/', $r['body'], $m );
	assert_same( 7, count( $m[0] ), 'expected 4 hreflang + 3 switcher links' );
	foreach ( $m[0] as $tag ) {
		assert_not_contains( 'evil.example', $tag );
	}
} );

lp_test( 'http: percent-encoded query values survive in language URLs', function () {
	$r = lp_http( 'GET', '/?p=' . lp_hello_world_id() . '&q=%D0%BF%D1%80%D0%B8' );
	preg_match( '/<link rel="alternate" hreflang="en" href="([^"]*)"/', $r['body'], $m );
	assert_true( isset( $m[1] ), 'hreflang="en" missing' );
	assert_contains( 'q=%D0%BF%D1%80%D0%B8', $m[1], 'encoded octets stripped from hreflang' );
} );

lp_test( 'http: default-language page view registers its strings once', function () {
	$id = lp_hello_world_id();
	lp_http( 'GET', '/?p=' . $id );
	assert_true( lp_count_rows( "original_text = 'Hello world!' AND status = 'pending'" ) === 2, 'expected pending rows for en + uk' );
	assert_same( 1, lp_count_scan_transients(), 'one throttle entry for the page' );

	$rows = lp_count_rows();
	lp_http( 'GET', '/?p=' . $id );
	assert_same( $rows, lp_count_rows() );
	assert_same( 1, lp_count_scan_transients() );
} );

lp_test( 'http: unique query strings cannot create throttle entries or rescans', function () {
	$id = lp_hello_world_id();
	for ( $i = 0; $i < 15; $i++ ) {
		lp_http( 'GET', "/?p=$id&cachebust=$i" );
	}
	assert_true( lp_count_scan_transients() <= 1, lp_count_scan_transients() . ' throttle entries for one page' );
} );

lp_test( 'http: search terms are never registered as translatable strings', function () {
	lp_http( 'GET', '/?s=Zqxv+Injected+Phrase' );
	lp_http( 'GET', '/?s=Zqxv+Second+Phrase&paged=1' );
	assert_same( 0, lp_count_rows( "original_text LIKE '%Zqxv%'" ) );
} );

lp_test( 'http: 404 pages do not register strings', function () {
	lp_http( 'GET', '/?p=999999&junk=Zqxw' );
	lp_http( 'GET', '/?page_id=987654' );
	assert_same( 0, lp_count_scan_transients() );
} );

lp_test( 'http: POST requests to the front end are not scanned', function () {
	lp_http( 'POST', '/?p=' . lp_hello_world_id(), [], 'a=b' );
	assert_same( 0, lp_count_scan_transients() );
	assert_same( 0, lp_count_rows() );
} );

lp_test( 'http: admin bar text is not registered for logged-in admins', function () {
	$auth = lp_http_auth( 1, 'unused' );
	lp_http( 'GET', '/?p=' . lp_hello_world_id(), [ 'Cookie' => $auth['cookie'] ] );
	assert_true( lp_count_rows( "original_text = 'Hello world!'" ) > 0, 'page content should still be registered' );
	assert_same( 0, lp_count_rows( "original_text LIKE 'Howdy%' OR original_text = 'Log Out' OR original_text = 'Edit Post'" ) );
} );

lp_test( 'http: stored page_url always uses the configured site origin', function () {
	lp_http( 'GET', '/?p=' . lp_hello_world_id(), [ 'Host' => 'evil.example', 'X-LP-Test-No-Canonical' => '1' ] );
	global $wpdb;
	$urls = $wpdb->get_col( 'SELECT DISTINCT page_url FROM ' . lp_table() );
	assert_true( ! empty( $urls ), 'page was not scanned' );
	foreach ( $urls as $u ) {
		assert_true( strpos( $u, home_url( '/' ) ) === 0, "untrusted page_url $u" );
	}
} );

lp_test( 'http: auto-scan can be disabled', function () {
	update_option( 'lp_auto_scan', 0 );
	lp_http( 'GET', '/?p=' . lp_hello_world_id() );
	assert_same( 0, lp_count_rows() );
	delete_option( 'lp_auto_scan' );
} );

lp_test( 'http: saving a post re-enables scanning for that page', function () {
	$id = lp_hello_world_id();
	lp_http( 'GET', '/?p=' . $id );
	$post = get_post( $id );
	wp_update_post( [ 'ID' => $id, 'post_content' => $post->post_content . "\n<!-- wp:paragraph --><p>Freshly added sentence.</p><!-- /wp:paragraph -->" ] );
	lp_http( 'GET', '/?p=' . $id );
	assert_same( 2, lp_count_rows( "original_text = 'Freshly added sentence.'" ) );
	wp_update_post( [ 'ID' => $id, 'post_content' => $post->post_content ] );
} );

lp_test( 'http: anonymous requests to admin AJAX actions are refused without side effects', function () {
	LP_Database::instance()->upsert_translation( 'Keep me', 'en', 'Behalte mich', 'active' );
	global $wpdb;
	$id   = (int) $wpdb->get_var( 'SELECT id FROM ' . lp_table() . " WHERE original_text = 'Keep me'" );
	$hash = LP_Database::instance()->hash( 'Keep me' );

	$calls = [
		'lp_save_translation'      => "original=Evil&lang=en&translated=pwned",
		'lp_update_status'         => "id=$id&status=ignored",
		'lp_delete_translation'    => "hash=$hash",
		'lp_export'                => '',
		'lp_clear_cache'           => '',
		'lp_reinstall_db'          => '',
		'lp_get_translation'       => 'original=Keep+me',
		'lp_get_page_translations' => 'lang=en',
	];
	foreach ( $calls as $action => $body ) {
		$r = lp_http( 'POST', '/wp-admin/admin-ajax.php', [], "action=$action&nonce=x&$body" );
		assert_true( in_array( $r['status'], [ 400, 403 ], true ), "$action returned {$r['status']}" );
		assert_not_contains( 'Behalte', $r['body'], "$action leaked data" );
	}
	assert_same( 1, lp_count_rows() );
	assert_same( 'active', $wpdb->get_var( 'SELECT status FROM ' . lp_table() . " WHERE id = $id" ) );
} );

lp_test( 'http: admin export is valid JSON and includes page_url as data', function () {
	LP_Database::instance()->upsert_translation( 'Export <me>', 'en', 'Exported', 'active', home_url( '/?p=1' ) );
	$auth = lp_http_auth( 1, 'lp_admin_nonce' );
	$r    = lp_http( 'GET', '/wp-admin/admin-ajax.php?action=lp_export&nonce=' . $auth['nonce'], [ 'Cookie' => $auth['cookie'] ] );
	assert_same( 200, $r['status'] );
	assert_contains( 'Content-Type: application/json', $r['headers'] );
	assert_contains( 'X-Content-Type-Options: nosniff', $r['headers'] );
	$rows = json_decode( $r['body'], true );
	assert_true( is_array( $rows ) && count( $rows ) === 1, 'export body: ' . substr( $r['body'], 0, 80 ) );
	assert_same( 'Export <me>', $rows[0]['original_text'] );
} );

lp_test( 'http: language switch endpoint validates the language and sets the cookie', function () {
	$ok = lp_http( 'POST', '/wp-admin/admin-ajax.php', [], 'action=lp_switch_lang&lang=uk' );
	assert_same( 200, $ok['status'] );
	assert_true( (bool) preg_match( '/^Set-Cookie: lp_language=uk;[^\r\n]*HttpOnly/mi', $ok['headers'] ), $ok['headers'] );

	foreach ( [ 'fr', 'xx', '../en', 'en<script>' ] as $bad ) {
		$r = lp_http( 'POST', '/wp-admin/admin-ajax.php', [], 'action=lp_switch_lang&lang=' . rawurlencode( $bad ) );
		assert_not_contains( 'Set-Cookie: lp_language', $r['headers'], "cookie set for $bad" );
		assert_contains( '"success":false', $r['body'] );
	}
} );
