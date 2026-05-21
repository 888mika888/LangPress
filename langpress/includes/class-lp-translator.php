<?php
defined( 'ABSPATH' ) || exit;

/**
 * Handles language detection, the in-memory translation cache, and
 * the DOM-based text replacement that runs on every page request.
 */
class LP_Translator {

	private static ?self $instance = null;

	/**
	 * Block-level elements we attempt to translate as a single combined unit.
	 * This allows paragraphs containing inline tags like <strong> or <em> to be
	 * matched against the hash that was saved from the element's full innerText.
	 */
	public const BLOCK_TAGS = [
		'p', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6',
		'li', 'blockquote', 'figcaption', 'dt', 'dd', 'td', 'th',
	];

	/**
	 * If any of these tags appear inside a block candidate we abandon the combined
	 * lookup and fall back to per-node translation, to avoid accidentally flattening
	 * links, buttons, images, or nested block structure.
	 */
	private const BLOCK_BAIL_TAGS = [
		'p', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6',
		'ul', 'ol', 'li', 'table', 'thead', 'tbody', 'tr', 'td', 'th',
		'div', 'section', 'article', 'header', 'footer', 'nav', 'main',
		'blockquote', 'form', 'fieldset',
		'a', 'button', 'img', 'input', 'select',
	];

	/** hash => translated_text, keyed by language code */
	private array $cache = [];

	private bool $cache_loaded = false;

	private string $current_language = 'de';

