<?php
defined( 'ABSPATH' ) || exit;

/**
 * All database operations: schema creation, CRUD, and stats.
 *
 * Uses a singleton so the table name strings are only computed once.
 */
class CWT_Database {

	private static ?self $instance = null;

	private string $table_translations;
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

	/**
	 * Create or update the database tables via dbDelta.
	 * Safe to call repeatedly — dbDelta only adds missing columns/tables.
	 */
	public function install(): void {
		global $wpdb;

		$charset_collate = $wpdb->get_charset_collate();

		$sql_translations = "CREATE TABLE IF NOT EXISTS {$this->table_translations} (
			id              BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			post_id         BIGINT(20) UNSIGNED NULL DEFAULT NULL,
			original_text   LONGTEXT            NOT NULL,
			normalized_text LONGTEXT            NOT NULL DEFAULT '',
			text_hash       VARCHAR(64)         NOT NULL,
			language_code   VARCHAR(10)         NOT NULL,
			translated_text LONGTEXT            NOT NULL DEFAULT '',
			status          ENUM('active','ignored','pending') NOT NULL DEFAULT 'pending',
			page_url        VARCHAR(2083)       NOT NULL DEFAULT '',
			created_at      DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at      DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			UNIQUE KEY hash_lang (text_hash(64), language_code),
			KEY status_idx (status),
			KEY language_idx (language_code),
			KEY post_id_idx (post_id)
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

	/**
	 * Load all active translations for a language into a hash => text map.
	 * This is the bulk fetch used to populate the in-memory cache.
	 *
	 * @return array<string, string>
	 */
	public function get_translations_for_language( string $language_code ): array {
		global $wpdb;

		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT text_hash, translated_text
				 FROM {$this->table_translations}
				 WHERE language_code = %s AND status = 'active' AND translated_text != ''",
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
	 * Fetch a single translation. Returns null if ignored or not yet translated.
	 */
	public function get_translation( string $hash, string $language_code ): ?string {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT translated_text, status FROM {$this->table_translations}
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

	/**
	 * Insert or update a translation row.
	 * The hash is always derived from $original_text so the caller never needs to pass it.
	 */
	public function upsert_translation(
		string $original_text,
		string $language_code,
		string $translated_text = '',
		string $status = 'pending',
		string $page_url = '',
		int    $post_id = 0
	): bool {
		global $wpdb;

		$hash = $this->hash( $original_text );
		$now  = current_time( 'mysql' );

		$existing = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$this->table_translations}
				 WHERE text_hash = %s AND language_code = %s",
				$hash,
				$language_code
			)
		);

		if ( $existing ) {
			$data   = [
				'translated_text' => $translated_text,
				'status'          => $status,
				'page_url'        => $page_url,
				'updated_at'      => $now,
			];
			$format = [ '%s', '%s', '%s', '%s' ];

			if ( $post_id > 0 ) {
				$data['post_id'] = $post_id;
				$format[]        = '%d';
			}

			$result = $wpdb->update( $this->table_translations, $data, [ 'id' => $existing ], $format, [ '%d' ] );
		} else {
			$data   = [
				'original_text'   => $original_text,
				'text_hash'       => $hash,
				'language_code'   => $language_code,
				'translated_text' => $translated_text,
				'status'          => $status,
				'page_url'        => $page_url,
				'created_at'      => $now,
				'updated_at'      => $now,
			];
			$format = [ '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' ];

			// Guard against sites that haven't migrated to the latest schema yet.
			// column_exists() is cached so this won't hammer the DB on every request.
			if ( $this->column_exists( 'normalized_text' ) ) {
				$data['normalized_text'] = $this->normalize( $original_text );
				$format[]                = '%s';
			}

			if ( $post_id > 0 && $this->column_exists( 'post_id' ) ) {
				$data['post_id'] = $post_id;
				$format[]        = '%d';
			}

			$result = $wpdb->insert( $this->table_translations, $data, $format );
		}

		return $result !== false;
	}

