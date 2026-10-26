<?php
/**
 * Minimal, dependency-free test helpers for the LangPress integration suite.
 * Tests run inside a disposable WordPress install; never point this at a real site.
 */

class LP_Test_Failure extends Exception {}

/** Thrown by the wp_die handler so AJAX handlers can be exercised in-process. */
class LP_Test_Die extends Exception {
	public int $status;
	public function __construct( string $message, int $status ) {
		parent::__construct( $message );
		$this->status = $status;
	}
}

$GLOBALS['lp_tests'] = [];

function lp_test( string $name, callable $fn ): void {
	$GLOBALS['lp_tests'][ $name ] = $fn;
}

function lp_fail( string $msg ): void {
	throw new LP_Test_Failure( $msg );
}

function assert_true( $cond, string $msg = 'expected true' ): void {
	if ( ! $cond ) {
		lp_fail( $msg );
	}
}

function assert_same( $expected, $actual, string $msg = '' ): void {
	if ( $expected !== $actual ) {
		lp_fail( ( $msg ? $msg . ': ' : '' ) . 'expected ' . var_export( $expected, true ) . ', got ' . var_export( $actual, true ) );
	}
}

function assert_contains( string $needle, string $haystack, string $msg = '' ): void {
	if ( strpos( $haystack, $needle ) === false ) {
		lp_fail( ( $msg ? $msg . ': ' : '' ) . 'missing ' . var_export( $needle, true ) );
	}
}

function assert_not_contains( string $needle, string $haystack, string $msg = '' ): void {
	if ( strpos( $haystack, $needle ) !== false ) {
		lp_fail( ( $msg ? $msg . ': ' : '' ) . 'unexpected ' . var_export( $needle, true ) );
	}
}

// -----------------------------------------------------------------------------
// WordPress state helpers
// -----------------------------------------------------------------------------

function lp_table(): string {
	return LP_Database::instance()->get_table_translations();
}

/** Remove all LangPress rows and scan transients so each test starts clean. */
function lp_reset(): void {
	global $wpdb;
	$wpdb->query( 'DELETE FROM ' . lp_table() );
	foreach ( [ '_transient_lp_scan', '_transient_timeout_lp_scan' ] as $prefix ) {
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( $prefix ) . '%' ) );
	}
	wp_cache_flush();
	update_option( 'lp_default_language', 'de' );
	update_option( 'lp_active_languages', [ 'de', 'en', 'uk' ] );
	delete_option( 'lp_auto_scan' );
	lp_set_lang( 'de' );
}

function lp_count_rows( string $where = '1=1' ): int {
	global $wpdb;
	return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . lp_table() . " WHERE $where" );
}

function lp_count_scan_transients(): int {
	global $wpdb;
	return (int) $wpdb->get_var(
		$wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( '_transient_lp_scan' ) . '%' )
	);
}

/** Force the translator singleton into a language (it normally detects once per request). */
function lp_set_lang( string $lang ): void {
	$t = LP_Translator::instance();
	$r = new ReflectionObject( $t );
	foreach ( [ 'current_language' => $lang, 'cache' => [], 'cache_loaded' => false ] as $prop => $val ) {
		$p = $r->getProperty( $prop );
		$p->setValue( $t, $val );
	}
}

function lp_call_private( object $obj, string $method, array $args = [] ) {
	$m = new ReflectionMethod( $obj, $method );
	return $m->invokeArgs( $obj, $args );
}

/** Count SQL queries matching $pattern while running $fn. */
function lp_count_queries( callable $fn, string $pattern = '/./' ): int {
	$n = 0;
	$cb = function ( $q ) use ( &$n, $pattern ) {
		if ( preg_match( $pattern, $q ) ) {
			$n++;
		}
		return $q;
	};
	add_filter( 'query', $cb );
	try {
		$fn();
	} finally {
		remove_filter( 'query', $cb );
	}
	return $n;
}

function lp_user( string $role ): int {
	$login = 'lp_test_' . $role;
	$user  = get_user_by( 'login', $login );
	if ( $user ) {
		return $user->ID;
	}
	return (int) wp_insert_user( [
		'user_login' => $login,
		'user_pass'  => wp_generate_password( 24 ),
		'user_email' => $login . '@example.test',
		'role'       => $role,
	] );
}

/**
 * Run a wp_ajax_* action in-process as the given user.
 * Returns [ 'status' => int, 'body' => string, 'json' => mixed ].
 */
