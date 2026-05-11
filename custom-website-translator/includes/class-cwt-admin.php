<?php
defined( 'ABSPATH' ) || exit;

/**
 * Admin-Interface: Menüs, Einstellungsseiten, Übersetzungsverwaltung.
 */
class CWT_Admin {

    private static ?self $instance = null;

    private function __construct() {
        add_action( 'admin_menu',            [ $this, 'register_menus' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
        add_action( 'admin_init',            [ $this, 'handle_form_submissions' ] );

        // AJAX-Handler für Übersetzungsverwaltung
        add_action( 'wp_ajax_cwt_save_translation',  [ $this, 'ajax_save_translation' ] );
        add_action( 'wp_ajax_cwt_update_status',     [ $this, 'ajax_update_status' ] );
        add_action( 'wp_ajax_cwt_delete_translation',[ $this, 'ajax_delete_translation' ] );
        add_action( 'wp_ajax_cwt_export',            [ $this, 'ajax_export' ] );
        add_action( 'wp_ajax_cwt_clear_cache',          [ $this, 'ajax_clear_cache' ] );
        add_action( 'wp_ajax_cwt_reinstall_db',         [ $this, 'ajax_reinstall_db' ] );
        add_action( 'wp_ajax_cwt_get_translation',      [ $this, 'ajax_get_translation' ] );
        add_action( 'wp_ajax_cwt_get_page_translations',[ $this, 'ajax_get_page_translations' ] );

        // Plugin-Aktionslinks
        add_filter( 'plugin_action_links_' . CWT_PLUGIN_BASENAME, [ $this, 'plugin_action_links' ] );
    }

    public static function instance(): self {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    // -------------------------------------------------------------------------
    // Menüs
    // -------------------------------------------------------------------------

    public function register_menus(): void {
        add_menu_page(
            __( 'Website Translator', 'custom-website-translator' ),
            __( 'Translator', 'custom-website-translator' ),
            'manage_options',
            'cwt-settings',
            [ $this, 'page_settings' ],
            'dashicons-translation',
            80
        );

        add_submenu_page(
            'cwt-settings',
            __( 'Einstellungen', 'custom-website-translator' ),
            __( 'Einstellungen', 'custom-website-translator' ),
            'manage_options',
            'cwt-settings',
            [ $this, 'page_settings' ]
        );

        add_submenu_page(
            'cwt-settings',
            __( 'Sprachen', 'custom-website-translator' ),
            __( 'Sprachen', 'custom-website-translator' ),
            'manage_options',
            'cwt-languages',
            [ $this, 'page_languages' ]
        );

        add_submenu_page(
            'cwt-settings',
            __( 'Übersetzungen', 'custom-website-translator' ),
            __( 'Übersetzungen', 'custom-website-translator' ),
            'manage_options',
            'cwt-translations',
            [ $this, 'page_translations' ]
        );

        add_submenu_page(
            'cwt-settings',
            __( 'Design & Dropdown', 'custom-website-translator' ),
            __( 'Design', 'custom-website-translator' ),
            'manage_options',
            'cwt-design',
            [ $this, 'page_design' ]
        );

        add_submenu_page(
            'cwt-settings',
            __( 'Import / Export', 'custom-website-translator' ),
            __( 'Import / Export', 'custom-website-translator' ),
            'manage_options',
            'cwt-import-export',
            [ $this, 'page_import_export' ]
        );

        add_submenu_page(
            'cwt-settings',
            __( 'Debug / Status', 'custom-website-translator' ),
            __( 'Debug / Status', 'custom-website-translator' ),
            'manage_options',
            'cwt-debug',
            [ $this, 'page_debug' ]
        );
    }

    // -------------------------------------------------------------------------
    // Assets
    // -------------------------------------------------------------------------

    public function enqueue_assets( string $hook ): void {
        $cwt_pages = [
            'toplevel_page_cwt-settings',
            'translator_page_cwt-languages',
            'translator_page_cwt-translations',
            'translator_page_cwt-design',
            'translator_page_cwt-import-export',
            'translator_page_cwt-debug',
        ];

        if ( ! in_array( $hook, $cwt_pages, true ) ) {
            return;
        }

        wp_enqueue_style(
            'cwt-admin',
            CWT_PLUGIN_URL . 'admin/admin.css',
            [],
            CWT_VERSION
        );

        wp_enqueue_style( 'wp-color-picker' );

        wp_enqueue_script(
            'cwt-admin',
            CWT_PLUGIN_URL . 'admin/admin.js',
            [ 'jquery', 'wp-color-picker' ],
            CWT_VERSION,
            true
        );

        wp_localize_script( 'cwt-admin', 'CWT_Admin', [
            'ajaxUrl' => admin_url( 'admin-ajax.php' ),
            'nonce'   => wp_create_nonce( 'cwt_admin_nonce' ),
            'i18n'    => [
                'saved'   => __( 'Gespeichert!', 'custom-website-translator' ),
                'saveBtn' => __( 'Speichern', 'custom-website-translator' ),
                'error'   => __( 'Fehler beim Speichern.', 'custom-website-translator' ),
                'confirm' => __( 'Wirklich löschen?', 'custom-website-translator' ),
                'active'  => __( 'Aktiv', 'custom-website-translator' ),
                'pending' => __( 'Ausstehend', 'custom-website-translator' ),
                'ignored' => __( 'Ignoriert', 'custom-website-translator' ),
            ],
        ] );
    }

    // -------------------------------------------------------------------------
    // Formularverarbeitung (klassische POST-Formulare)
    // -------------------------------------------------------------------------

    public function handle_form_submissions(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        // Einstellungen
        if ( isset( $_POST['cwt_save_settings'], $_POST['_wpnonce'] ) ) {
            if ( wp_verify_nonce( sanitize_key( $_POST['_wpnonce'] ), 'cwt_settings_nonce' ) ) {
                $this->save_settings();
            }
        }

        // Sprachen
        if ( isset( $_POST['cwt_save_languages'], $_POST['_wpnonce'] ) ) {
            if ( wp_verify_nonce( sanitize_key( $_POST['_wpnonce'] ), 'cwt_languages_nonce' ) ) {
                $this->save_languages();
            }
        }

        // Design
        if ( isset( $_POST['cwt_save_design'], $_POST['_wpnonce'] ) ) {
            if ( wp_verify_nonce( sanitize_key( $_POST['_wpnonce'] ), 'cwt_design_nonce' ) ) {
                $this->save_design();
            }
        }

        // Import
        if ( isset( $_POST['cwt_do_import'], $_POST['_wpnonce'] ) ) {
            if ( wp_verify_nonce( sanitize_key( $_POST['_wpnonce'] ), 'cwt_import_nonce' ) ) {
                $this->process_import();
            }
        }
    }

    // -------------------------------------------------------------------------
    // Speicher-Methoden
    // -------------------------------------------------------------------------

    private function save_settings(): void {
        $display = sanitize_key( $_POST['cwt_switcher_display'] ?? 'all' );
        if ( ! in_array( $display, [ 'all', 'specific', 'exclude' ], true ) ) {
            $display = 'all';
        }

        $position = sanitize_key( $_POST['cwt_switcher_position'] ?? 'bottom-right' );
        $allowed_positions = [ 'top-left', 'top-right', 'bottom-left', 'bottom-right', 'shortcode-only' ];
        if ( ! in_array( $position, $allowed_positions, true ) ) {
            $position = 'bottom-right';
        }

        $pages = [];
        if ( isset( $_POST['cwt_switcher_pages'] ) && is_array( $_POST['cwt_switcher_pages'] ) ) {
            $pages = array_map( 'absint', $_POST['cwt_switcher_pages'] );
        }

        update_option( 'cwt_switcher_display',  $display );
        update_option( 'cwt_switcher_position', $position );
        update_option( 'cwt_switcher_pages',    $pages );
        update_option( 'cwt_position_fixed',    isset( $_POST['cwt_position_fixed'] ) ? 1 : 0 );

        add_settings_error( 'cwt', 'cwt_saved', __( 'Einstellungen gespeichert.', 'custom-website-translator' ), 'success' );
    }

    private function save_languages(): void {
        $default = sanitize_key( $_POST['cwt_default_language'] ?? 'de' );
        $all     = CWT_Translator::available_languages();

        if ( ! isset( $all[ $default ] ) ) {
            $default = 'de';
        }

        $active = [];
        if ( isset( $_POST['cwt_active_languages'] ) && is_array( $_POST['cwt_active_languages'] ) ) {
            foreach ( $_POST['cwt_active_languages'] as $lang ) {
                $lang = sanitize_key( $lang );
                if ( isset( $all[ $lang ] ) ) {
                    $active[] = $lang;
                }
            }
        }

        // Standardsprache immer in aktiven Sprachen
        if ( ! in_array( $default, $active, true ) ) {
            array_unshift( $active, $default );
        }

        update_option( 'cwt_default_language',  $default );
        update_option( 'cwt_active_languages',  $active );

        // Cache leeren nach Sprachänderung
        CWT_Translator::instance()->invalidate_cache();

        add_settings_error( 'cwt', 'cwt_saved', __( 'Sprachen gespeichert.', 'custom-website-translator' ), 'success' );
    }

    private function save_design(): void {
        $style = sanitize_key( $_POST['cwt_switcher_style'] ?? 'dropdown' );
        if ( ! in_array( $style, [ 'dropdown', 'buttons' ], true ) ) {
            $style = 'dropdown';
        }

        $mode = sanitize_key( $_POST['cwt_display_mode'] ?? 'text' );
        if ( ! in_array( $mode, [ 'text', 'flag', 'both' ], true ) ) {
            $mode = 'text';
        }

        // Farben validieren (nur gültige HEX-Farben)
        $color_fields = [ 'cwt_bg_color', 'cwt_text_color', 'cwt_border_color', 'cwt_hover_color' ];
        foreach ( $color_fields as $field ) {
            $val = sanitize_hex_color( $_POST[ $field ] ?? '#ffffff' ) ?: '#ffffff';
            update_option( $field, $val );
        }

        // Numerische Felder
        $num_fields = [ 'cwt_border_radius', 'cwt_font_size', 'cwt_padding' ];
        foreach ( $num_fields as $field ) {
            $val = absint( $_POST[ $field ] ?? 0 );
            $val = max( 0, min( 100, $val ) );
            update_option( $field, (string) $val );
        }

        update_option( 'cwt_switcher_style', $style );
        update_option( 'cwt_display_mode',   $mode );

        add_settings_error( 'cwt', 'cwt_saved', __( 'Design gespeichert.', 'custom-website-translator' ), 'success' );
    }

    private function process_import(): void {
        if ( ! isset( $_FILES['cwt_import_file'] ) || $_FILES['cwt_import_file']['error'] !== UPLOAD_ERR_OK ) {
            add_settings_error( 'cwt', 'cwt_import_error', __( 'Keine Datei hochgeladen.', 'custom-website-translator' ), 'error' );
            return;
        }

        $tmp_file = $_FILES['cwt_import_file']['tmp_name'];
        $ext      = strtolower( pathinfo( sanitize_file_name( $_FILES['cwt_import_file']['name'] ), PATHINFO_EXTENSION ) );

        if ( $ext !== 'json' ) {
            add_settings_error( 'cwt', 'cwt_import_error', __( 'Nur JSON-Dateien werden unterstützt.', 'custom-website-translator' ), 'error' );
            return;
        }

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
        $json    = file_get_contents( $tmp_file );
        $data    = json_decode( $json, true );
        $db      = CWT_Database::instance();
        $count   = 0;

        if ( ! is_array( $data ) ) {
            add_settings_error( 'cwt', 'cwt_import_error', __( 'Ungültige JSON-Datei.', 'custom-website-translator' ), 'error' );
            return;
        }

        foreach ( $data as $entry ) {
            if ( ! isset( $entry['original_text'], $entry['language_code'], $entry['translated_text'] ) ) {
                continue;
            }
            $status = in_array( $entry['status'] ?? 'active', [ 'active', 'pending', 'ignored' ], true )
                    ? $entry['status']
                    : 'active';

            $db->upsert_translation(
                sanitize_textarea_field( $entry['original_text'] ),
                sanitize_key( $entry['language_code'] ),
                sanitize_textarea_field( $entry['translated_text'] ),
                $status
            );
            $count++;
        }

        CWT_Translator::instance()->invalidate_cache();

        add_settings_error(
            'cwt',
            'cwt_import_success',
            sprintf( __( '%d Übersetzungen importiert.', 'custom-website-translator' ), $count ),
            'success'
        );
    }

    // -------------------------------------------------------------------------
    // AJAX-Handler
    // -------------------------------------------------------------------------

    public function ajax_save_translation(): void {
        check_ajax_referer( 'cwt_admin_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => 'Insufficient permissions.' ] );
        }

        $original = sanitize_textarea_field( wp_unslash( $_POST['original'] ?? '' ) );
        $post_id  = absint( $_POST['post_id'] ?? 0 );

        if ( $original === '' ) {
            wp_send_json_error( [ 'message' => 'Missing original text.' ] );
        }

        $db           = CWT_Database::instance();
        $active_langs = (array) get_option( 'cwt_active_languages', [ 'de', 'en', 'uk' ] );
        $default_lang = get_option( 'cwt_default_language', 'de' );
        $target_langs = array_filter( $active_langs, fn( $l ) => $l !== $default_lang );

        // --- Multi-language save (from Translation Editor) ---
        // The editor sends each target lang as its own POST key (e.g. POST['en'], POST['uk'])
        $multi_langs = array_filter(
            $target_langs,
            fn( $l ) => isset( $_POST[ $l ] )
        );

        if ( ! empty( $multi_langs ) ) {
            $saved = [];
            foreach ( $multi_langs as $lang ) {
                $translated = sanitize_textarea_field( wp_unslash( $_POST[ $lang ] ) );
                if ( $translated === '' ) {
                    continue; // Don't overwrite existing with empty
                }
                if ( $db->upsert_translation( $original, $lang, $translated, 'active', '', $post_id ) ) {
                    $saved[] = $lang;
                    CWT_Translator::instance()->invalidate_cache( $lang );
                }
            }

            if ( empty( $saved ) ) {
                wp_send_json_error( [ 'message' => __( 'Keine Übersetzungen eingegeben.', 'custom-website-translator' ) ] );
            }

            wp_send_json_success( [
                'message' => __( 'Gespeichert!', 'custom-website-translator' ),
                'langs'   => $saved,
            ] );
            return;
        }

        // --- Legacy single-language save (from admin translations table) ---
        $lang       = sanitize_key( wp_unslash( $_POST['lang'] ?? '' ) );
        $translated = sanitize_textarea_field( wp_unslash( $_POST['translated'] ?? '' ) );
        $status     = sanitize_key( wp_unslash( $_POST['status'] ?? 'active' ) );

        if ( $lang === '' ) {
            wp_send_json_error( [ 'message' => 'Missing parameters.' ] );
        }

        $allowed_status = [ 'active', 'pending', 'ignored' ];
        if ( ! in_array( $status, $allowed_status, true ) ) {
            $status = 'active';
        }

        $result = $db->upsert_translation( $original, $lang, $translated, $status, '', $post_id );

        if ( $result ) {
            CWT_Translator::instance()->invalidate_cache( $lang );
            wp_send_json_success( [ 'message' => __( 'Gespeichert!', 'custom-website-translator' ) ] );
        } else {
            wp_send_json_error( [ 'message' => __( 'Fehler beim Speichern.', 'custom-website-translator' ) ] );
        }
    }

    public function ajax_update_status(): void {
        check_ajax_referer( 'cwt_admin_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => 'Insufficient permissions.' ] );
        }

        $id     = absint( $_POST['id'] ?? 0 );
        $status = sanitize_key( $_POST['status'] ?? '' );

        if ( ! $id ) {
            wp_send_json_error( [ 'message' => 'Invalid ID.' ] );
        }

        $result = CWT_Database::instance()->update_status( $id, $status );
        CWT_Translator::instance()->invalidate_cache();

        $result
            ? wp_send_json_success()
            : wp_send_json_error( [ 'message' => 'Update failed.' ] );
    }

    public function ajax_delete_translation(): void {
        check_ajax_referer( 'cwt_admin_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => 'Insufficient permissions.' ] );
        }

        $hash   = sanitize_text_field( wp_unslash( $_POST['hash'] ?? '' ) );
        if ( $hash === '' ) {
            wp_send_json_error( [ 'message' => 'Missing hash.' ] );
        }

        $result = CWT_Database::instance()->delete_by_hash( $hash );
        CWT_Translator::instance()->invalidate_cache();

        $result
            ? wp_send_json_success()
            : wp_send_json_error( [ 'message' => 'Delete failed.' ] );
    }

    public function ajax_export(): void {
        check_ajax_referer( 'cwt_admin_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Forbidden', 403 );
        }

        global $wpdb;
        $table = CWT_Database::instance()->get_table_translations();

        $rows = $wpdb->get_results(
            "SELECT original_text, text_hash, language_code, translated_text, status, page_url
             FROM $table
             ORDER BY language_code, text_hash",
            ARRAY_A
        );

        $filename = 'cwt-export-' . gmdate( 'Y-m-d' ) . '.json';

        header( 'Content-Type: application/json; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
        header( 'Cache-Control: no-cache, no-store, must-revalidate' );
        header( 'Pragma: no-cache' );
        header( 'Expires: 0' );

        // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
        echo json_encode( $rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE );
        exit;
    }

    public function ajax_clear_cache(): void {
        check_ajax_referer( 'cwt_admin_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => 'Insufficient permissions.' ] );
        }

        CWT_Translator::instance()->invalidate_cache();
        wp_send_json_success( [ 'message' => __( 'Cache geleert.', 'custom-website-translator' ) ] );
    }

    public function ajax_reinstall_db(): void {
        check_ajax_referer( 'cwt_admin_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => 'Insufficient permissions.' ] );
        }

        CWT_Database::instance()->install();
        wp_send_json_success( [ 'message' => 'DB neu installiert.' ] );
    }

    /**
     * Alle Übersetzungen für eine Sprache/Seite liefern (für Inline-Sprachwechsel).
     */
    public function ajax_get_page_translations(): void {
        check_ajax_referer( 'cwt_admin_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => 'Insufficient permissions.' ] );
        }

        $lang    = sanitize_key( wp_unslash( $_POST['language_code'] ?? $_POST['lang'] ?? '' ) );
        $post_id = absint( $_POST['post_id'] ?? 0 );

        if ( $lang === '' ) {
            wp_send_json_error( [ 'message' => 'Missing language_code.' ] );
        }

        $translations = CWT_Database::instance()->get_translations_for_language( $lang );

        wp_send_json_success( [
            'lang'         => $lang,
            'post_id'      => $post_id,
            'translations' => $translations,
        ] );
    }

    /**
     * Vorhandene Übersetzungen für einen Originaltext laden.
     * Wird vom Frontend-Translate-Mode aufgerufen.
     */
    public function ajax_get_translation(): void {
        check_ajax_referer( 'cwt_admin_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => 'Insufficient permissions.' ] );
        }

        $original = sanitize_textarea_field( wp_unslash( $_POST['original'] ?? '' ) );
        if ( $original === '' ) {
            wp_send_json_error( [ 'message' => 'Missing text.' ] );
        }

        $db           = CWT_Database::instance();
        $hash         = $db->hash( $original );
        $active_langs = (array) get_option( 'cwt_active_languages', [ 'de', 'en', 'uk' ] );
        $default_lang = get_option( 'cwt_default_language', 'de' );
        $translations = [];

        foreach ( $active_langs as $lang ) {
            if ( $lang === $default_lang ) {
                continue;
            }
            $translated = $db->get_translation( $hash, $lang );
            $translations[ $lang ] = $translated ?? '';
        }

        wp_send_json_success( [
            'hash'         => $hash,
            'original'     => $original,
            'translations' => $translations,
        ] );
    }

    // -------------------------------------------------------------------------
    // Admin-Seiten
    // -------------------------------------------------------------------------

    public function page_settings(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Zugriff verweigert.', 'custom-website-translator' ) );
        }

        settings_errors( 'cwt' );
        $pages    = get_pages();
        $display  = get_option( 'cwt_switcher_display', 'all' );
        $position = get_option( 'cwt_switcher_position', 'bottom-right' );
        $sel_pages= (array) get_option( 'cwt_switcher_pages', [] );
        $fixed    = (bool) get_option( 'cwt_position_fixed', false );
        ?>
        <div class="wrap cwt-wrap">
            <h1><?php esc_html_e( 'CWT – Einstellungen', 'custom-website-translator' ); ?></h1>
            <?php $this->render_nav( 'cwt-settings' ); ?>

            <form method="post" class="cwt-form">
                <?php wp_nonce_field( 'cwt_settings_nonce' ); ?>

                <div class="cwt-card">
                    <h2><?php esc_html_e( 'Sprachumschalter-Sichtbarkeit', 'custom-website-translator' ); ?></h2>

                    <table class="form-table">
                        <tr>
                            <th><label for="cwt_switcher_display"><?php esc_html_e( 'Anzeigen auf', 'custom-website-translator' ); ?></label></th>
                            <td>
                                <select name="cwt_switcher_display" id="cwt_switcher_display">
                                    <option value="all" <?php selected( $display, 'all' ); ?>><?php esc_html_e( 'Allen Seiten', 'custom-website-translator' ); ?></option>
                                    <option value="specific" <?php selected( $display, 'specific' ); ?>><?php esc_html_e( 'Nur bestimmten Seiten', 'custom-website-translator' ); ?></option>
                                    <option value="exclude" <?php selected( $display, 'exclude' ); ?>><?php esc_html_e( 'Allen außer bestimmten', 'custom-website-translator' ); ?></option>
                                </select>
                            </td>
                        </tr>
                        <tr class="cwt-page-selector" style="<?php echo $display === 'all' ? 'display:none' : ''; ?>">
                            <th><label><?php esc_html_e( 'Seiten auswählen', 'custom-website-translator' ); ?></label></th>
                            <td>
                                <div class="cwt-page-list">
                                <?php foreach ( $pages as $page ) : ?>
                                    <label>
                                        <input type="checkbox"
                                               name="cwt_switcher_pages[]"
                                               value="<?php echo esc_attr( $page->ID ); ?>"
                                               <?php checked( in_array( $page->ID, $sel_pages, true ) ); ?>>
                                        <?php echo esc_html( $page->post_title ); ?>
                                    </label><br>
                                <?php endforeach; ?>
                                </div>
                            </td>
                        </tr>
                        <tr>
                            <th><label for="cwt_switcher_position"><?php esc_html_e( 'Position', 'custom-website-translator' ); ?></label></th>
                            <td>
                                <select name="cwt_switcher_position" id="cwt_switcher_position">
                                    <?php
                                    $positions = [
                                        'top-left'      => __( 'Oben links', 'custom-website-translator' ),
                                        'top-right'     => __( 'Oben rechts', 'custom-website-translator' ),
                                        'bottom-left'   => __( 'Unten links', 'custom-website-translator' ),
                                        'bottom-right'  => __( 'Unten rechts', 'custom-website-translator' ),
                                        'shortcode-only'=> __( 'Nur Shortcode', 'custom-website-translator' ),
                                    ];
                                    foreach ( $positions as $val => $label ) :
                                    ?>
                                        <option value="<?php echo esc_attr( $val ); ?>" <?php selected( $position, $val ); ?>>
                                            <?php echo esc_html( $label ); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th><?php esc_html_e( 'Fixed-Position', 'custom-website-translator' ); ?></th>
                            <td>
                                <label>
                                    <input type="checkbox" name="cwt_position_fixed" value="1" <?php checked( $fixed ); ?>>
                                    <?php esc_html_e( 'Switcher fest am Bildschirmrand fixieren (position: fixed)', 'custom-website-translator' ); ?>
                                </label>
                            </td>
                        </tr>
                    </table>
                </div>

                <p class="cwt-shortcode-info">
                    <?php esc_html_e( 'Shortcode:', 'custom-website-translator' ); ?>
                    <code>[custom_language_switcher]</code>
                </p>

                <p class="submit">
                    <button type="submit" name="cwt_save_settings" class="button button-primary">
                        <?php esc_html_e( 'Einstellungen speichern', 'custom-website-translator' ); ?>
                    </button>
                </p>
            </form>
        </div>
        <?php
    }

    public function page_languages(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Zugriff verweigert.', 'custom-website-translator' ) );
        }

        settings_errors( 'cwt' );
        $all_langs   = CWT_Translator::available_languages();
        $active      = (array) get_option( 'cwt_active_languages', [ 'de', 'en', 'uk' ] );
        $default     = get_option( 'cwt_default_language', 'de' );
        ?>
        <div class="wrap cwt-wrap">
            <h1><?php esc_html_e( 'CWT – Sprachen', 'custom-website-translator' ); ?></h1>
            <?php $this->render_nav( 'cwt-languages' ); ?>

            <form method="post" class="cwt-form">
                <?php wp_nonce_field( 'cwt_languages_nonce' ); ?>

                <div class="cwt-card">
                    <h2><?php esc_html_e( 'Standardsprache', 'custom-website-translator' ); ?></h2>
                    <table class="form-table">
                        <tr>
                            <th><label for="cwt_default_language"><?php esc_html_e( 'Hauptsprache', 'custom-website-translator' ); ?></label></th>
                            <td>
                                <select name="cwt_default_language" id="cwt_default_language">
                                    <?php foreach ( $all_langs as $code => $meta ) : ?>
                                        <option value="<?php echo esc_attr( $code ); ?>" <?php selected( $default, $code ); ?>>
                                            <?php echo esc_html( $meta['flag'] . ' ' . $meta['label'] . ' (' . $meta['native'] . ')' ); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <p class="description"><?php esc_html_e( 'Texte auf der Website sind in dieser Sprache verfasst.', 'custom-website-translator' ); ?></p>
                            </td>
                        </tr>
                    </table>
                </div>

                <div class="cwt-card">
                    <h2><?php esc_html_e( 'Aktive Übersetzungssprachen', 'custom-website-translator' ); ?></h2>
                    <p class="description"><?php esc_html_e( 'Wähle alle Sprachen, in die übersetzt werden soll.', 'custom-website-translator' ); ?></p>
                    <div class="cwt-lang-grid">
                        <?php foreach ( $all_langs as $code => $meta ) : ?>
                            <label class="cwt-lang-card <?php echo in_array( $code, $active, true ) ? 'cwt-lang-card--active' : ''; ?>">
                                <input type="checkbox"
                                       name="cwt_active_languages[]"
                                       value="<?php echo esc_attr( $code ); ?>"
                                       <?php checked( in_array( $code, $active, true ) ); ?>>
                                <span class="cwt-lang-flag"><?php echo esc_html( $meta['flag'] ); ?></span>
                                <span class="cwt-lang-name"><?php echo esc_html( $meta['label'] ); ?></span>
                                <span class="cwt-lang-native"><?php echo esc_html( $meta['native'] ); ?></span>
                                <span class="cwt-lang-code"><?php echo esc_html( strtoupper( $code ) ); ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>

                <p class="submit">
                    <button type="submit" name="cwt_save_languages" class="button button-primary">
                        <?php esc_html_e( 'Sprachen speichern', 'custom-website-translator' ); ?>
                    </button>
                </p>
            </form>
        </div>
        <?php
    }

    public function page_translations(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Zugriff verweigert.', 'custom-website-translator' ) );
        }

        $db           = CWT_Database::instance();
        $active_langs = (array) get_option( 'cwt_active_languages', [ 'de', 'en', 'uk' ] );
        $default_lang = get_option( 'cwt_default_language', 'de' );
        $all_langs    = CWT_Translator::available_languages();

        // Filter & Paginierung
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $paged        = max( 1, absint( $_GET['paged'] ?? 1 ) );
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $search       = sanitize_text_field( wp_unslash( $_GET['s'] ?? '' ) );
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $status_filter= sanitize_key( $_GET['status_filter'] ?? '' );
        $per_page     = 20;

        $result       = $db->get_all_originals( $per_page, $paged, $search, $status_filter );
        $items        = $result['items'];
        $total        = $result['total'];
        $total_pages  = (int) ceil( $total / $per_page );
        $target_langs = array_filter( $active_langs, fn( $l ) => $l !== $default_lang );
        ?>
        <div class="wrap cwt-wrap">
            <h1><?php esc_html_e( 'CWT – Übersetzungen', 'custom-website-translator' ); ?></h1>
            <?php $this->render_nav( 'cwt-translations' ); ?>

            <div class="cwt-translations-header">
                <form method="get" class="cwt-search-form">
                    <input type="hidden" name="page" value="cwt-translations">
                    <input type="search"
                           name="s"
                           value="<?php echo esc_attr( $search ); ?>"
                           placeholder="<?php esc_attr_e( 'Originaltext suchen...', 'custom-website-translator' ); ?>"
                           class="cwt-search-input">
                    <select name="status_filter">
                        <option value=""><?php esc_html_e( 'Alle Status', 'custom-website-translator' ); ?></option>
                        <option value="pending"  <?php selected( $status_filter, 'pending' ); ?>><?php esc_html_e( 'Ausstehend', 'custom-website-translator' ); ?></option>
                        <option value="active"   <?php selected( $status_filter, 'active' ); ?>><?php esc_html_e( 'Aktiv', 'custom-website-translator' ); ?></option>
                        <option value="ignored"  <?php selected( $status_filter, 'ignored' ); ?>><?php esc_html_e( 'Ignoriert', 'custom-website-translator' ); ?></option>
                    </select>
                    <button type="submit" class="button"><?php esc_html_e( 'Filtern', 'custom-website-translator' ); ?></button>
                </form>
                <span class="cwt-total-count">
                    <?php printf( esc_html__( '%d Einträge', 'custom-website-translator' ), $total ); ?>
                </span>
            </div>

            <?php if ( empty( $items ) ) : ?>
                <div class="cwt-empty-state">
                    <p><?php esc_html_e( 'Keine Texte gefunden. Besuche deine Website, damit Texte automatisch erkannt werden.', 'custom-website-translator' ); ?></p>
                </div>
            <?php else : ?>

                <div class="cwt-translations-table-wrap">
                    <table class="cwt-translations-table">
                        <thead>
                            <tr>
                                <th class="col-original"><?php esc_html_e( 'Originaltext', 'custom-website-translator' ); ?></th>
                                <?php foreach ( $target_langs as $lang ) : ?>
                                    <?php $meta = $all_langs[ $lang ] ?? [ 'flag' => '', 'label' => $lang ]; ?>
                                    <th class="col-translation">
                                        <?php echo esc_html( $meta['flag'] . ' ' . $meta['label'] ); ?>
                                    </th>
                                <?php endforeach; ?>
                                <th class="col-actions"><?php esc_html_e( 'Aktionen', 'custom-website-translator' ); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ( $items as $item ) :
                            $hash         = $item['text_hash'];
                            $original     = $item['original_text'];
                            $translations = $item['translations'];
                        ?>
                            <tr class="cwt-translation-row" data-hash="<?php echo esc_attr( $hash ); ?>">
                                <td class="col-original">
                                    <div class="cwt-original-text"><?php echo esc_html( $original ); ?></div>
                                    <?php if ( $item['page_url'] ) : ?>
                                        <div class="cwt-source-url">
                                            <small><?php echo esc_html( $item['page_url'] ); ?></small>
                                        </div>
                                    <?php endif; ?>
                                </td>

                                <?php foreach ( $target_langs as $lang ) :
                                    $entry   = $translations[ $lang ] ?? null;
                                    $trans   = $entry['translated_text'] ?? '';
                                    $status  = $entry['status'] ?? 'pending';
                                    $entry_id= $entry['id'] ?? 0;
                                ?>
                                    <td class="col-translation cwt-lang-cell"
                                        data-lang="<?php echo esc_attr( $lang ); ?>"
                                        data-entry-id="<?php echo esc_attr( $entry_id ); ?>">
                                        <div class="cwt-status-badge cwt-status--<?php echo esc_attr( $status ); ?>">
                                            <?php echo esc_html( $this->status_label( $status ) ); ?>
                                        </div>
                                        <textarea class="cwt-translation-input"
                                                  rows="2"
                                                  data-lang="<?php echo esc_attr( $lang ); ?>"
                                                  data-hash="<?php echo esc_attr( $hash ); ?>"
                                                  data-original="<?php echo esc_attr( $original ); ?>"
                                                  placeholder="<?php esc_attr_e( 'Übersetzung eingeben…', 'custom-website-translator' ); ?>"
                                        ><?php echo esc_textarea( $trans ); ?></textarea>
                                        <div class="cwt-cell-actions">
                                            <select class="cwt-status-select"
                                                    data-entry-id="<?php echo esc_attr( $entry_id ); ?>">
                                                <option value="active"   <?php selected( $status, 'active' ); ?>><?php esc_html_e( 'Aktiv', 'custom-website-translator' ); ?></option>
                                                <option value="pending"  <?php selected( $status, 'pending' ); ?>><?php esc_html_e( 'Ausstehend', 'custom-website-translator' ); ?></option>
                                                <option value="ignored"  <?php selected( $status, 'ignored' ); ?>><?php esc_html_e( 'Ignorieren', 'custom-website-translator' ); ?></option>
                                            </select>
                                            <button type="button"
                                                    class="button button-small cwt-save-translation"
                                                    data-lang="<?php echo esc_attr( $lang ); ?>"
                                                    data-hash="<?php echo esc_attr( $hash ); ?>"
                                                    data-original="<?php echo esc_attr( $original ); ?>">
                                                <?php esc_html_e( 'Speichern', 'custom-website-translator' ); ?>
                                            </button>
                                        </div>
                                    </td>
                                <?php endforeach; ?>

                                <td class="col-actions">
                                    <?php if ( ! empty( $translations ) ) :
                                        $first = reset( $translations );
                                    ?>
                                        <button type="button"
                                                class="button button-small button-link-delete cwt-delete-translation"
                                                data-id="<?php echo esc_attr( $first['id'] ); ?>">
                                            <?php esc_html_e( 'Löschen', 'custom-website-translator' ); ?>
                                        </button>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <?php if ( $total_pages > 1 ) : ?>
                    <div class="cwt-pagination">
                        <?php
                        $pagination_args = [
                            'base'      => add_query_arg( 'paged', '%#%' ),
                            'format'    => '',
                            'current'   => $paged,
                            'total'     => $total_pages,
                            'prev_text' => '&laquo;',
                            'next_text' => '&raquo;',
                        ];
                        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                        echo paginate_links( $pagination_args );
                        ?>
                    </div>
                <?php endif; ?>

            <?php endif; ?>
        </div>
        <?php
    }

