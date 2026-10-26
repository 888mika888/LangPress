<?php
/**
 * LangPress integration test runner.
 *
 * Usage:  LP_WP_DIR=/path/to/disposable/wordpress php tests/run.php [filter]
 *
 * The WordPress install must have LangPress active (see bin/setup-test-wp.sh).
 * Tests mutate the database: only ever use a throwaway install.
 */

$wp_dir = getenv( 'LP_WP_DIR' );
if ( ! $wp_dir || ! is_file( $wp_dir . '/wp-load.php' ) ) {
	fwrite( STDERR, "Set LP_WP_DIR to a disposable WordPress install (see bin/setup-test-wp.sh).\n" );
	exit( 2 );
}

define( 'LP_HTTP_HOST', '127.0.0.1' );
define( 'LP_HTTP_PORT', (int) ( getenv( 'LP_HTTP_PORT' ) ?: 8899 ) );

$_SERVER['HTTP_HOST']      = LP_HTTP_HOST . ':' . LP_HTTP_PORT;
$_SERVER['SERVER_NAME']    = LP_HTTP_HOST;
$_SERVER['REQUEST_URI']    = '/';
$_SERVER['REQUEST_METHOD'] = 'GET';

require $wp_dir . '/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';
require_once ABSPATH . 'wp-admin/includes/file.php';
require_once ABSPATH . 'wp-admin/includes/template.php';
require __DIR__ . '/lib.php';

if ( ! class_exists( 'LP_Database' ) ) {
	fwrite( STDERR, "LangPress is not active in $wp_dir.\n" );
	exit( 2 );
}

// LP_Admin only boots inside wp-admin; its AJAX handlers are what we test.
LP_Admin::instance();

add_filter( 'wp_doing_ajax', '__return_true' );
add_filter( 'wp_die_ajax_handler', fn() => function ( $message, $title = '', $args = [] ) {
	$status = is_array( $args ) && isset( $args['response'] ) ? (int) $args['response'] : 200;
	throw new LP_Test_Die( is_scalar( $message ) ? (string) $message : '', $status );
} );

foreach ( glob( __DIR__ . '/test-*.php' ) as $file ) {
	require $file;
}

// Start a built-in web server for the HTTP-level tests.
$server = null;
if ( getenv( 'LP_NO_HTTP' ) !== '1' ) {
	$cmd    = [ PHP_BINARY, '-d', 'output_buffering=0', '-S', LP_HTTP_HOST . ':' . LP_HTTP_PORT, '-t', $wp_dir ];
	$ini    = php_ini_loaded_file();
	if ( $ini ) {
		array_splice( $cmd, 1, 0, [ '-c', $ini ] );
	}
	$server = proc_open( $cmd, [ [ 'pipe', 'r' ], [ 'file', sys_get_temp_dir() . '/lp-test-server.log', 'a' ], [ 'file', sys_get_temp_dir() . '/lp-test-server.log', 'a' ] ], $pipes, null, null );
	for ( $i = 0; $i < 50; $i++ ) {
		$fp = @fsockopen( LP_HTTP_HOST, LP_HTTP_PORT, $e, $s, 0.2 );
		if ( $fp ) {
			fclose( $fp );
			break;
		}
		usleep( 100000 );
	}
}

$filter = $argv[1] ?? '';
$passed = $failed = 0;

foreach ( $GLOBALS['lp_tests'] as $name => $fn ) {
	if ( $filter !== '' && stripos( $name, $filter ) === false ) {
		continue;
	}
	lp_reset();
	try {
		$fn();
		$passed++;
		echo "PASS  $name\n";
	} catch ( Throwable $e ) {
		$failed++;
		$where = $e instanceof LP_Test_Failure ? '' : ' [' . get_class( $e ) . ' @ ' . basename( $e->getFile() ) . ':' . $e->getLine() . ']';
		echo "FAIL  $name\n      " . $e->getMessage() . $where . "\n";
	}
}

lp_reset();
if ( $server ) {
	proc_terminate( $server );
}

echo "\n$passed passed, $failed failed\n";
exit( $failed ? 1 : 0 );
