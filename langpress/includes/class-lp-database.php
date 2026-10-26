<?php
defined( 'ABSPATH' ) || exit;

/**
 * All database operations: schema creation, CRUD, and stats.
 *
 * Uses a singleton so the table name strings are only computed once.
 */
class LP_Database {

	private static ?self $instance = null;

	private string $table_translations;
	private string $table_settings;

	/** Per-request memo for column_exists(), so even without a persistent cache we query once. */
	private array $columns = [];

	private function __construct() {
		global $wpdb;
		$this->table_translations = $wpdb->prefix . 'lp_translations';
		$this->table_settings     = $wpdb->prefix . 'lp_settings';
	}

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Create or update the database tables via dbDelta.
	 * Safe to call repeatedly: dbDelta only adds missing columns/tables.
	 * Returns false if the resulting schema is still incomplete.
	 *
	 * Also handles migration from the old cwt_ table prefix (renamed from
	 * Custom Website Translator to LangPress) so existing data is preserved.
	 */
	public function install(): bool {
		global $wpdb;

		// Migrate data from the old plugin's table if it still exists.
		// This runs once and is a no-op afterwards. Only rename onto a free name,
		// so an existing LangPress table is never touched, and check the outcome
		// rather than the return value: the SQLite driver rejects RENAME TABLE and
		// reports success for ALTER TABLE … RENAME TO without renaming anything.
		$old_table = $wpdb->prefix . 'cwt_translations';
		$has_old   = $this->table_exists( $old_table );
		if ( $has_old && ! $this->table_exists( $this->table_translations ) ) {
			$suppress = $wpdb->suppress_errors( true );
			$wpdb->query( "RENAME TABLE `{$old_table}` TO `{$this->table_translations}`" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->suppress_errors( $suppress );
			$has_old = $this->table_exists( $old_table );
		}

		$charset_collate = $wpdb->get_charset_collate();

		// No "IF NOT EXISTS": dbDelta() parses the table name from "CREATE TABLE <name>",
		// and with that clause it never diffs the existing table, so new columns were
		// silently never added on upgrade. TEXT columns carry no DEFAULT because MySQL
		// rejects one, which made every dbDelta() run retry a failing ALTER.
		// normalized_text is nullable so it can be added to a table that already has rows.
		$sql_translations = "CREATE TABLE {$this->table_translations} (
			id              BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			post_id         BIGINT(20) UNSIGNED NULL DEFAULT NULL,
			original_text   LONGTEXT            NOT NULL,
			normalized_text LONGTEXT            NULL,
			text_hash       VARCHAR(64)         NOT NULL,
			language_code   VARCHAR(10)         NOT NULL,
			translated_text LONGTEXT            NOT NULL,
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

		$sql_settings = "CREATE TABLE {$this->table_settings} (
			id            BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			setting_key   VARCHAR(191)        NOT NULL,
			setting_value LONGTEXT,
			PRIMARY KEY (id),
			UNIQUE KEY setting_key (setting_key)
		) $charset_collate;";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql_translations );
		dbDelta( $sql_settings );

		// The rename was not possible: copy the old rows into the new, still empty
		// table, keeping their IDs. The old table stays behind as a backup.
		if ( $has_old && ! $wpdb->get_var( "SELECT id FROM {$this->table_translations} LIMIT 1" ) ) {
			$columns = 'id, original_text, text_hash, language_code, translated_text, status, page_url, created_at, updated_at';
			$wpdb->query( "INSERT INTO {$this->table_translations} ($columns) SELECT $columns FROM `{$old_table}`" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}

		// The schema may have just changed, so forget what we knew about it.
		$this->columns = [];
		foreach ( self::LATE_COLUMNS as $column ) {
			wp_cache_delete( 'lp_col_' . $column, 'lp' );
		}

		return $this->schema_is_current();
	}

	private function table_exists( string $table ): bool {
		global $wpdb;
		return $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) === $table;
	}

	/** Columns added after the first release; their presence marks an upgraded table. */
	private const LATE_COLUMNS = [ 'normalized_text', 'post_id' ];