function lp_ajax( string $action, array $post, int $user_id, bool $nopriv = false ): array {
	wp_set_current_user( $user_id );
	$_POST    = $post;
	$_REQUEST = $post;
	$_GET     = [];

	ob_start();
	$status = 200;
	try {
		do_action( ( $nopriv ? 'wp_ajax_nopriv_' : 'wp_ajax_' ) . $action );
		$body = ob_get_clean();
		$status = 0; // handler returned without dying, treat as "no response sent"
	} catch ( LP_Test_Die $e ) {
		$body   = ob_get_clean();
		$status = $e->status;
		if ( $body === '' ) {
			$body = $e->getMessage();
		}
	}
	$_POST = $_REQUEST = [];
	wp_set_current_user( 0 );
	return [ 'status' => $status, 'body' => $body, 'json' => json_decode( $body, true ) ];
}

// -----------------------------------------------------------------------------
// HTTP helpers (PHP built-in server pointed at the same disposable install)
// -----------------------------------------------------------------------------

/**
 * Start PHP's built-in web server on $port for the test install, with
 * output_buffering off (strict about output before headers) and the
 * pretty-permalink router. Returns the process handle once it accepts connections.
 *
 * @return resource
 */
function lp_start_server( int $port ) {
	$cmd = [ PHP_BINARY ];
	if ( php_ini_loaded_file() ) {
		array_push( $cmd, '-c', php_ini_loaded_file() );
	}
	array_push( $cmd, '-d', 'output_buffering=0', '-S', LP_HTTP_HOST . ':' . $port, '-t', getenv( 'LP_WP_DIR' ), __DIR__ . '/router.php' );
	$log  = sys_get_temp_dir() . '/lp-test-server.log';
	$proc = proc_open( $cmd, [ [ 'pipe', 'r' ], [ 'file', $log, 'a' ], [ 'file', $log, 'a' ] ], $pipes );
	for ( $i = 0; $i < 50; $i++ ) {
		$fp = @fsockopen( LP_HTTP_HOST, $port, $e, $s, 0.2 );
		if ( $fp ) {
			fclose( $fp );
			break;
		}
		usleep( 100000 );
	}
	return $proc;
}

/**
 * Send a raw HTTP/1.0 request so headers like Host and the request target are
 * passed through unmodified (curl would normalize or reject some of them).
 *
 * @return array{status:int, headers:string, body:string}
 */
function lp_http( string $method, string $target, array $headers = [], string $body = '' ): array {
	$fp = fsockopen( LP_HTTP_HOST, LP_HTTP_PORT, $errno, $errstr, 10 );
	if ( ! $fp ) {
		lp_fail( "cannot connect to test server: $errstr" );
	}
	$headers += [ 'Host' => LP_HTTP_HOST . ':' . LP_HTTP_PORT ];
	if ( $body !== '' ) {
		$headers += [ 'Content-Type' => 'application/x-www-form-urlencoded' ];
		$headers['Content-Length'] = (string) strlen( $body );
	}
	$req = "$method $target HTTP/1.0\r\n";
	foreach ( $headers as $k => $v ) {
		$req .= "$k: $v\r\n";
	}
	fwrite( $fp, $req . "\r\n" . $body );
	stream_set_timeout( $fp, 60 );
	$raw = stream_get_contents( $fp );
	fclose( $fp );

	[ $head, $resp_body ] = array_pad( explode( "\r\n\r\n", $raw, 2 ), 2, '' );
	preg_match( '#^HTTP/\S+ (\d+)#', $head, $m );
	return [ 'status' => (int) ( $m[1] ?? 0 ), 'headers' => $head, 'body' => $resp_body ];
}

/** Build a logged-in cookie header + matching nonce for a user, for HTTP tests. */
function lp_http_auth( int $user_id, string $nonce_action ): array {
	$manager    = WP_Session_Tokens::get_instance( $user_id );
	$expiration = time() + HOUR_IN_SECONDS;
	$token      = $manager->create( $expiration );
	$logged_in  = wp_generate_auth_cookie( $user_id, $expiration, 'logged_in', $token );
	$auth       = wp_generate_auth_cookie( $user_id, $expiration, 'auth', $token );

	$_COOKIE[ LOGGED_IN_COOKIE ] = $logged_in;
	wp_set_current_user( $user_id );
	$nonce = wp_create_nonce( $nonce_action );
	unset( $_COOKIE[ LOGGED_IN_COOKIE ] );
	wp_set_current_user( 0 );

	return [
		'cookie' => LOGGED_IN_COOKIE . '=' . rawurlencode( $logged_in ) . '; ' . AUTH_COOKIE . '=' . rawurlencode( $auth ),
		'nonce'  => $nonce,
	];
}
