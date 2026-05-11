<?php
defined( 'ABSPATH' ) || exit;

/**
 * Übersetzungslogik: Textsuche, Caching, Hash-Lookup.
 */
class CWT_Translator {

    private static ?self $instance = null;

    /** @var array<string, array<string,string>>  lang_code => [ hash => translation ] */
    private array $cache = [];

    /** @var bool  Ob der Cache für die aktive Sprache geladen wurde */
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

    // -------------------------------------------------------------------------
    // Spracherkennung
    // -------------------------------------------------------------------------

    /**
     * Aktuelle Sprache ermitteln (Cookie > URL-Parameter > Default).
     */
    public function detect_language(): string {
        $active  = get_option( 'cwt_active_languages', [ 'de', 'en', 'uk' ] );
        $default = get_option( 'cwt_default_language', 'de' );

        // URL-Parameter hat Priorität (ermöglicht direkte Links)
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if ( isset( $_GET['cwt_lang'] ) ) {
            $lang = sanitize_key( wp_unslash( $_GET['cwt_lang'] ) );
            if ( in_array( $lang, $active, true ) ) {
                $this->set_language_cookie( $lang );
                return $lang;
            }
        }

        // Cookie auslesen
        if ( isset( $_COOKIE['cwt_language'] ) ) {
            $lang = sanitize_key( wp_unslash( $_COOKIE['cwt_language'] ) );
            if ( in_array( $lang, $active, true ) ) {
                return $lang;
            }
        }

        return $default;
    }

    /**
     * Sprach-Cookie setzen.
     */
    public function set_language_cookie( string $lang ): void {
        if ( headers_sent() ) {
            return;
        }
        setcookie(
            'cwt_language',
            $lang,
            [
                'expires'  => time() + ( 30 * DAY_IN_SECONDS ),
                'path'     => COOKIEPATH ?: '/',
                'domain'   => COOKIE_DOMAIN ?: '',
                'secure'   => is_ssl(),
                'httponly' => true,
                'samesite' => 'Lax',
            ]
        );
    }

    // -------------------------------------------------------------------------
    // Übersetzung abrufen
    // -------------------------------------------------------------------------

    public function get_current_language(): string {
        return $this->current_language;
    }

    public function is_default_language(): bool {
        return $this->current_language === get_option( 'cwt_default_language', 'de' );
    }

    /**
     * Cache für die aktive Sprache einmalig laden.
     */
    private function load_cache(): void {
        if ( $this->cache_loaded ) {
            return;
        }

        $lang        = $this->current_language;
        $cache_key   = 'cwt_translations_' . $lang;
        $cached      = wp_cache_get( $cache_key, 'cwt' );

        if ( $cached !== false ) {
            $this->cache[ $lang ] = $cached;
        } else {
            $this->cache[ $lang ] = CWT_Database::instance()->get_translations_for_language( $lang );
            wp_cache_set( $cache_key, $this->cache[ $lang ], 'cwt', 300 );
        }

        $this->cache_loaded = true;
    }

    /**
     * Text übersetzen – Fallback auf Original wenn keine Übersetzung vorhanden.
     */
    public function translate( string $text ): string {
        if ( $this->is_default_language() ) {
            return $text;
        }

        $trimmed = trim( $text );
        if ( $trimmed === '' ) {
            return $text;
        }

        $this->load_cache();

        $hash = CWT_Database::instance()->hash( $trimmed );
        $lang = $this->current_language;

        if ( isset( $this->cache[ $lang ][ $hash ] ) && $this->cache[ $lang ][ $hash ] !== '' ) {
            // Originaltext kann führende/nachfolgende Leerzeichen haben – beibehalten
            $leading  = strlen( $text ) - strlen( ltrim( $text ) );
            $trailing = strlen( $text ) - strlen( rtrim( $text ) );
            return substr( $text, 0, $leading )
                 . $this->cache[ $lang ][ $hash ]
                 . substr( $text, strlen( $text ) - $trailing );
        }

        return $text;
    }