    public function page_design(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Zugriff verweigert.', 'custom-website-translator' ) );
        }

        settings_errors( 'cwt' );

        $opts = [
            'cwt_switcher_style' => get_option( 'cwt_switcher_style', 'dropdown' ),
            'cwt_display_mode'   => get_option( 'cwt_display_mode', 'text' ),
            'cwt_bg_color'       => get_option( 'cwt_bg_color', '#ffffff' ),
            'cwt_text_color'     => get_option( 'cwt_text_color', '#333333' ),
            'cwt_border_color'   => get_option( 'cwt_border_color', '#cccccc' ),
            'cwt_hover_color'    => get_option( 'cwt_hover_color', '#f0f0f0' ),
            'cwt_border_radius'  => get_option( 'cwt_border_radius', '4' ),
            'cwt_font_size'      => get_option( 'cwt_font_size', '14' ),
            'cwt_padding'        => get_option( 'cwt_padding', '8' ),
        ];
        ?>
        <div class="wrap cwt-wrap">
            <h1><?php esc_html_e( 'CWT – Design & Dropdown', 'custom-website-translator' ); ?></h1>
            <?php $this->render_nav( 'cwt-design' ); ?>

            <form method="post" class="cwt-form">
                <?php wp_nonce_field( 'cwt_design_nonce' ); ?>

