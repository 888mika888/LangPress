<?php
/**
 * Static checks on the plugin source tree.
 */

function lp_plugin_files( string $ext ): array {
	$dir   = dirname( __DIR__ ) . '/langpress';
	$files = [];
	$it    = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ) );
	foreach ( $it as $f ) {
		if ( $f->isFile() && strtolower( $f->getExtension() ) === $ext ) {
			$files[] = $f->getPathname();
		}
	}
	return $files;
}

lp_test( 'source: no PHP file has a BOM or output before <?php', function () {
	foreach ( lp_plugin_files( 'php' ) as $file ) {
		$head = file_get_contents( $file, false, null, 0, 5 );
		assert_same( '<?php', $head, basename( $file ) . ' must start with <?php' );
	}
} );

lp_test( 'source: no JS/CSS file has a BOM', function () {
	foreach ( array_merge( lp_plugin_files( 'js' ), lp_plugin_files( 'css' ) ) as $file ) {
		assert_true( file_get_contents( $file, false, null, 0, 3 ) !== "\xEF\xBB\xBF", basename( $file ) . ' has a BOM' );
	}
} );

lp_test( 'source: plugin header points at the LangPress repository', function () {
	$data = get_plugin_data( LP_PLUGIN_FILE, false, false );
	assert_same( 'https://github.com/888mika888/LangPress', $data['PluginURI'] );
	assert_same( LP_VERSION, $data['Version'], 'header and LP_VERSION must agree' );
} );

lp_test( 'source: admin.js toast never builds HTML from a message', function () {
	$js = file_get_contents( LP_PLUGIN_DIR . 'admin/admin.js' );
	preg_match( '/function showToast\s*\([^)]*\)\s*\{(.*?)\n    \}/s', $js, $m );
	assert_true( isset( $m[1] ), 'showToast not found' );
	assert_not_contains( '+ message', $m[1], 'message concatenated into markup' );
	assert_contains( '.text( message )', $m[1] );
} );

lp_test( 'source: public.js does not write the server-owned language cookie', function () {
	$js = file_get_contents( LP_PLUGIN_DIR . 'public/public.js' );
	assert_not_contains( 'document.cookie', $js );
} );
