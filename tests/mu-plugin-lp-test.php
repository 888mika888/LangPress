<?php
/**
 * Test-only must-use plugin, copied into the disposable WordPress install by
 * bin/setup-test-wp.sh. Never install this on a real site.
 *
 * WordPress core's redirect_canonical() already bounces some forged-Host
 * requests, but not every page, theme or configuration gets that redirect.
 * This header lets the suite prove LangPress is safe on its own.
 */
if ( isset( $_SERVER['HTTP_X_LP_TEST_NO_CANONICAL'] ) ) {
	add_action( 'init', function () {
		remove_action( 'template_redirect', 'redirect_canonical' );
	} );
}