                <div class="cwt-design-columns">
                    <div class="cwt-card">
                        <h2><?php esc_html_e( 'Darstellung', 'custom-website-translator' ); ?></h2>
                        <table class="form-table">
                            <tr>
                                <th><?php esc_html_e( 'Stil', 'custom-website-translator' ); ?></th>
                                <td>
                                    <label><input type="radio" name="cwt_switcher_style" value="dropdown" <?php checked( $opts['cwt_switcher_style'], 'dropdown' ); ?>>
                                        <?php esc_html_e( 'Dropdown-Menü', 'custom-website-translator' ); ?>
                                    </label><br>
                                    <label><input type="radio" name="cwt_switcher_style" value="buttons" <?php checked( $opts['cwt_switcher_style'], 'buttons' ); ?>>
                                        <?php esc_html_e( 'Schaltflächen', 'custom-website-translator' ); ?>
                                    </label>
                                </td>
                            </tr>
                            <tr>
                                <th><?php esc_html_e( 'Sprach-Anzeige', 'custom-website-translator' ); ?></th>
                                <td>
                                    <label><input type="radio" name="cwt_display_mode" value="text" <?php checked( $opts['cwt_display_mode'], 'text' ); ?>>
                                        <?php esc_html_e( 'Text', 'custom-website-translator' ); ?>
                                    </label><br>
                                    <label><input type="radio" name="cwt_display_mode" value="flag" <?php checked( $opts['cwt_display_mode'], 'flag' ); ?>>
                                        <?php esc_html_e( 'Flagge', 'custom-website-translator' ); ?>
                                    </label><br>
                                    <label><input type="radio" name="cwt_display_mode" value="both" <?php checked( $opts['cwt_display_mode'], 'both' ); ?>>
                                        <?php esc_html_e( 'Text + Flagge', 'custom-website-translator' ); ?>
                                    </label>
                                </td>
                            </tr>
                        </table>
                    </div>