	/**
	 * Whether the translations table exists with every column the code writes.
	 * dbDelta() reports nothing when an ALTER fails, so callers check this
	 * instead of assuming an existing table is an upgraded one.
	 */
	public function schema_is_current(): bool {
		foreach ( array_merge( [ 'text_hash', 'translated_text' ], self::LATE_COLUMNS ) as $column ) {
			if ( ! $this->column_exists( $column ) ) {
				return false;
			}
		}
		return true;
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
	 * Insert or update a translation row in a single atomic statement.
	 * The hash is always derived from $original_text so the caller never needs to pass it.
	 *
	 * A SELECT-then-INSERT here lost writes when two requests registered or saved
	 * the same string at once (the second INSERT hit the hash_lang unique key).
	 * INSERT ... ON DUPLICATE KEY UPDATE lets the database resolve that race.
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

		$now  = current_time( 'mysql' );
		$data = [
			'original_text'   => [ $original_text, '%s' ],
			'text_hash'       => [ $this->hash( $original_text ), '%s' ],
			'language_code'   => [ $language_code, '%s' ],
			'translated_text' => [ $translated_text, '%s' ],
			'status'          => [ $status, '%s' ],
			'page_url'        => [ $this->clean_url( $page_url ), '%s' ],
			'created_at'      => [ $now, '%s' ],
			'updated_at'      => [ $now, '%s' ],
		];

		// Guard against sites that haven't migrated to the latest schema yet.
		if ( $this->column_exists( 'normalized_text' ) ) {
			$data['normalized_text'] = [ $this->normalize( $original_text ), '%s' ];
		}
		$with_post_id = $post_id > 0 && $this->column_exists( 'post_id' );
		if ( $with_post_id ) {
			$data['post_id'] = [ $post_id, '%d' ];
		}

		if ( $translated_text === '' ) {
			// Empty placeholder (auto-registration): create the row if missing, never
			// touch an existing one, so active translations are not wiped.
			$on_duplicate = 'id = id';
		} else {
			// VALUES() is what core's add_option() uses; it works on MySQL and MariaDB.
			// An empty page_url means "unknown", so keep the URL recorded at registration.
			$on_duplicate = 'translated_text = VALUES(translated_text), status = VALUES(status), updated_at = VALUES(updated_at),'
						  . " page_url = IF(VALUES(page_url) = '', page_url, VALUES(page_url))"
						  . ( $with_post_id ? ', post_id = VALUES(post_id)' : '' );
		}

		$sql = sprintf(
			'INSERT INTO %s (%s) VALUES (%s) ON DUPLICATE KEY UPDATE %s',
			$this->table_translations,
			implode( ', ', array_keys( $data ) ),
			implode( ', ', array_column( $data, 1 ) ),
			$on_duplicate
		);

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- placeholders built above, values bound here.
		return false !== $wpdb->query( $wpdb->prepare( $sql, ...array_column( $data, 0 ) ) );
	}

	/**
	 * Register many source strings as pending for each target language.
	 *
	 * One SELECT per chunk of hashes finds rows that already exist, and the
	 * missing ones go in as multi-row inserts, instead of a SELECT plus an
	 * INSERT per string per language. ON DUPLICATE KEY keeps concurrent scans
	 * of the same page harmless.
	 *
	 * @param string[] $texts     Trimmed source strings.
	 * @param string[] $languages Target language codes.
	 * @param int      $max_new   Stop after this many strings needed new rows.
	 * @return array{rows: int, complete: bool} Rows written, and false for
	 *         `complete` if $max_new was reached or an insert failed.
	 */
	public function register_texts( array $texts, array $languages, string $page_url = '', int $max_new = PHP_INT_MAX ): array {
		global $wpdb;

		if ( ! $texts || ! $languages ) {
			return [ 'rows' => 0, 'complete' => true ];
		}

		$by_hash = [];
		foreach ( $texts as $text ) {
			$by_hash[ $this->hash( $text ) ] = $text;
		}

		$page_url   = $this->clean_url( $page_url );
		$now        = current_time( 'mysql' );
		$normalized = $this->column_exists( 'normalized_text' );
		$columns    = 'original_text, text_hash, language_code, translated_text, status, page_url, created_at, updated_at'
					. ( $normalized ? ', normalized_text' : '' );
		$row_sql    = $normalized ? '(%s, %s, %s, %s, %s, %s, %s, %s, %s)' : '(%s, %s, %s, %s, %s, %s, %s, %s)';

		$rows     = [];
		$args     = [];
		$bytes    = 0;
		$total    = 0;
		$new      = 0;
		$complete = true;

		$flush = function () use ( &$rows, &$args, &$bytes, &$total, &$complete, $columns, $wpdb ): void {
			if ( ! $rows ) {
				return;
			}
			$sql = "INSERT INTO {$this->table_translations} ($columns) VALUES " . implode( ', ', $rows )
				 . ' ON DUPLICATE KEY UPDATE id = id';
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- placeholders built above, values bound here.
			if ( false !== $wpdb->query( $wpdb->prepare( $sql, ...$args ) ) ) {
				$total += count( $rows );
			} else {
				$complete = false; // wpdb has logged the error; the next scan retries
			}
			$rows  = [];
			$args  = [];
			$bytes = 0;
		};

		foreach ( array_chunk( $by_hash, 200, true ) as $chunk ) {
			$placeholders = implode( ', ', array_fill( 0, count( $chunk ), '%s' ) );
			$existing     = $wpdb->get_results(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$wpdb->prepare( "SELECT text_hash, language_code FROM {$this->table_translations} WHERE text_hash IN ($placeholders)", ...array_keys( $chunk ) ),
				ARRAY_A
			);

			$have = [];
			foreach ( $existing as $row ) {
				$have[ $row['text_hash'] . '|' . $row['language_code'] ] = true;
			}

			foreach ( $chunk as $hash => $text ) {
				$missing = array_filter( $languages, fn( $lang ) => ! isset( $have[ $hash . '|' . $lang ] ) );
				if ( ! $missing ) {
					continue;
				}
				if ( $new >= $max_new ) {
					$complete = false;
					break 2;
				}
				$new++;
				foreach ( $missing as $lang ) {
					$rows[] = $row_sql;
					array_push( $args, $text, $hash, $lang, '', 'pending', $page_url, $now, $now );
					if ( $normalized ) {
						$args[] = $this->normalize( $text );
					}
					// Keep each statement well under conservative max_allowed_packet limits.
					$bytes += 2 * strlen( $text ) + 200;
					if ( $bytes > 1000000 || count( $rows ) >= 500 ) {
						$flush();
					}
				}
			}
		}
		$flush();

		return [ 'rows' => $total, 'complete' => $complete ];
	}

