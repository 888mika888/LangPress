<?php
defined( 'ABSPATH' ) || exit;

/**
 * Handles language detection, the in-memory translation cache, and
 * the DOM-based text replacement that runs on every page request.
 */
class CWT_Translator {

    private static ?self $instance = null;

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
     * Priority: URL param ?cwt_lang → cookie → site default.
     */
    public function detect_language(): string {
        $active  = get_option( 'cwt_active_languages', [ 'de', 'en', 'uk' ] );
        $default = get_option( 'cwt_default_language', 'de' );

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if ( isset( $_GET['cwt_lang'] ) ) {
            $lang = sanitize_key( wp_unslash( $_GET['cwt_lang'] ) );
            if ( in_array( $lang, $active, true ) ) {
                $this->set_language_cookie( $lang );
                return $lang;
            }
        }

        if ( isset( $_COOKIE['cwt_language'] ) ) {
            $lang = sanitize_key( wp_unslash( $_COOKIE['cwt_language'] ) );
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
        setcookie( 'cwt_language', $lang, [
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
        return $this->current_language === get_option( 'cwt_default_language', 'de' );
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

        $hash = CWT_Database::instance()->hash( trim( $text ) );
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
     * We copy childNodes to an array first because replacing nodeValue
     * while iterating the live NodeList can skip siblings.
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
     * Record a source text as "pending" for each active target language.
     * Called automatically as pages are visited in the default language.
     * Only creates a row if one doesn't already exist.
     */
    public function register_text( string $text, string $page_url = '' ): void {
        $trimmed = trim( $text );
        if ( $trimmed === '' || mb_strlen( $trimmed ) < 2 ) {
            return;
        }

        $db      = CWT_Database::instance();
        $active  = get_option( 'cwt_active_languages', [ 'de', 'en', 'uk' ] );
        $default = get_option( 'cwt_default_language', 'de' );

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
            $active = get_option( 'cwt_active_languages', [ 'de', 'en', 'uk' ] );
            foreach ( $active as $l ) {
                wp_cache_delete( 'cwt_translations_' . $l, 'cwt' );
            }
            $this->cache        = [];
            $this->cache_loaded = false;
        } else {
            wp_cache_delete( 'cwt_translations_' . $lang, 'cwt' );
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
        $cache_key = 'cwt_translations_' . $lang;
        $cached    = wp_cache_get( $cache_key, 'cwt' );

        if ( $cached !== false ) {
            $this->cache[ $lang ] = $cached;
        } else {
            $this->cache[ $lang ] = CWT_Database::instance()->get_translations_for_language( $lang );
            wp_cache_set( $cache_key, $this->cache[ $lang ], 'cwt', 300 );
        }

        $this->cache_loaded = true;
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
