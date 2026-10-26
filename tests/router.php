<?php
/**
 * Router for PHP's built-in web server so pretty permalinks work in tests
 * (the equivalent of WordPress's .htaccess rewrite rules).
 */
$path = (string) parse_url( $_SERVER['REQUEST_URI'], PHP_URL_PATH );
if ( $path !== '/' && is_file( $_SERVER['DOCUMENT_ROOT'] . rawurldecode( $path ) ) ) {
	return false;
}
$_SERVER['SCRIPT_NAME']     = '/index.php';
$_SERVER['SCRIPT_FILENAME'] = $_SERVER['DOCUMENT_ROOT'] . '/index.php';
require $_SERVER['DOCUMENT_ROOT'] . '/index.php';