                    <div class="cwt-card">
                        <h2><?php esc_html_e( 'Farben', 'custom-website-translator' ); ?></h2>
                        <table class="form-table">
                            <?php
                            $color_fields = [
                                'cwt_bg_color'     => __( 'Hintergrundfarbe', 'custom-website-translator' ),
                                'cwt_text_color'   => __( 'Textfarbe', 'custom-website-translator' ),
                                'cwt_border_color' => __( 'Rahmenfarbe', 'custom-website-translator' ),
                                'cwt_hover_color'  => __( 'Hover-Hintergrund', 'custom-website-translator' ),
                            ];
                            foreach ( $color_fields as $key => $label ) :
                            ?>
                                <tr>
                                    <th><label for="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></label></th>
                                    <td>
                                        <input type="text"
                                               id="<?php echo esc_attr( $key ); ?>"
                                               name="<?php echo esc_attr( $key ); ?>"
                                               value="<?php echo esc_attr( $opts[ $key ] ); ?>"
                                               class="cwt-color-picker">
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </table>
                    </div>

                    <div class="cwt-card">
                        <h2><?php esc_html_e( 'Abstände & Größen', 'custom-website-translator' ); ?></h2>
                        <table class="form-table">
                            <?php
                            $num_fields = [
                                'cwt_border_radius' => [ __( 'Border-Radius (px)', 'custom-website-translator' ), 0, 50 ],
                                'cwt_font_size'     => [ __( 'Schriftgröße (px)', 'custom-website-translator' ), 8, 32 ],
                                'cwt_padding'       => [ __( 'Padding (px)', 'custom-website-translator' ), 0, 40 ],
                            ];
                            foreach ( $num_fields as $key => [$label, $min, $max] ) :
                            ?>
                                <tr>
                                    <th><label for="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></label></th>
                                    <td>
                                        <input type="number"
                                               id="<?php echo esc_attr( $key ); ?>"
                                               name="<?php echo esc_attr( $key ); ?>"
                                               value="<?php echo esc_attr( $opts[ $key ] ); ?>"
                                               min="<?php echo esc_attr( $min ); ?>"
                                               max="<?php echo esc_attr( $max ); ?>"
                                               class="small-text">
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </table>
                    </div>
                </div>