	private function __construct() {
		$this->current_language = $this->detect_language();
	}

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Determine the active language.
	 * Priority: URL param ?lp_lang → cookie → site default.
	 */
	public function detect_language(): string {
		$active  = get_option( 'lp_active_languages', [ 'de', 'en', 'uk' ] );
		$default = get_option( 'lp_default_language', 'de' );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['lp_lang'] ) ) {
			$lang = sanitize_key( wp_unslash( $_GET['lp_lang'] ) );
			if ( in_array( $lang, $active, true ) ) {
				$this->set_language_cookie( $lang );
				return $lang;
			}
		}

		if ( isset( $_COOKIE['lp_language'] ) ) {
			$lang = sanitize_key( wp_unslash( $_COOKIE['lp_language'] ) );
			if ( in_array( $lang, $active, true ) ) {
				return $lang;
			}
		}

		return $default;
	}

	public function set_language_cookie( string $lang ): void {
		if ( headers_sent() ) {
			return;
		}
		setcookie( 'lp_language', $lang, [
			'expires'  => time() + ( 30 * DAY_IN_SECONDS ),
			'path'     => COOKIEPATH ?: '/',
			'domain'   => COOKIE_DOMAIN ?: '',
			'secure'   => is_ssl(),
			'httponly' => true,
			'samesite' => 'Lax',
		] );
	}

	public function get_current_language(): string {
		return $this->current_language;
	}

	public function is_default_language(): bool {
		return $this->current_language === get_option( 'lp_default_language', 'de' );
	}

	/**
	 * Translate a plain text string. Falls back to the original if no translation exists.
	 * Preserves any leading/trailing whitespace the DOM gave us.
	 */
	public function translate( string $text ): string {
		if ( $this->is_default_language() || trim( $text ) === '' ) {
			return $text;
		}

		$this->load_cache();

		$hash = LP_Database::instance()->hash( trim( $text ) );
		$lang = $this->current_language;

		if ( ! empty( $this->cache[ $lang ][ $hash ] ) ) {
			$leading  = strlen( $text ) - strlen( ltrim( $text ) );
			$trailing = strlen( $text ) - strlen( rtrim( $text ) );

			// Re-attach any whitespace that was trimmed before hashing.
			return substr( $text, 0, $leading )
				 . $this->cache[ $lang ][ $hash ]
				 . substr( $text, strlen( $text ) - $trailing );
		}

		return $text;
	}

	/**
	 * Run translations over a full HTML string.
	 * Only visible text nodes are touched — attributes, scripts, and styles are left alone.
	 */
	public function translate_html( string $html ): string {
		if ( $this->is_default_language() || trim( $html ) === '' ) {
			return $html;
		}

		$this->load_cache();

		if ( empty( $this->cache[ $this->current_language ] ) ) {
			return $html;
		}

		$use_errors = libxml_use_internal_errors( true );

		$doc = new DOMDocument( '1.0', 'UTF-8' );
		// The xml encoding PI convinces libxml to handle UTF-8 correctly
		// without adding a <meta charset> to the output.
		$doc->loadHTML( '<?xml encoding="UTF-8">' . $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD );

		libxml_clear_errors();
		libxml_use_internal_errors( $use_errors );

		$this->walk_dom( $doc );

		$result = '';
		foreach ( $doc->childNodes as $child ) {
			$result .= $doc->saveHTML( $child );
		}

		// Strip the xml PI that loadHTML added at the top.
		return preg_replace( '/<\?xml[^>]*\?>\n?/', '', $result ) ?? $html;
	}

	/**
	 * Recursively walk DOM nodes and replace text node values.
	 * For block-level elements we first attempt a combined lookup so that a
	 * paragraph containing inline tags like <strong> or <em> is matched against
	 * the full-sentence hash saved by the visual editor (via JS innerText).
	 * If no combined translation exists we fall back to per-text-node replacement.
	 */
	private function walk_dom( DOMNode $node ): void {
		if ( $node instanceof DOMElement ) {
			$tag = strtolower( $node->nodeName );
			if ( in_array( $tag, [ 'script', 'style', 'noscript', 'code', 'pre', 'textarea' ], true ) ) {
				return;
			}
			if ( $node->getAttribute( 'translate' ) === 'no' ) {
				return;
			}

			if ( in_array( $tag, self::BLOCK_TAGS, true ) ) {
				if ( $this->has_inline_children( $node ) ) {
					// Block contains inline elements (strong, em, span…).
					// Translate individual text nodes first so formatting is preserved.
					// Fall back to combined block replacement only if no per-node
					// translations exist (combined would flatten all tags to plain text).
					if ( ! $this->translate_node_tree( $node ) ) {
						$this->try_translate_block( $node );
					}
					return;
				}

				if ( $this->try_translate_block( $node ) ) {
					return; // translated successfully as a combined block unit
				}
				// try_translate_block found no stored translation — fall through
				// to per-child recursion so nested blocks still get translated.
			}
		}

		if ( $node instanceof DOMText ) {
			$translated = $this->translate( $node->nodeValue );
			if ( $translated !== $node->nodeValue ) {
				$node->nodeValue = $translated;
			}
			return;
		}

		foreach ( iterator_to_array( $node->childNodes ) as $child ) {
			$this->walk_dom( $child );
		}
	}

	/**
	 * Try to translate a block element as one combined string.
	 * Returns true and rewrites the element's content if a translation is found.
	 * Bails (returns false) whenever the subtree contains links, images, buttons,
	 * or nested block elements — we must not flatten those into plain text.
	 */
	private function try_translate_block( DOMElement $el ): bool {
		if ( $this->subtree_has_bail_tag( $el ) ) {
			return false;
		}

		$combined = $this->get_block_text( $el );
		if ( $combined === '' || mb_strlen( $combined ) < 2 ) {
			return false;
		}

		$hash = LP_Database::instance()->hash( $combined );
		$lang = $this->current_language;

		if ( empty( $this->cache[ $lang ][ $hash ] ) ) {
			return false;
		}

		$translated = $this->cache[ $lang ][ $hash ];

		// Try to rebuild the inline structure (strong, em, span…) by locating each
		// inline child's text inside the translated string.  Falls back to flat text
		// if any piece cannot be found (e.g. the inline text itself was translated).
		if ( ! $this->apply_block_translation( $el, $translated ) ) {
			while ( $el->firstChild ) {
				$el->removeChild( $el->firstChild );
			}
			$el->appendChild( $el->ownerDocument->createTextNode( $translated ) );
		}

		return true;
	}

	/**
	 * Apply a flat translated string to a block element while preserving inline
	 * child elements (strong, em, span, etc.).
	 *
	 * For each inline child we search for its original text inside the translated
	 * string (case-insensitive, extended to the next word boundary so "Meter"
	 * matches "meters").  If every piece is found in order the element is rebuilt
	 * with the inline tags intact.  Returns false if reconstruction fails so the
	 * caller can fall back to a flat text replacement.
	 */
	private function apply_block_translation( DOMElement $el, string $translated ): bool {
		// Collect direct inline-element children with their text and attributes.
		$pieces = [];
		foreach ( $el->childNodes as $child ) {
			if ( ! ( $child instanceof DOMElement ) ) {
				continue;
			}
			$text = $this->get_block_text( $child );
			if ( $text === '' ) {
				continue;
			}
			$attrs = [];
			foreach ( $child->attributes as $attr ) {
				$attrs[ $attr->name ] = $attr->value;
			}
			$pieces[] = [
				'tag'   => strtolower( $child->nodeName ),
				'text'  => $text,
				'attrs' => $attrs,
			];
		}

		if ( empty( $pieces ) ) {
			return false; // No inline elements — nothing to preserve.
		}

		// Locate each piece inside the translated string in order.
		$remaining = $translated;
		$segments  = [];

		foreach ( $pieces as $piece ) {
			$pos = mb_stripos( $remaining, $piece['text'] );
			if ( $pos === false ) {
				return false; // Piece not found — bail, caller uses flat text.
			}

			// Extend the match forward to the nearest non-word character so
			// "Meter" (5 chars) correctly captures "meters" (6 chars).
			$end = $pos + mb_strlen( $piece['text'] );
			while ( $end < mb_strlen( $remaining )
				&& preg_match( '/[\p{L}\p{N}]/u', mb_substr( $remaining, $end, 1 ) )
			) {
				$end++;
			}

			if ( $pos > 0 ) {
				$segments[] = [ 'type' => 'text', 'content' => mb_substr( $remaining, 0, $pos ) ];
			}
			$segments[] = [
				'type'    => 'element',
				'tag'     => $piece['tag'],
				'attrs'   => $piece['attrs'],
				'content' => mb_substr( $remaining, $pos, $end - $pos ),
			];

			$remaining = mb_substr( $remaining, $end );
		}

		if ( $remaining !== '' ) {
			$segments[] = [ 'type' => 'text', 'content' => $remaining ];
		}

		// Rebuild the element from the segments.
		while ( $el->firstChild ) {
			$el->removeChild( $el->firstChild );
		}

		$doc = $el->ownerDocument;
		foreach ( $segments as $seg ) {
			if ( $seg['type'] === 'text' ) {
				$el->appendChild( $doc->createTextNode( $seg['content'] ) );
			} else {
				$child_el = $doc->createElement( $seg['tag'] );
				foreach ( $seg['attrs'] as $name => $val ) {
					$child_el->setAttribute( $name, $val );
				}
				$child_el->appendChild( $doc->createTextNode( $seg['content'] ) );
				$el->appendChild( $child_el );
			}
		}

		return true;
	}

	/** Return true if the element has at least one DOMElement child (e.g. strong, em). */
	private function has_inline_children( DOMNode $node ): bool {
		foreach ( $node->childNodes as $child ) {
			if ( $child instanceof DOMElement ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Walk every text node in a subtree and translate it individually,
	 * descending into inline elements (strong, em, span…) without removing them.
	 * Returns true if at least one text node was changed.
	 */
	private function translate_node_tree( DOMNode $node ): bool {
		$any = false;
		foreach ( iterator_to_array( $node->childNodes ) as $child ) {
			if ( $child instanceof DOMText ) {
				$translated = $this->translate( $child->nodeValue );
				if ( $translated !== $child->nodeValue ) {
					$child->nodeValue = $translated;
					$any              = true;
				}
			} elseif ( $child instanceof DOMElement ) {
				$tag = strtolower( $child->nodeName );
				if ( in_array( $tag, [ 'script', 'style', 'noscript', 'code', 'pre' ], true ) ) {
					continue;
				}
				if ( $child->getAttribute( 'translate' ) === 'no' ) {
					continue;
				}
				if ( $this->translate_node_tree( $child ) ) {
					$any = true;
				}
			}
		}
		return $any;
	}

	/**
	 * Return true if the element's subtree contains any tag from BLOCK_BAIL_TAGS.
	 * We copy childNodes to avoid issues with live NodeList mutation.
	 */
	public function subtree_has_bail_tag( DOMNode $node ): bool {
		foreach ( iterator_to_array( $node->childNodes ) as $child ) {
			if ( ! ( $child instanceof DOMElement ) ) {
				continue;
			}
			if ( in_array( strtolower( $child->nodeName ), self::BLOCK_BAIL_TAGS, true ) ) {
				return true;
			}
			if ( $this->subtree_has_bail_tag( $child ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Collect the visible text of an element by recursively descending into
	 * all children (inline tags included), then normalize whitespace.
	 * Does NOT apply bail-tag logic — call subtree_has_bail_tag() first.
	 */
	public function get_block_text( DOMNode $node ): string {
		$text = '';
		foreach ( $node->childNodes as $child ) {
			if ( $child instanceof DOMText ) {
				$text .= $child->nodeValue;
			} elseif ( $child instanceof DOMElement ) {
				$tag = strtolower( $child->nodeName );
				if ( in_array( $tag, [ 'script', 'style', 'noscript', 'code', 'pre' ], true ) ) {
					continue;
				}
				if ( $child->getAttribute( 'translate' ) === 'no' ) {
					continue;
				}
				$text .= $this->get_block_text( $child );
			}
		}
		return preg_replace( '/\s+/', ' ', trim( $text ) ) ?? trim( $text );
	}

	/**
	 * Record a source text as "pending" for each active target language.
	 * Called automatically as pages are visited in the default language.
	 * Only creates a row if one doesn't already exist.
	 */
	public function register_text( string $text, string $page_url = '' ): void {
		$trimmed = trim( $text );
		if ( $trimmed === '' || mb_strlen( $trimmed ) < 2 ) {
			return;
		}

		$db      = LP_Database::instance();
		$active  = get_option( 'lp_active_languages', [ 'de', 'en', 'uk' ] );
		$default = get_option( 'lp_default_language', 'de' );

		foreach ( $active as $lang ) {
			if ( $lang !== $default ) {
				$db->upsert_translation( $trimmed, $lang, '', 'pending', $page_url );
			}
		}
	}

	/**
	 * Clear the cached translation map for one language or all languages.
	 * Call this after saving new translations so they show up immediately.
	 */
	public function invalidate_cache( string $lang = '' ): void {
		if ( $lang === '' ) {
			$active = get_option( 'lp_active_languages', [ 'de', 'en', 'uk' ] );
			foreach ( $active as $l ) {
				wp_cache_delete( 'lp_translations_' . $l, 'lp' );
			}
			$this->cache        = [];
			$this->cache_loaded = false;
		} else {
			wp_cache_delete( 'lp_translations_' . $lang, 'lp' );
			unset( $this->cache[ $lang ] );
			if ( $lang === $this->current_language ) {
				$this->cache_loaded = false;
			}
		}
	}

	/**
	 * Load translations from DB (or object cache) once per request.
	 * With a persistent cache backend this is typically a single cache hit.
	 */
	private function load_cache(): void {
		if ( $this->cache_loaded ) {
			return;
		}

		$lang      = $this->current_language;
		$cache_key = 'lp_translations_' . $lang;
		$cached    = wp_cache_get( $cache_key, 'lp' );

		if ( $cached !== false ) {
			$this->cache[ $lang ] = $cached;
		} else {
			$this->cache[ $lang ] = LP_Database::instance()->get_translations_for_language( $lang );
			wp_cache_set( $cache_key, $this->cache[ $lang ], 'lp', 300 );
		}

		$this->cache_loaded = true;
	}

	public static function is_rtl_language( string $lang_code ): bool {
		return in_array( $lang_code, [ 'ar' ], true );
	}

	/**
	 * All languages the plugin can handle.
	 * Add more here if you need them — just enable them in Settings > Languages.
	 *
	 * @return array<string, array{label: string, native: string, flag: string}>
	 */
	public static function available_languages(): array {
		return [
			'de' => [ 'label' => 'German',     'native' => 'Deutsch',    'flag' => '🇩🇪' ],
			'en' => [ 'label' => 'English',    'native' => 'English',    'flag' => '🇬🇧' ],
			'uk' => [ 'label' => 'Ukrainian',  'native' => 'Українська', 'flag' => '🇺🇦' ],
			'fr' => [ 'label' => 'French',     'native' => 'Français',   'flag' => '🇫🇷' ],
			'es' => [ 'label' => 'Spanish',    'native' => 'Español',    'flag' => '🇪🇸' ],
			'it' => [ 'label' => 'Italian',    'native' => 'Italiano',   'flag' => '🇮🇹' ],
			'tr' => [ 'label' => 'Turkish',    'native' => 'Türkçe',     'flag' => '🇹🇷' ],
			'pl' => [ 'label' => 'Polish',     'native' => 'Polski',     'flag' => '🇵🇱' ],
			'ru' => [ 'label' => 'Russian',    'native' => 'Русский',    'flag' => '🇷🇺' ],
			'ar' => [ 'label' => 'Arabic',     'native' => 'العربية',    'flag' => '🇸🇦' ],
		];
	}
}

