<?php
defined( 'ABSPATH' ) || exit;

/**
 * Frontend-Ausgabe-Pufferung, Textübersetzung und Translation-Editor-Modus.
 */
class CWT_Frontend {

    private static ?self $instance = null;

    private function __construct() {
        // Admin-Bar-Button: muss VOR dem is_admin()-Guard stehen,
        // damit er auch im wp-admin registriert wird (admin_bar_menu feuert überall).
        add_action( 'admin_bar_menu', [ $this, 'add_admin_bar_button' ], 100 );

        // Der Rest läuft nur im Frontend
        if ( is_admin() ) {
            return;
        }

        // Editor-Modus so früh wie möglich prüfen (vor Output-Buffering)
        add_action( 'template_redirect', [ $this, 'maybe_start_editor_mode' ], 0 );
        // Output-Buffering für Übersetzung
        add_action( 'template_redirect', [ $this, 'start_output_buffer' ], 1 );
        // Texterkennung im Hintergrund
        add_action( 'template_redirect', [ $this, 'maybe_register_texts' ], 5 );
    }

    public static function instance(): self {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    // =========================================================================
    // Admin-Bar Button
    // =========================================================================

    public function add_admin_bar_button( WP_Admin_Bar $wp_admin_bar ): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        // Nur im Frontend anzeigen, nicht in wp-admin
        if ( is_admin() ) {
            return;
        }

        $current_url = ( is_ssl() ? 'https://' : 'http://' )
            . sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ?? '' ) )
            . sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ?? '/' ) );

        // Bestehenden editor-Parameter entfernen und neu setzen
        $editor_url = add_query_arg(
            'cwt_translation_editor',
            '1',
            remove_query_arg( 'cwt_translation_editor', $current_url )
        );

        $wp_admin_bar->add_node( [
            'id'    => 'cwt-translate-page',
            'title' => '<span class="ab-icon dashicons dashicons-translation"></span>'
                     . ' Translate Page',
            'href'  => esc_url( $editor_url ),
            'meta'  => [
                'class' => 'cwt-translate-page-btn',
                'title' => __( 'Diese Seite übersetzen', 'custom-website-translator' ),
            ],
        ] );
    }

    // =========================================================================
    // Translation Editor Mode
    // =========================================================================

    private function is_editor_mode(): bool {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        return isset( $_GET['cwt_translation_editor'] )
            && $_GET['cwt_translation_editor'] === '1'
            && current_user_can( 'manage_options' );
    }

    public function maybe_start_editor_mode(): void {
        if ( ! $this->is_editor_mode() ) {
            return;
        }

        // Editor-Assets einbinden
        add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_editor_assets' ] );
        // Sidebar in den Footer injizieren
        add_action( 'wp_footer', [ $this, 'inject_editor_sidebar' ], 9999 );

        // Im Editor-Modus: Übersetzungs-Buffering deaktivieren (Originaltexte anzeigen)
        remove_action( 'template_redirect', [ $this, 'start_output_buffer' ], 1 );
    }

    public function enqueue_editor_assets(): void {
        wp_enqueue_style(
            'cwt-translation-editor',
            CWT_PLUGIN_URL . 'public/translation-editor.css',
            [],
            CWT_VERSION
        );

        wp_enqueue_script(
            'cwt-translation-editor',
            CWT_PLUGIN_URL . 'public/translation-editor.js',
            [],
            CWT_VERSION,
            true
        );

        $active_langs = (array) get_option( 'cwt_active_languages', [ 'de', 'en', 'uk' ] );
        $default_lang = get_option( 'cwt_default_language', 'de' );
        $target_langs = array_values(
            array_filter( $active_langs, fn( string $l ) => $l !== $default_lang )
        );

        wp_localize_script( 'cwt-translation-editor', 'CWT_Editor', [
            'ajaxUrl'     => admin_url( 'admin-ajax.php' ),
            'adminUrl'    => admin_url( 'admin.php?page=cwt-translations' ),
            'nonce'       => wp_create_nonce( 'cwt_admin_nonce' ),
            'defaultLang' => $default_lang,
            'targetLangs' => $target_langs,
            'postId'      => (int) get_queried_object_id(),
            'closeUrl'    => esc_url( remove_query_arg( 'cwt_translation_editor' ) ),
            'sidebarWidth'=> 340,
        ] );
    }

    public function inject_editor_sidebar(): void {
        if ( ! $this->is_editor_mode() ) {
            return;
        }

        $active_langs = (array) get_option( 'cwt_active_languages', [ 'de', 'en', 'uk' ] );
        $default_lang = get_option( 'cwt_default_language', 'de' );
        $all_langs    = CWT_Translator::available_languages();
        $target_langs = array_filter( $active_langs, fn( string $l ) => $l !== $default_lang );
        $def_meta     = $all_langs[ $default_lang ] ?? [ 'flag' => '', 'native' => strtoupper( $default_lang ) ];

        ?>
        <div id="cwt-editor-sidebar" translate="no"
             role="complementary"
             aria-label="<?php esc_attr_e( 'Translation Editor', 'custom-website-translator' ); ?>">

            <!-- Header -->
            <div class="cwt-sidebar-header">
                <button class="cwt-sidebar-close"
                        id="cwt-editor-close"
                        type="button"
                        aria-label="<?php esc_attr_e( 'Editor schließen', 'custom-website-translator' ); ?>">&times;</button>

                <span class="cwt-sidebar-title">
                    <?php esc_html_e( 'Translation Editor', 'custom-website-translator' ); ?>
                </span>

                <button class="cwt-sidebar-save-top"
                        id="cwt-editor-save-top"
                        type="button">
                    <?php esc_html_e( 'Speichern', 'custom-website-translator' ); ?>
                </button>
            </div>

            <!-- Tabs -->
            <div class="cwt-sidebar-tabs" role="tablist">
                <button class="cwt-sidebar-tab cwt-sidebar-tab--active"
                        type="button"
                        role="tab"
                        aria-selected="true">
                    <?php esc_html_e( 'Translation Editor', 'custom-website-translator' ); ?>
                </button>
                <button class="cwt-sidebar-tab"
                        type="button"
                        role="tab"
                        aria-selected="false">
                    <?php esc_html_e( 'String Translation', 'custom-website-translator' ); ?>
                </button>
            </div>

            <!-- Scrollable body -->
            <div class="cwt-sidebar-body">

                <!-- Default language display -->
                <div class="cwt-sidebar-lang-display">
                    <span class="cwt-sidebar-lang-pill">
                        <?php echo esc_html( $def_meta['flag'] . ' ' . $def_meta['native'] ); ?>
                    </span>
                </div>

                <!-- Hint (no element selected) -->
                <div class="cwt-sidebar-hint" id="cwt-editor-hint">
                    <p><?php esc_html_e( 'Klicke auf ein ✎ Symbol oder direkt auf einen Text, um ihn zu übersetzen.', 'custom-website-translator' ); ?></p>
                </div>

                <!-- Translation fields (hidden until element is selected) -->
                <div class="cwt-sidebar-fields" id="cwt-editor-fields" style="display:none">

                    <!-- Original text (readonly) -->
                    <div class="cwt-sidebar-field">
                        <label class="cwt-sidebar-label">
                            <?php
                            printf(
                                /* translators: %s: language name */
                                esc_html__( '%s From %s', 'custom-website-translator' ),
                                esc_html( $def_meta['flag'] ),
                                esc_html( $def_meta['native'] )
                            );
                            ?>
                        </label>
                        <textarea class="cwt-sidebar-textarea cwt-sidebar-textarea--readonly"
                                  id="cwt-editor-de"
                                  readonly
                                  rows="3"
                                  placeholder="<?php esc_attr_e( 'Originaltext…', 'custom-website-translator' ); ?>"></textarea>
                        <small class="cwt-sidebar-sublabel">Text</small>
                    </div>

                    <?php foreach ( $target_langs as $lang_code ) :
                        $meta = $all_langs[ $lang_code ] ?? [ 'flag' => '', 'native' => strtoupper( $lang_code ) ];
                    ?>
                    <div class="cwt-sidebar-field">
                        <label class="cwt-sidebar-label"
                               for="cwt-editor-<?php echo esc_attr( $lang_code ); ?>">
                            <?php
                            printf(
                                esc_html__( '%s To %s', 'custom-website-translator' ),
                                esc_html( $meta['flag'] ),
                                esc_html( $meta['native'] )
                            );
                            ?>
                        </label>
                        <textarea class="cwt-sidebar-textarea"
                                  id="cwt-editor-<?php echo esc_attr( $lang_code ); ?>"
                                  rows="3"
                                  placeholder="<?php
                                      echo esc_attr(
                                          $meta['native'] . ' '
                                          . __( 'Übersetzung…', 'custom-website-translator' )
                                      );
                                  ?>"></textarea>
                        <small class="cwt-sidebar-sublabel">Text</small>
                    </div>
                    <?php endforeach; ?>

                    <!-- Status message -->
                    <div class="cwt-sidebar-message" id="cwt-editor-msg"></div>

                </div><!-- /#cwt-editor-fields -->

            </div><!-- /.cwt-sidebar-body -->

            <!-- Footer Save Button -->
            <div class="cwt-sidebar-footer" id="cwt-editor-footer" style="display:none">
                <button class="cwt-sidebar-save-btn"
                        id="cwt-editor-save"
                        type="button">
                    <?php esc_html_e( 'Speichern', 'custom-website-translator' ); ?>
                </button>
            </div>

        </div><!-- /#cwt-editor-sidebar -->
        <?php
    }

    // =========================================================================
    // Output-Buffering (Übersetzung im Frontend)
    // =========================================================================

    public function start_output_buffer(): void {
        $translator = CWT_Translator::instance();

        if ( $translator->is_default_language() ) {
            return;
        }

        ob_start( [ $this, 'process_output' ] );
    }

    public function process_output( string $html ): string {
        if ( trim( $html ) === '' ) {
            return $html;
        }

        if ( $this->is_non_html_request() ) {
            return $html;
        }

        return CWT_Translator::instance()->translate_html( $html );
    }

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
        foreach ( headers_list() as $header ) {
            if ( stripos( $header, 'Content-Type:' ) === 0 ) {
                if ( stripos( $header, 'text/html' ) === false ) {
                    return true;
                }
            }
        }
        return false;
    }

    // =========================================================================
    // Text-Registrierung für Admin-Verwaltung
    // =========================================================================

    public function maybe_register_texts(): void {
        $translator = CWT_Translator::instance();

        if ( ! $translator->is_default_language() ) {
            return;
        }

        $page_url  = $this->get_current_url();
        $trans_key = 'cwt_scanned_' . md5( $page_url );

        if ( get_transient( $trans_key ) ) {
            return;
        }

        ob_start( function ( string $html ) use ( $translator, $page_url, $trans_key ): string {
            $this->extract_and_register_texts( $html, $translator, $page_url );
            set_transient( $trans_key, 1, DAY_IN_SECONDS );
            return $html;
        } );
    }

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
            if ( mb_strlen( $text ) >= 2 && preg_match( '/\p{L}/u', $text ) ) {
                $texts[] = $text;
            }
            return;
        }

        foreach ( $node->childNodes as $child ) {
            $this->collect_texts( $child, $texts );
        }
    }

    private function get_current_url(): string {
        return ( is_ssl() ? 'https://' : 'http://' )
             . sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ?? 'localhost' ) )
             . sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ?? '/' ) );
    }
}