	/**
	 * Normalize a plain-text string for storage.
	 *
	 * Translations are plain text that is only ever output through DOM text nodes
	 * or esc_html()/esc_attr()/esc_textarea(), so characters like "<" or "%AB"
	 * are kept verbatim. sanitize_textarea_field() rewrote or stripped them, so
	 * the saved hash no longer matched the text on the page.
	 */
	public function clean_text( $text ): string {
		if ( ! is_string( $text ) ) {
			return '';
		}
		$text = wp_check_invalid_utf8( $text, true );
		$text = preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $text ) ?? '';
		return trim( $text );
	}

	/** Sanitize a page URL for storage; anything that is not an http(s) URL becomes ''. */
	private function clean_url( string $url ): string {
		if ( $url === '' ) {
			return '';
		}
		return substr( esc_url_raw( $url, [ 'http', 'https' ] ), 0, 2083 );
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

	/**
	 * SHA-256 hash of the normalized text. Used as the lookup key throughout.
	 * Whitespace is collapsed so "Hello  World" and "Hello World" produce the same hash,
	 * matching what browsers report via innerText (used by the visual editor).
	 */
	public function hash( string $text ): string {
		return hash( 'sha256', preg_replace( '/\s+/', ' ', trim( $text ) ) ?? trim( $text ) );
	}

	/** Collapse internal whitespace so minor formatting differences don't create duplicate entries. */
	public function normalize( string $text ): string {
		return preg_replace( '/\s+/', ' ', trim( $text ) ) ?? trim( $text );
	}

	/**
	 * Fallback lookup by the original_text column (exact match after normalization).
	 * Used when the stored text_hash was computed with an older algorithm so the
	 * primary hash-based lookup returns nothing, but the text is still in the table.
	 */
	public function get_translation_by_original_text( string $text, string $language_code ): ?string {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT translated_text, status FROM {$this->table_translations}
				 WHERE original_text = %s AND language_code = %s
				 AND status != 'ignored' AND translated_text != ''
				 LIMIT 1",
				$text,
				$language_code
			),
			ARRAY_A
		);

		if ( ! $row ) {
			return null;
		}

		return $row['translated_text'];
	}

	/**
	 * Fallback lookup by the normalized_text column.
	 * Catches rows whose original_text was stored with different whitespace
	 * than the incoming text but whose normalized form matches.
	 */
	public function get_translation_by_normalized( string $normalized, string $language_code ): ?string {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT translated_text, status FROM {$this->table_translations}
				 WHERE normalized_text = %s AND language_code = %s
				 AND status != 'ignored' AND translated_text != ''
				 LIMIT 1",
				$normalized,
				$language_code
			),
			ARRAY_A
		);

		if ( ! $row ) {
			return null;
		}

		return $row['translated_text'];
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
	 *
	 * The cache stores 1/0 and is read with the $found flag: storing a bare
	 * boolean made a cached `false` indistinguishable from a cache miss, so a
	 * missing column re-ran the information_schema query on every call.
	 */
	private function column_exists( string $column ): bool {
		if ( isset( $this->columns[ $column ] ) ) {
			return $this->columns[ $column ];
		}

		$cache_key = 'lp_col_' . $column;
		$found     = false;
		$cached    = wp_cache_get( $cache_key, 'lp', false, $found );

		if ( $found ) {
			return $this->columns[ $column ] = (bool) $cached;
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

		wp_cache_set( $cache_key, $exists ? 1 : 0, 'lp', HOUR_IN_SECONDS );

		return $this->columns[ $column ] = $exists;
	}
}