                <div class="cwt-card cwt-preview-card">
                    <h2><?php esc_html_e( 'Vorschau', 'custom-website-translator' ); ?></h2>
                    <div id="cwt-design-preview">
                        <!-- Wird per JS aktualisiert -->
                        <?php
                        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                        echo CWT_Language_Switcher::instance()->render( true );
                        ?>
                    </div>
                </div>

                <p class="submit">
                    <button type="submit" name="cwt_save_design" class="button button-primary">
                        <?php esc_html_e( 'Design speichern', 'custom-website-translator' ); ?>
                    </button>
                </p>
            </form>
        </div>
        <?php
    }

    public function page_import_export(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Zugriff verweigert.', 'custom-website-translator' ) );
        }

        settings_errors( 'cwt' );
        ?>
        <div class="wrap cwt-wrap">
            <h1><?php esc_html_e( 'CWT – Import / Export', 'custom-website-translator' ); ?></h1>
            <?php $this->render_nav( 'cwt-import-export' ); ?>

            <div class="cwt-columns-2">
                <div class="cwt-card">
                    <h2><?php esc_html_e( 'Export', 'custom-website-translator' ); ?></h2>
                    <p><?php esc_html_e( 'Alle Übersetzungen als JSON-Datei herunterladen.', 'custom-website-translator' ); ?></p>
                    <form method="post">
                        <?php wp_nonce_field( 'cwt_admin_nonce', '_wpnonce' ); ?>
                        <button type="button" id="cwt-export-btn" class="button button-primary">
                            <?php esc_html_e( 'JSON exportieren', 'custom-website-translator' ); ?>
                        </button>
                    </form>
                </div>

                <div class="cwt-card">
                    <h2><?php esc_html_e( 'Import', 'custom-website-translator' ); ?></h2>
                    <p><?php esc_html_e( 'JSON-Datei importieren (zuvor exportiertes Format).', 'custom-website-translator' ); ?></p>
                    <form method="post" enctype="multipart/form-data">
                        <?php wp_nonce_field( 'cwt_import_nonce' ); ?>
                        <input type="file" name="cwt_import_file" accept=".json" class="cwt-file-input">
                        <br><br>
                        <button type="submit" name="cwt_do_import" class="button button-primary">
                            <?php esc_html_e( 'JSON importieren', 'custom-website-translator' ); ?>
                        </button>
                    </form>
                </div>
            </div>
        </div>
        <?php
    }

    public function page_debug(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Zugriff verweigert.', 'custom-website-translator' ) );
        }

        global $wpdb;
        $stats       = CWT_Database::instance()->get_stats();
        $table       = CWT_Database::instance()->get_table_translations();
        $table_exists= $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table;
        $php_version = PHP_VERSION;
        $wp_version  = get_bloginfo( 'version' );
        $active_lang = CWT_Translator::instance()->get_current_language();
        ?>
        <div class="wrap cwt-wrap">
            <h1><?php esc_html_e( 'CWT – Debug / Status', 'custom-website-translator' ); ?></h1>
            <?php $this->render_nav( 'cwt-debug' ); ?>

            <div class="cwt-columns-2">
                <div class="cwt-card">
                    <h2><?php esc_html_e( 'System-Status', 'custom-website-translator' ); ?></h2>
                    <table class="cwt-status-table">
                        <tr>
                            <td><?php esc_html_e( 'Plugin-Version', 'custom-website-translator' ); ?></td>
                            <td><strong><?php echo esc_html( CWT_VERSION ); ?></strong></td>
                        </tr>
                        <tr>
                            <td><?php esc_html_e( 'WordPress', 'custom-website-translator' ); ?></td>
                            <td><strong><?php echo esc_html( $wp_version ); ?></strong></td>
                        </tr>
                        <tr>
                            <td><?php esc_html_e( 'PHP', 'custom-website-translator' ); ?></td>
                            <td><strong><?php echo esc_html( $php_version ); ?></strong>
                                <?php if ( version_compare( $php_version, '8.1', '>=' ) ) : ?>
                                    <span class="cwt-ok">✓</span>
                                <?php else : ?>
                                    <span class="cwt-warn">PHP 8.1+ empfohlen</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <tr>
                            <td><?php esc_html_e( 'Datenbanktabelle', 'custom-website-translator' ); ?></td>
                            <td>
                                <?php if ( $table_exists ) : ?>
                                    <span class="cwt-ok">✓ <?php echo esc_html( $table ); ?></span>
                                <?php else : ?>
                                    <span class="cwt-error">✗ <?php esc_html_e( 'Tabelle fehlt!', 'custom-website-translator' ); ?></span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <tr>
                            <td><?php esc_html_e( 'DOM-Extension', 'custom-website-translator' ); ?></td>
                            <td>
                                <?php if ( extension_loaded( 'dom' ) ) : ?>
                                    <span class="cwt-ok">✓ geladen</span>
                                <?php else : ?>
                                    <span class="cwt-error">✗ fehlt – Plugin kann Texte nicht übersetzen!</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <tr>
                            <td><?php esc_html_e( 'Aktive Sprache (aktueller Request)', 'custom-website-translator' ); ?></td>
                            <td><strong><?php echo esc_html( strtoupper( $active_lang ) ); ?></strong></td>
                        </tr>
                    </table>
                </div>

                <div class="cwt-card">
                    <h2><?php esc_html_e( 'Übersetzungs-Statistiken', 'custom-website-translator' ); ?></h2>
                    <table class="cwt-status-table">
                        <tr>
                            <td><?php esc_html_e( 'Einzigartige Originaltexte', 'custom-website-translator' ); ?></td>
                            <td><strong><?php echo esc_html( $stats['total_originals'] ); ?></strong></td>
                        </tr>
                        <tr>
                            <td><?php esc_html_e( 'Aktive Übersetzungen', 'custom-website-translator' ); ?></td>
                            <td><strong class="cwt-ok"><?php echo esc_html( $stats['active'] ); ?></strong></td>
                        </tr>
                        <tr>
                            <td><?php esc_html_e( 'Ausstehend', 'custom-website-translator' ); ?></td>
                            <td><strong class="cwt-warn"><?php echo esc_html( $stats['pending'] ); ?></strong></td>
                        </tr>
                        <tr>
                            <td><?php esc_html_e( 'Ignoriert', 'custom-website-translator' ); ?></td>
                            <td><strong><?php echo esc_html( $stats['ignored'] ); ?></strong></td>
                        </tr>
                    </table>

                    <br>
                    <button type="button" id="cwt-clear-cache" class="button">
                        <?php esc_html_e( 'Übersetzungs-Cache leeren', 'custom-website-translator' ); ?>
                    </button>
                    <button type="button" id="cwt-reinstall-db" class="button">
                        <?php esc_html_e( 'Datenbank neu installieren', 'custom-website-translator' ); ?>
                    </button>
                </div>
            </div>
        </div>
        <?php
    }

    // -------------------------------------------------------------------------
    // Hilfsmethoden
    // -------------------------------------------------------------------------

    private function render_nav( string $current ): void {
        $tabs = [
            'cwt-settings'      => __( 'Einstellungen', 'custom-website-translator' ),
            'cwt-languages'     => __( 'Sprachen', 'custom-website-translator' ),
            'cwt-translations'  => __( 'Übersetzungen', 'custom-website-translator' ),
            'cwt-design'        => __( 'Design', 'custom-website-translator' ),
            'cwt-import-export' => __( 'Import / Export', 'custom-website-translator' ),
            'cwt-debug'         => __( 'Debug', 'custom-website-translator' ),
        ];
        echo '<nav class="cwt-tab-nav">';
        foreach ( $tabs as $slug => $label ) {
            $class = $slug === $current ? 'cwt-tab cwt-tab--active' : 'cwt-tab';
            $url   = esc_url( admin_url( 'admin.php?page=' . $slug ) );
            echo '<a href="' . $url . '" class="' . esc_attr( $class ) . '">' . esc_html( $label ) . '</a>';
        }
        echo '</nav>';
    }

    private function status_label( string $status ): string {
        return match ( $status ) {
            'active'  => __( 'Aktiv', 'custom-website-translator' ),
            'ignored' => __( 'Ignoriert', 'custom-website-translator' ),
            default   => __( 'Ausstehend', 'custom-website-translator' ),
        };
    }

    public function plugin_action_links( array $links ): array {
        $settings_link = '<a href="' . esc_url( admin_url( 'admin.php?page=cwt-settings' ) ) . '">'
                       . esc_html__( 'Einstellungen', 'custom-website-translator' )
                       . '</a>';
        array_unshift( $links, $settings_link );
        return $links;
    }
}
