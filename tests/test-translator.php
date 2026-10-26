<?php
/**
 * Server-side HTML translation.
 */

function lp_translate_with( array $pairs, string $html, string $lang = 'en' ): string {
	foreach ( $pairs as $original => $translated ) {
		LP_Database::instance()->upsert_translation( $original, $lang, $translated, 'active' );
	}
	LP_Translator::instance()->invalidate_cache();
	lp_set_lang( $lang );
	return LP_Translator::instance()->translate_html( $html );
}

lp_test( 'translator: combined block translation keeps embedded media', function () {
	$cases = [
		'iframe' => '<iframe src="https://example.test/embed"></iframe>',
		'video'  => '<video src="https://example.test/v.mp4" controls></video>',
		'audio'  => '<audio src="https://example.test/a.mp3"></audio>',
		'svg'    => '<svg viewBox="0 0 10 10"><circle r="4"></circle></svg>',
		'object' => '<object data="https://example.test/x.pdf"></object>',
		'embed'  => '<embed src="https://example.test/x.swf">',
		'canvas' => '<canvas id="c"></canvas>',
	];
	foreach ( $cases as $tag => $media ) {
		lp_reset();
		$out = lp_translate_with(
			[ 'Watch this clip now' => 'Schau dir diesen Clip an' ],
			"<p>Watch this <em>clip</em> now $media</p>"
		);
		assert_contains( "<$tag", $out, "$tag removed" );
	}
} );

lp_test( 'translator: inline formatting is rebuilt around the translation', function () {
	$out = lp_translate_with(
		[ 'Read the manual today' => 'Lies heute das manual' ],
		'<p>Read the <strong class="x">manual</strong> today</p>'
	);
	assert_contains( '<strong class="x">manual</strong>', $out );
	assert_contains( 'Lies heute das', $out );
} );

lp_test( 'translator: per-node translation and untouched script/style', function () {
	$out = lp_translate_with(
		[ 'Hello' => 'Hallo', 'World' => 'Welt' ],
		'<div><span>Hello</span><b>World</b><script>var s="Hello";</script><style>.Hello{}</style></div>'
	);
	assert_contains( '<span>Hallo</span>', $out );
	assert_contains( '<b>Welt</b>', $out );
	assert_contains( 'var s="Hello";', $out );
	assert_contains( '.Hello{}', $out );
} );

lp_test( 'translator: translated text is inserted as text, never markup', function () {
	$out = lp_translate_with(
		[ 'Safe text' => '<img src=x onerror=alert(1)>"&' ],
		'<p>Safe text</p>'
	);
	assert_not_contains( '<img', $out );
	assert_contains( '&lt;img src=x onerror=alert(1)&gt;', $out );
} );

lp_test( 'translator: texts with < and %XX survive the editor save and match the page', function () {
	$original = 'Prices < 5 € & 100%AB off';
	$res = lp_ajax( 'lp_save_translation', [
		'nonce'    => ( function () { wp_set_current_user( 1 ); return wp_create_nonce( 'lp_admin_nonce' ); } )(),
		'original' => $original,
		'en'       => 'Preise < 5 € & 100%AB Rabatt',
	], 1 );
	assert_true( ! empty( $res['json']['success'] ), 'save failed: ' . $res['body'] );

	LP_Translator::instance()->invalidate_cache();
	lp_set_lang( 'en' );
	$out = LP_Translator::instance()->translate_html( '<p>Prices &lt; 5 € &amp; 100%AB off</p>' );
	assert_contains( 'Preise &lt; 5 € &amp; 100%AB Rabatt', $out );
} );

