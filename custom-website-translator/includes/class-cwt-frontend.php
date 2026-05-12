<?php
defined( 'ABSPATH' ) || exit;

/**
 * Handles three frontend concerns:
 *   1. Admin-bar "Translate Page" button
 *   2. Visual translation editor mode (?cwt_translation_editor=1)
 *   3. PHP output buffering that swaps text nodes for the active language
 */
class CWT_Frontend {

	private static ?self $instance = null;

	private function __construct() {
		// admin_bar_menu fires on both frontend and admin — register it
		// before the is_admin() guard so the button always appears in the frontend bar.
		add_action( 'admin_bar_menu', [ $this, 'add_admin_bar_button' ], 100 );

		if ( is_admin() ) {
			return;
		}

		add_action( 'template_redirect', [ $this, 'maybe_start_editor_mode' ], 0 );
		add_action( 'template_redirect', [ $this, 'start_output_buffer' ], 1 );
		add_action( 'template_redirect', [ $this, 'maybe_register_texts' ], 5 );
	}

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function add_admin_bar_button( WP_Admin_Bar $wp_admin_bar ): void {
		if ( ! current_user_can( 'manage_options' ) || is_admin() ) {
			return;
		}

		$current_url = ( is_ssl() ? 'https://' : 'http://' )
			. sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ?? '' ) )
			. sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ?? '/' ) );

		$editor_url = add_query_arg(
			'cwt_translation_editor', '1',
			remove_query_arg( 'cwt_translation_editor', $current_url )
		);

		$wp_admin_bar->add_node( [
			'id'    => 'cwt-translate-page',
			'title' => '<span class="ab-icon dashicons dashicons-translation"></span> Translate Page',
			'href'  => esc_url( $editor_url ),
			'meta'  => [ 'class' => 'cwt-translate-page-btn', 'title' => 'Open the visual translation editor' ],
		] );
	}

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

		add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_editor_assets' ] );
		add_action( 'wp_footer', [ $this, 'inject_editor_sidebar' ], 9999 );

		// Show the original content in the editor so the admin can see what they're translating.
		remove_action( 'template_redirect', [ $this, 'start_output_buffer' ], 1 );
	}

	public function enqueue_editor_assets(): void {
		wp_enqueue_style( 'cwt-translation-editor', CWT_PLUGIN_URL . 'public/translation-editor.css', [], CWT_VERSION );
		wp_enqueue_script( 'cwt-translation-editor', CWT_PLUGIN_URL . 'public/translation-editor.js', [], CWT_VERSION, true );

		$active_langs = (array) get_option( 'cwt_active_languages', [ 'de', 'en', 'uk' ] );
		$default_lang = get_option( 'cwt_default_language', 'de' );
		$target_langs = array_values( array_filter( $active_langs, fn( $l ) => $l !== $default_lang ) );

		wp_localize_script( 'cwt-translation-editor', 'CWT_Editor', [
			'ajaxUrl'     => admin_url( 'admin-ajax.php' ),
			'adminUrl'    => admin_url( 'admin.php?page=cwt-translations' ),
			'nonce'       => wp_create_nonce( 'cwt_admin_nonce' ),
			'defaultLang' => $default_lang,
			'targetLangs' => $target_langs,
			'postId'      => (int) get_queried_object_id(),
			'closeUrl'    => esc_url( remove_query_arg( 'cwt_translation_editor' ) ),
		] );
	}

	public function inject_editor_sidebar(): void {
		if ( ! $this->is_editor_mode() ) {
			return;
		}

		$active_langs = (array) get_option( 'cwt_active_languages', [ 'de', 'en', 'uk' ] );
		$default_lang = get_option( 'cwt_default_language', 'de' );
		$all_langs    = CWT_Translator::available_languages();
		$target_langs = array_filter( $active_langs, fn( $l ) => $l !== $default_lang );
		$def_meta     = $all_langs[ $default_lang ] ?? [ 'flag' => '', 'native' => strtoupper( $default_lang ) ];

		?>
		<div id="cwt-editor-sidebar" translate="no"
			 role="complementary"
			 aria-label="<?php esc_attr_e( 'Translation Editor', 'custom-website-translator' ); ?>">

			<div class="cwt-sidebar-header">
				<button class="cwt-sidebar-close" id="cwt-editor-close" type="button"
						aria-label="<?php esc_attr_e( 'Close editor', 'custom-website-translator' ); ?>">&times;</button>
				<span class="cwt-sidebar-title">
					<?php esc_html_e( 'Translation Editor', 'custom-website-translator' ); ?>
				</span>
				<button class="cwt-sidebar-save-top" id="cwt-editor-save-top" type="button">
					<?php esc_html_e( 'Save', 'custom-website-translator' ); ?>
				</button>
			</div>

			<div class="cwt-sidebar-tabs" role="tablist">
				<button class="cwt-sidebar-tab cwt-sidebar-tab--active" type="button"
						role="tab" aria-selected="true">
					<?php esc_html_e( 'Translation Editor', 'custom-website-translator' ); ?>
				</button>
				<button class="cwt-sidebar-tab" type="button"
						role="tab" aria-selected="false">
					<?php esc_html_e( 'String Translation', 'custom-website-translator' ); ?>
				</button>
			</div>

			<div class="cwt-sidebar-body">

				<div class="cwt-sidebar-lang-display">
					<span class="cwt-sidebar-lang-pill">
						<?php echo esc_html( $def_meta['flag'] . ' ' . $def_meta['native'] ); ?>
					</span>
				</div>

				<div class="cwt-sidebar-hint" id="cwt-editor-hint">
					<p><?php esc_html_e( 'Hover over any text on the page and click the ✎ icon to translate it.', 'custom-website-translator' ); ?></p>
				</div>

				<div class="cwt-sidebar-fields" id="cwt-editor-fields" style="display:none">

					<div class="cwt-sidebar-field">
						<label class="cwt-sidebar-label">
							<?php echo esc_html( $def_meta['flag'] . ' From ' . $def_meta['native'] ); ?>
						</label>
						<textarea class="cwt-sidebar-textarea cwt-sidebar-textarea--readonly"
								  id="cwt-editor-de" readonly rows="3"
								  placeholder="<?php esc_attr_e( 'Original text…', 'custom-website-translator' ); ?>"></textarea>
						<small class="cwt-sidebar-sublabel">Text</small>
					</div>

					<?php foreach ( $target_langs as $lang_code ) :
						$meta = $all_langs[ $lang_code ] ?? [ 'flag' => '', 'native' => strtoupper( $lang_code ) ];
					?>
					<div class="cwt-sidebar-field">
						<label class="cwt-sidebar-label" for="cwt-editor-<?php echo esc_attr( $lang_code ); ?>">
							<?php echo esc_html( $meta['flag'] . ' To ' . $meta['native'] ); ?>
						</label>
						<textarea class="cwt-sidebar-textarea"
								  id="cwt-editor-<?php echo esc_attr( $lang_code ); ?>"
								  rows="3"
								  placeholder="<?php echo esc_attr( $meta['native'] . ' translation…' ); ?>"></textarea>
						<small class="cwt-sidebar-sublabel">Text</small>
					</div>
					<?php endforeach; ?>

					<div id="cwt-editor-msg" class="cwt-sidebar-message"></div>

				</div>
			</div>

			<div class="cwt-sidebar-footer" id="cwt-editor-footer" style="display:none">
				<button class="cwt-sidebar-save-btn" id="cwt-editor-save" type="button">
					<?php esc_html_e( 'Save', 'custom-website-translator' ); ?>
				</button>
			</div>

		</div>
		<?php
	}

	public function start_output_buffer(): void {
		if ( CWT_Translator::instance()->is_default_language() ) {
			return;
		}
		ob_start( [ $this, 'process_output' ] );
	}

	public function process_output( string $html ): string {
		if ( trim( $html ) === '' || $this->is_non_html_request() ) {
			return $html;
		}
		return CWT_Translator::instance()->translate_html( $html );
	}

	private function is_non_html_request(): bool {
		if ( ( defined( 'REST_REQUEST' ) && REST_REQUEST )
			|| ( defined( 'DOING_AJAX' ) && DOING_AJAX )
			|| is_feed() ) {
			return true;
		}
		foreach ( headers_list() as $header ) {
			if ( stripos( $header, 'Content-Type:' ) === 0 && stripos( $header, 'text/html' ) === false ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * On the default language, scan the rendered HTML for translatable strings
	 * and register any new ones as "pending" in the database.
	 * Throttled to once per URL per day via a transient.
	 */
	public function maybe_register_texts(): void {
		if ( ! CWT_Translator::instance()->is_default_language() ) {
			return;
		}

		$page_url  = $this->get_current_url();
		$trans_key = 'cwt_scanned_' . md5( $page_url );

		if ( get_transient( $trans_key ) ) {
			return;
		}

		$translator = CWT_Translator::instance();

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
		$doc        = new DOMDocument( '1.0', 'UTF-8' );
		$doc->loadHTML( '<?xml encoding="UTF-8">' . $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD );
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