    /**
     * Einen HTML-String übersetzen: nur sichtbare Textknoten ersetzen.
     * Klassen, IDs, Attribute, Skripte und Styles werden nicht angefasst.
     */
    public function translate_html( string $html ): string {
        if ( $this->is_default_language() || trim( $html ) === '' ) {
            return $html;
        }

        $this->load_cache();

        if ( empty( $this->cache[ $this->current_language ] ) ) {
            return $html;
        }

        // libxml-Fehler unterdrücken; UTF-8-Meta hinzufügen damit Sonderzeichen erhalten bleiben
        $use_errors = libxml_use_internal_errors( true );

        $doc = new DOMDocument( '1.0', 'UTF-8' );
        // UTF-8-BOM-Trick, damit Sonderzeichen korrekt geladen werden
        $doc->loadHTML(
            '<?xml encoding="UTF-8">' . $html,
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
        );
        libxml_clear_errors();
        libxml_use_internal_errors( $use_errors );

        $this->walk_dom( $doc );

        // Nur den Body-Inhalt zurückgeben, ohne <html><body>-Wrapper
        $result = '';
        foreach ( $doc->childNodes as $child ) {
            $result .= $doc->saveHTML( $child );
        }

        // Die <?xml encoding …> PI entfernen
        $result = preg_replace( '/<\?xml[^>]*\?>\n?/', '', $result );

        return $result ?? $html;
    }

    /**
     * Rekursiv durch den DOM-Baum laufen und nur Text-Nodes übersetzen.
     */
    private function walk_dom( DOMNode $node ): void {
        // Skript- und Style-Knoten überspringen
        if ( $node instanceof DOMElement ) {
            $tag = strtolower( $node->nodeName );
            if ( in_array( $tag, [ 'script', 'style', 'noscript', 'code', 'pre', 'textarea' ], true ) ) {
                return;
            }
            // translate="no"-Attribut respektieren
            if ( $node->getAttribute( 'translate' ) === 'no' ) {
                return;
            }
        }

        if ( $node instanceof DOMText ) {
            $original    = $node->nodeValue;
            $translated  = $this->translate( $original );
            if ( $translated !== $original ) {
                $node->nodeValue = $translated;
            }
            return;
        }

        // Kindknoten iterieren (Kopie, da nodeValue-Änderungen die Liste verändern können)
        foreach ( iterator_to_array( $node->childNodes ) as $child ) {
            $this->walk_dom( $child );
        }
    }

    // -------------------------------------------------------------------------
    // Text-Registrierung (für die Admin-Übersetzungsverwaltung)
    // -------------------------------------------------------------------------

    /**
     * Einen Text aus dem Frontend registrieren (nur pending, wenn noch nicht vorhanden).
     */
    public function register_text( string $text, string $page_url = '' ): void {
        $trimmed = trim( $text );
        if ( $trimmed === '' || mb_strlen( $trimmed ) < 2 ) {
            return;
        }

        $db      = CWT_Database::instance();
        $hash    = $db->hash( $trimmed );
        $active  = get_option( 'cwt_active_languages', [ 'de', 'en', 'uk' ] );
        $default = get_option( 'cwt_default_language', 'de' );

        foreach ( $active as $lang ) {
            if ( $lang === $default ) {
                continue;
            }
            // Nur einfügen, wenn noch kein Eintrag existiert
            $db->upsert_translation( $trimmed, $lang, '', 'pending', $page_url );
        }
    }

    /**
     * Cache für eine Sprache invalidieren.
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

    // -------------------------------------------------------------------------
    // Sprachen-Metadaten
    // -------------------------------------------------------------------------

    /**
     * Alle unterstützten Sprachen mit Label und Flag-Emoji.
     *
     * @return array<string, array{label: string, native: string, flag: string}>
     */
    public static function available_languages(): array {
        return [
            'de' => [ 'label' => 'Deutsch',     'native' => 'Deutsch',     'flag' => '🇩🇪' ],
            'en' => [ 'label' => 'English',     'native' => 'English',     'flag' => '🇬🇧' ],
            'uk' => [ 'label' => 'Ukrainisch',  'native' => 'Українська',  'flag' => '🇺🇦' ],
            'fr' => [ 'label' => 'Französisch', 'native' => 'Français',    'flag' => '🇫🇷' ],
            'es' => [ 'label' => 'Spanisch',    'native' => 'Español',     'flag' => '🇪🇸' ],
            'it' => [ 'label' => 'Italienisch', 'native' => 'Italiano',    'flag' => '🇮🇹' ],
            'tr' => [ 'label' => 'Türkisch',    'native' => 'Türkçe',      'flag' => '🇹🇷' ],
            'pl' => [ 'label' => 'Polnisch',    'native' => 'Polski',      'flag' => '🇵🇱' ],
            'ru' => [ 'label' => 'Russisch',    'native' => 'Русский',     'flag' => '🇷🇺' ],
            'ar' => [ 'label' => 'Arabisch',    'native' => 'العربية',     'flag' => '🇸🇦' ],
        ];
    }
}
