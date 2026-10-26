<?php
/**
 * Measures the cost of auto-registering a content-heavy page.
 *
 * Usage: LP_WP_DIR=/path/to/disposable/wordpress php tests/bench-register.php [paragraphs]
 */

$_SERVER['HTTP_HOST']   = '127.0.0.1:8899';
$_SERVER['REQUEST_URI'] = '/';
require getenv( 'LP_WP_DIR' ) . '/wp-load.php';
require __DIR__ . '/lib.php';

$n    = (int) ( $argv[1] ?? 250 );
$html = '<!DOCTYPE html><html><head><title>Bench</title></head><body><main>';
for ( $i = 0; $i < $n; $i++ ) {
	$html .= "<p>Paragraph number $i with <strong>bold</strong> words.</p><ul><li>List item $i</li></ul>";
}
$html .= '</main></body></html>';

lp_reset();
$frontend = LP_Frontend::instance();
$m        = new ReflectionMethod( $frontend, 'extract_and_register_texts' );

foreach ( [ 'cold (empty table)', 'warm (all rows exist)' ] as $label ) {
	$start   = microtime( true );
	$queries = lp_count_queries( fn() => $m->invoke( $frontend, $html, LP_Translator::instance(), home_url( '/?p=1' ) ) );
	printf( "%-24s %5d strings  %6d queries  %8.1f ms  rows=%d\n", $label, $n * 2, $queries, ( microtime( true ) - $start ) * 1000, lp_count_rows() );
}
lp_reset();