	public function update_status( int $id, string $status ): bool {
		global $wpdb;

		if ( ! in_array( $status, [ 'active', 'pending', 'ignored' ], true ) ) {
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

	public function delete_translation( int $id ): bool {
		global $wpdb;

		return (bool) $wpdb->delete( $this->table_translations, [ 'id' => $id ], [ '%d' ] );
	}

	/**
	 * Delete every language entry for a given original text.
	 * Used when the admin removes a string from the translations table.
	 */
	public function delete_by_hash( string $hash ): bool {
		global $wpdb;

		$result = $wpdb->delete( $this->table_translations, [ 'text_hash' => $hash ], [ '%s' ] );

		return $result !== false && $result > 0;
	}

	/**
	 * Return paginated unique originals with their per-language translations attached.
	 * The inner subquery deduplicates by hash so each source string appears once.
	 *
	 * @return array{ items: array, total: int }
	 */
	public function get_all_originals(
		int    $per_page = 20,
		int    $paged = 1,
		string $search = '',
		string $status_filter = ''
	): array {
		global $wpdb;

		$offset     = ( $paged - 1 ) * $per_page;
		$where      = 'WHERE 1=1';
		$params     = [];
		$inner_cond = '';

		if ( $status_filter !== '' && in_array( $status_filter, [ 'active', 'pending', 'ignored' ], true ) ) {
			$inner_cond = $wpdb->prepare( 'AND status = %s', $status_filter );
		}

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

		$count_sql = "SELECT COUNT(*) $base_sql $where";
		$total     = (int) ( $params
			? $wpdb->get_var( $wpdb->prepare( $count_sql, ...$params ) )
			: $wpdb->get_var( $count_sql ) );

		$limit_sql = $wpdb->prepare( 'LIMIT %d OFFSET %d', $per_page, $offset );
		$items_sql = "SELECT originals.text_hash, originals.original_text, originals.page_url, originals.created_at
					  $base_sql $where ORDER BY originals.created_at DESC $limit_sql";

		$items = $params
			? $wpdb->get_results( $wpdb->prepare( $items_sql, ...$params ), ARRAY_A )
			: $wpdb->get_results( $items_sql, ARRAY_A );

		if ( ! $items ) {
			return [ 'items' => [], 'total' => $total ];
		}

		// Fetch all translations for the returned hashes in a single query.
		$hashes       = array_column( $items, 'text_hash' );
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

		$index = [];
		foreach ( $trans_rows as $row ) {
			$index[ $row['text_hash'] ][ $row['language_code'] ] = $row;
		}

		foreach ( $items as &$item ) {
			$item['translations'] = $index[ $item['text_hash'] ] ?? [];
		}
		unset( $item );

		return [ 'items' => $items, 'total' => $total ];
	}

	/** SHA-256 hash of the trimmed text. Used as the lookup key throughout. */
	public function hash( string $text ): string {
		return hash( 'sha256', trim( $text ) );
	}

	/** Collapse internal whitespace so minor formatting differences don't create duplicate entries. */
	public function normalize( string $text ): string {
		return preg_replace( '/\s+/', ' ', trim( $text ) ) ?? trim( $text );
	}

	public function get_table_translations(): string {
		return $this->table_translations;
	}

	/** Counts for the Debug/Status dashboard. */
	public function get_stats(): array {
		global $wpdb;

		$row = $wpdb->get_row(
			"SELECT
				COUNT(DISTINCT text_hash) AS total_originals,
				SUM(CASE WHEN status = 'active'  THEN 1 ELSE 0 END) AS active,
				SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) AS pending,
				SUM(CASE WHEN status = 'ignored' THEN 1 ELSE 0 END) AS ignored
			 FROM {$this->table_translations}",
			ARRAY_A
		);

		return [
			'total_originals' => (int) ( $row['total_originals'] ?? 0 ),
			'active'          => (int) ( $row['active']          ?? 0 ),
			'pending'         => (int) ( $row['pending']         ?? 0 ),
			'ignored'         => (int) ( $row['ignored']         ?? 0 ),
		];
	}

	/**
	 * Check whether a column exists in the translations table.
	 * Result is cached for an hour so repeated calls are free.
	 */
	private function column_exists( string $column ): bool {
		$cache_key = 'cwt_col_' . $column;
		$cached    = wp_cache_get( $cache_key, 'cwt' );

		if ( $cached !== false ) {
			return (bool) $cached;
		}

		global $wpdb;
		$exists = (bool) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM information_schema.COLUMNS
				 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = %s',
				$this->table_translations,
				$column
			)
		);

		wp_cache_set( $cache_key, $exists, 'cwt', 3600 );

		return $exists;
	}
}
