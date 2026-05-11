<?php
defined( 'ABSPATH' ) || exit;

/**
 * Datenbankoperationen: Tabellenerstellung und CRUD für Übersetzungen.
 */
class CWT_Database {

    private static ?self $instance = null;

    /** @var string */
    private string $table_translations;

    /** @var string */
    private string $table_settings;

    private function __construct() {
        global $wpdb;
        $this->table_translations = $wpdb->prefix . 'cwt_translations';
        $this->table_settings     = $wpdb->prefix . 'cwt_settings';
    }

    public static function instance(): self {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    // -------------------------------------------------------------------------
    // Schema-Installation
    // -------------------------------------------------------------------------

    public function install(): void {
        global $wpdb;

        $charset_collate = $wpdb->get_charset_collate();

        $sql_translations = "CREATE TABLE IF NOT EXISTS {$this->table_translations} (
            id            BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            original_text LONGTEXT            NOT NULL,
            text_hash     VARCHAR(64)         NOT NULL,
            language_code VARCHAR(10)         NOT NULL,
            translated_text LONGTEXT          NOT NULL DEFAULT '',
            status        ENUM('active','ignored','pending') NOT NULL DEFAULT 'pending',
            page_url      VARCHAR(2083)       NOT NULL DEFAULT '',
            created_at    DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at    DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY hash_lang (text_hash(64), language_code),
            KEY status_idx (status),
            KEY language_idx (language_code)
        ) $charset_collate;";

        $sql_settings = "CREATE TABLE IF NOT EXISTS {$this->table_settings} (
            id            BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            setting_key   VARCHAR(191)        NOT NULL,
            setting_value LONGTEXT,
            PRIMARY KEY (id),
            UNIQUE KEY setting_key (setting_key)
        ) $charset_collate;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql_translations );
        dbDelta( $sql_settings );
    }

    // -------------------------------------------------------------------------
    // Übersetzungen abrufen
    // -------------------------------------------------------------------------

    /**
     * Alle aktiven Übersetzungen für eine Sprache laden (für Caching).
     *
     * @param string $language_code z.B. 'en', 'uk'
     * @return array<string, string>  hash => translated_text
     */
    public function get_translations_for_language( string $language_code ): array {
        global $wpdb;

        $results = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT text_hash, translated_text
                 FROM {$this->table_translations}
                 WHERE language_code = %s
                   AND status = 'active'
                   AND translated_text != ''",
                $language_code
            ),
            ARRAY_A
        );

        $map = [];
        foreach ( $results as $row ) {
            $map[ $row['text_hash'] ] = $row['translated_text'];
        }
        return $map;
    }

    /**
     * Einzelne Übersetzung per Hash und Sprache abrufen.
     */
    public function get_translation( string $hash, string $language_code ): ?string {
        global $wpdb;

        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT translated_text, status
                 FROM {$this->table_translations}
                 WHERE text_hash = %s AND language_code = %s",
                $hash,
                $language_code
            ),
            ARRAY_A
        );

        if ( ! $row || $row['status'] === 'ignored' ) {
            return null;
        }
        return $row['translated_text'] !== '' ? $row['translated_text'] : null;
    }

    // -------------------------------------------------------------------------
    // Übersetzungen speichern / aktualisieren
    // -------------------------------------------------------------------------

    /**
     * Übersetzung einfügen oder aktualisieren.
     *
     * @param string $original_text
     * @param string $language_code
     * @param string $translated_text
     * @param string $status           'active'|'pending'|'ignored'
     * @param string $page_url
     * @return bool
     */
    public function upsert_translation(
        string $original_text,
        string $language_code,
        string $translated_text = '',
        string $status = 'pending',
        string $page_url = ''
    ): bool {
        global $wpdb;

        $hash = $this->hash( $original_text );

        $existing = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT id FROM {$this->table_translations}
                 WHERE text_hash = %s AND language_code = %s",
                $hash,
                $language_code
            )
        );

        if ( $existing ) {
            $result = $wpdb->update(
                $this->table_translations,
                [
                    'translated_text' => $translated_text,
                    'status'          => $status,
                    'page_url'        => $page_url,
                    'updated_at'      => current_time( 'mysql' ),
                ],
                [ 'id' => $existing ],
                [ '%s', '%s', '%s', '%s' ],
                [ '%d' ]
            );
        } else {
            $result = $wpdb->insert(
                $this->table_translations,
                [
                    'original_text'   => $original_text,
                    'text_hash'       => $hash,
                    'language_code'   => $language_code,
                    'translated_text' => $translated_text,
                    'status'          => $status,
                    'page_url'        => $page_url,
                    'created_at'      => current_time( 'mysql' ),
                    'updated_at'      => current_time( 'mysql' ),
                ],
                [ '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' ]
            );
        }

        return $result !== false;
    }

    /**
     * Status einer Übersetzung ändern.
     */
    public function update_status( int $id, string $status ): bool {
        global $wpdb;

        $allowed = [ 'active', 'pending', 'ignored' ];
        if ( ! in_array( $status, $allowed, true ) ) {
            return false;
        }

        return (bool) $wpdb->update(
            $this->table_translations,
            [ 'status' => $status ],
            [ 'id'     => $id ],
            [ '%s' ],
            [ '%d' ]
        );
    }

    /**
     * Übersetzungseintrag löschen (einzelner Eintrag per ID).
     */
    public function delete_translation( int $id ): bool {
        global $wpdb;

        return (bool) $wpdb->delete(
            $this->table_translations,
            [ 'id' => $id ],
            [ '%d' ]
        );
    }

    /**
     * Alle Übersetzungseinträge eines Originaltexts löschen (alle Sprachen).
     */
    public function delete_by_hash( string $hash ): bool {
        global $wpdb;

        $result = $wpdb->delete(
            $this->table_translations,
            [ 'text_hash' => $hash ],
            [ '%s' ]
        );

        return $result !== false && $result > 0;
    }

    // -------------------------------------------------------------------------
    // Admin-Listen
    // -------------------------------------------------------------------------

    /**
     * Alle einzigartigen Originaltexte mit ihren Übersetzungen abrufen.
     *
     * @param int    $per_page
     * @param int    $paged
     * @param string $search
     * @param string $status_filter
     * @return array{ items: array, total: int }
     */
    public function get_all_originals(
        int $per_page = 20,
        int $paged = 1,
        string $search = '',
        string $status_filter = ''
    ): array {
        global $wpdb;

        $offset = ( $paged - 1 ) * $per_page;

        $where  = 'WHERE 1=1';
        $params = [];

        // Status-Filter innerhalb der Unterabfrage anwenden
        $allowed_statuses = [ 'active', 'pending', 'ignored' ];
        $inner_cond = '';
        if ( $status_filter !== '' && in_array( $status_filter, $allowed_statuses, true ) ) {
            $inner_cond = $wpdb->prepare( 'AND status = %s', $status_filter );
        }

        // Deduplizierte Übersicht: eine Zeile pro Original-Hash
        $base_sql = "FROM (
            SELECT DISTINCT text_hash, original_text, MIN(page_url) AS page_url, MIN(created_at) AS created_at
            FROM {$this->table_translations}
            WHERE 1=1 {$inner_cond}
            GROUP BY text_hash, original_text
        ) AS originals";

        if ( $search !== '' ) {
            $where   .= ' AND originals.original_text LIKE %s';
            $params[] = '%' . $wpdb->esc_like( $search ) . '%';
        }

        // Count
        $count_sql = "SELECT COUNT(*) $base_sql $where";
        if ( $params ) {
            $total = (int) $wpdb->get_var( $wpdb->prepare( $count_sql, ...$params ) );
        } else {
            $total = (int) $wpdb->get_var( $count_sql );
        }

        // Items
        $order_sql = 'ORDER BY originals.created_at DESC';
        $limit_sql = $wpdb->prepare( 'LIMIT %d OFFSET %d', $per_page, $offset );
        $items_sql = "SELECT originals.text_hash, originals.original_text, originals.page_url, originals.created_at
                      $base_sql $where $order_sql $limit_sql";

        if ( $params ) {
            $items = $wpdb->get_results( $wpdb->prepare( $items_sql, ...$params ), ARRAY_A );
        } else {
            $items = $wpdb->get_results( $items_sql, ARRAY_A );
        }

        if ( ! $items ) {
            return [ 'items' => [], 'total' => $total ];
        }

        // Übersetzungen für alle gefundenen Hashes laden
        $hashes      = array_column( $items, 'text_hash' );
        $placeholders = implode( ',', array_fill( 0, count( $hashes ), '%s' ) );

        $trans_rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT text_hash, language_code, translated_text, status, id
                 FROM {$this->table_translations}
                 WHERE text_hash IN ($placeholders)",
                ...$hashes
            ),
            ARRAY_A
        );

        // Indexieren
        $trans_index = [];
        foreach ( $trans_rows as $row ) {
            $trans_index[ $row['text_hash'] ][ $row['language_code'] ] = $row;
        }

        foreach ( $items as &$item ) {
            $item['translations'] = $trans_index[ $item['text_hash'] ] ?? [];
        }
        unset( $item );

        return [ 'items' => $items, 'total' => $total ];
    }

    // -------------------------------------------------------------------------
    // Hilfsmethoden
    // -------------------------------------------------------------------------

    public function hash( string $text ): string {
        return hash( 'sha256', trim( $text ) );
    }

    public function get_table_translations(): string {
        return $this->table_translations;
    }

    /**
     * Statistiken für das Dashboard.
     *
     * @return array<string, int>
     */
    public function get_stats(): array {
        global $wpdb;

        $row = $wpdb->get_row(
            "SELECT
                COUNT(DISTINCT text_hash) AS total_originals,
                SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) AS active,
                SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) AS pending,
                SUM(CASE WHEN status = 'ignored' THEN 1 ELSE 0 END) AS ignored
             FROM {$this->table_translations}",
            ARRAY_A
        );

        return [
            'total_originals' => (int) ( $row['total_originals'] ?? 0 ),
            'active'          => (int) ( $row['active'] ?? 0 ),
            'pending'         => (int) ( $row['pending'] ?? 0 ),
            'ignored'         => (int) ( $row['ignored'] ?? 0 ),
        ];
    }
}
