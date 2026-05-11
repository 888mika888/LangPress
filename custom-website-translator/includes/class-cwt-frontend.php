<?php
defined( 'ABSPATH' ) || exit;

/**
 * Frontend-Ausgabe-Pufferung und Textübersetzung im HTML-Stream.
 */
class CWT_Frontend {

    private static ?self $instance = null;

    private function __construct() {
        // Übersetzung nur im Frontend und nicht im Admin aktiv
        if ( is_admin() ) {
            return;
        }

        // Output-Buffering so früh wie möglich starten
        add_action( 'template_redirect', [ $this, 'start_output_buffer' ], 1 );

        // Texte für die Übersetzungsverwaltung im Hintergrund registrieren
        // (nur wenn aktuelle Sprache = Standardsprache, um Performance zu sparen)
        add_action( 'template_redirect', [ $this, 'maybe_register_texts' ], 5 );
    }

    public static function instance(): self {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    // -------------------------------------------------------------------------
    // Output-Buffering
    // -------------------------------------------------------------------------

    public function start_output_buffer(): void {
        $translator = CWT_Translator::instance();

        // Wenn Standardsprache aktiv ist, nichts übersetzen
        if ( $translator->is_default_language() ) {
            return;
        }

        ob_start( [ $this, 'process_output' ] );
    }

    /**
     * Callback für ob_start – verarbeitet den fertigen HTML-Output.
     */
    public function process_output( string $html ): string {
        if ( trim( $html ) === '' ) {
            return $html;
        }

        // Keine Übersetzung für REST-API, Admin-AJAX, XML-Feeds
        if ( $this->is_non_html_request() ) {
            return $html;
        }

        return CWT_Translator::instance()->translate_html( $html );
    }

    /**
     * Prüfen ob der aktuelle Request kein normales HTML-Dokument ist.
     */
    private function is_non_html_request(): bool {
        if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
            return true;
        }
        if ( defined( 'DOING_AJAX' ) && DOING_AJAX ) {
            return true;
        }
        if ( is_feed() ) {
            return true;
        }
        // Content-Type prüfen
        foreach ( headers_list() as $header ) {
            if ( stripos( $header, 'Content-Type:' ) === 0 ) {
                if ( stripos( $header, 'text/html' ) === false ) {
                    return true;
                }
            }
        }
        return false;
    }

    // -------------------------------------------------------------------------
    // Text-Registrierung für Admin-Verwaltung
    // -------------------------------------------------------------------------

    /**
     * Auf der Standardsprache: sichtbare Texte im Hintergrund registrieren.
     * Wird per Transient gedrosselt (einmal pro Seite alle 24h).
     */
    public function maybe_register_texts(): void {
        $translator = CWT_Translator::instance();

        // Nur auf Standardsprache und nicht im Admin
        if ( ! $translator->is_default_language() ) {
            return;
        }

        // Registrierung per URL-Transient begrenzen (max 1x alle 24h pro URL)
        $page_url   = $this->get_current_url();
        $trans_key  = 'cwt_scanned_' . md5( $page_url );

        if ( get_transient( $trans_key ) ) {
            return;
        }

        // Output-Buffer für die Erkennung starten
        ob_start( function ( string $html ) use ( $translator, $page_url, $trans_key ): string {
            $this->extract_and_register_texts( $html, $translator, $page_url );
            set_transient( $trans_key, 1, DAY_IN_SECONDS );
            return $html; // Original-HTML unverändert zurückgeben
        } );
    }

    /**
     * Sichtbare Texte aus dem HTML extrahieren und in der DB registrieren.
     */
    private function extract_and_register_texts( string $html, CWT_Translator $translator, string $page_url ): void {
        if ( trim( $html ) === '' ) {
            return;
        }

        $use_errors = libxml_use_internal_errors( true );

        $doc = new DOMDocument( '1.0', 'UTF-8' );
        $doc->loadHTML(
            '<?xml encoding="UTF-8">' . $html,
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
        );

        libxml_clear_errors();
        libxml_use_internal_errors( $use_errors );

        $texts = [];
        $this->collect_texts( $doc, $texts );

        foreach ( array_unique( $texts ) as $text ) {
            $translator->register_text( $text, $page_url );
        }
    }

    /**
     * Rekursiv Texte aus DOM-Knoten sammeln.
     *
     * @param DOMNode $node
     * @param string[] $texts
     */
    private function collect_texts( DOMNode $node, array &$texts ): void {
        if ( $node instanceof DOMElement ) {
            $tag = strtolower( $node->nodeName );
            if ( in_array( $tag, [ 'script', 'style', 'noscript', 'code', 'pre', 'textarea', 'head' ], true ) ) {
                return;
            }
            if ( $node->getAttribute( 'translate' ) === 'no' ) {
                return;
            }
        }

        if ( $node instanceof DOMText ) {
            $text = trim( $node->nodeValue );
            // Nur Texte mit Buchstaben registrieren (keine reinen Zahlen/Sonderzeichen)
            if ( mb_strlen( $text ) >= 2 && preg_match( '/\p{L}/u', $text ) ) {
                $texts[] = $text;
            }
            return;
        }

        foreach ( $node->childNodes as $child ) {
            $this->collect_texts( $child, $texts );
        }
    }

    /**
     * Aktuelle URL ermitteln.
     */
    private function get_current_url(): string {
        return ( is_ssl() ? 'https://' : 'http://' )
             . sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ?? 'localhost' ) )
             . sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ?? '/' ) );
    }
}