lp_test( 'translator: registration skips the admin bar and editor sidebar', function () {
	$frontend = LP_Frontend::instance();
	$html     = '<html><body><div id="wpadminbar"><a>Howdy, admin</a><span>Edit Post</span></div>'
			  . '<div id="lp-editor-sidebar" translate="no"><p>Translation Editor</p></div>'
			  . '<div class="lp-switcher" translate="no"><span>Deutsch</span></div>'
			  . '<main><p>Real content here</p></main></body></html>';
	$texts = lp_call_private( $frontend, 'collect_page_texts', [ $html ] );
	assert_true( in_array( 'Real content here', $texts, true ) );
	assert_true( ! in_array( 'Howdy, admin', $texts, true ), 'admin bar text collected' );
	assert_true( ! in_array( 'Edit Post', $texts, true ) );
	assert_true( ! in_array( 'Translation Editor', $texts, true ) );
	assert_true( ! in_array( 'Deutsch', $texts, true ) );

	// The real switcher markup must carry the opt-out.
	assert_contains( 'translate="no"', LP_Language_Switcher::instance()->render( true ) );
} );

lp_test( 'translator: translated full pages keep their doctype and no parser marker', function () {
	$page = "<!DOCTYPE html>\n<html lang=\"de\"><head><meta charset=\"UTF-8\"><title>Grüße</title>"
		  . '<script>var s = "Привіт <b>";</script></head><body><p>Hallo Welt</p></body></html>';
	$out = lp_translate_with( [ 'Hallo Welt' => 'Привіт, світе' ], $page );
	assert_same( 0, strpos( $out, '<!DOCTYPE html>' ), 'doctype must stay first: ' . substr( $out, 0, 40 ) );
	assert_not_contains( '<?xml', $out );
	assert_contains( '<p>Привіт, світе</p>', $out );
	assert_contains( '<title>Grüße</title>', $out );
	assert_contains( 'var s = "Привіт <b>";', $out, 'inline script must be byte-identical' );
} );

lp_test( 'translator: combined fallback keeps icons, empty elements and translate="no" parts', function () {
	$cases = [
		[ '<p><i class="fa fa-download"></i> Download the <strong>guide</strong> now</p>', 'Download the guide now', 'Lade jetzt die Anleitung', 'class="fa fa-download"' ],
		[ '<p><span class="badge"></span>New <b>feature</b> released</p>', 'New feature released', 'Neue Funktion', 'class="badge"' ],
		[ '<p>Call <span translate="no">ACME</span> <em>today</em></p>', 'Call today', 'Ruf heute an', '<span translate="no">ACME</span>' ],
	];
	foreach ( $cases as [ $html, $orig, $translated, $kept ] ) {
		lp_reset();
		$out = lp_translate_with( [ $orig => $translated ], $html );
		assert_contains( $kept, $out, 'element lost' );
		assert_contains( $translated, $out, 'translation not applied' );
		assert_not_contains( 'Download the', $out );
	}
} );

lp_test( 'translator: <br> blocks are matched per line like the visual editor saves them', function () {
	$out = lp_translate_with( [ 'Line one' => 'Zeile eins', 'Line two' => 'Zeile zwei' ], '<p>Line one<br>Line two</p>' );
	assert_contains( '<p>Zeile eins<br>Zeile zwei</p>', $out );
	$texts = lp_call_private( LP_Frontend::instance(), 'collect_page_texts', [ '<html><body><p>Line one<br>Line two</p></body></html>' ] );
	assert_same( [ 'Line one', 'Line two' ], $texts );
} );

lp_test( 'translator: each target language gets its own translation', function () {
	LP_Database::instance()->upsert_translation( 'Welcome', 'en', 'Welcome EN', 'active' );
	LP_Database::instance()->upsert_translation( 'Welcome', 'uk', 'Ласкаво просимо', 'active' );
	foreach ( [ 'en' => 'Welcome EN', 'uk' => 'Ласкаво просимо' ] as $lang => $expected ) {
		LP_Translator::instance()->invalidate_cache();
		lp_set_lang( $lang );
		assert_contains( "<h2>$expected</h2>", LP_Translator::instance()->translate_html( '<h2>Welcome</h2>' ) );
	}
} );
