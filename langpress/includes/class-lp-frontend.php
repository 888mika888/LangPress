<?php
defined( 'ABSPATH' ) || exit;

/**
 * Handles three frontend concerns:
 *   1. Admin-bar "Translate Page" button
 *   2. Visual translation editor mode (?lp_translation_editor=1)
 *   3. PHP output buffering that swaps text nodes for the active language
 */
class LP_Frontend {

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
			'lp_translation_editor', '1',
			remove_query_arg( 'lp_translation_editor', $current_url )
		);

		$wp_admin_bar->add_node( [
			'id'    => 'lp-translate-page',
			'title' => '<span class="ab-icon dashicons dashicons-translation"></span> Translate Page',
			'href'  => esc_url( $editor_url ),
			'meta'  => [ 'class' => 'lp-translate-page-btn', 'title' => 'Open the visual translation editor' ],
		] );
	}

	private function is_editor_mode(): bool {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return isset( $_GET['lp_translation_editor'] )
			&& $_GET['lp_translation_editor'] === '1'
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
		wp_enqueue_style( 'lp-translation-editor', LP_PLUGIN_URL . 'public/translation-editor.css', [], LP_VERSION );
		wp_enqueue_script( 'lp-translation-editor', LP_PLUGIN_URL . 'public/translation-editor.js', [], LP_VERSION, true );

		$active_langs = (array) get_option( 'lp_active_languages', [ 'de', 'en', 'uk' ] );
		$default_lang = get_option( 'lp_default_language', 'de' );
		$target_langs = array_values( array_filter( $active_langs, fn( $l ) => $l !== $default_lang ) );

		wp_localize_script( 'lp-translation-editor', 'LP_Editor', [
			'ajaxUrl'     => admin_url( 'admin-ajax.php' ),
			'adminUrl'    => admin_url( 'admin.php?page=lp-translations' ),
			'nonce'       => wp_create_nonce( 'lp_admin_nonce' ),
			'defaultLang' => $default_lang,
			'targetLangs' => $target_langs,
			'postId'      => (int) get_queried_object_id(),
			'closeUrl'    => esc_url( remove_query_arg( 'lp_translation_editor' ) ),
		] );
	}

	public function inject_editor_sidebar(): void {
		if ( ! $this->is_editor_mode() ) {
			return;
		}

		$active_langs = (array) get_option( 'lp_active_languages', [ 'de', 'en', 'uk' ] );
		$default_lang = get_option( 'lp_default_language', 'de' );
		$all_langs    = LP_Translator::available_languages();
		$target_langs = array_filter( $active_langs, fn( $l ) => $l !== $default_lang );
		$def_meta     = $all_langs[ $default_lang ] ?? [ 'flag' => '', 'native' => strtoupper( $default_lang ) ];

		?>
		<div id="lp-editor-sidebar" translate="no"
			 role="complementary"
			 aria-label="<?php esc_attr_e( 'Translation Editor', 'langpress' ); ?>">

			<div class="lp-sidebar-header">
				<button class="lp-sidebar-close" id="lp-editor-close" type="button"
						aria-label="<?php esc_attr_e( 'Close editor', 'langpress' ); ?>">&times;</button>
				<span class="lp-sidebar-title">
					<?php esc_html_e( 'Translation Editor', 'langpress' ); ?>
				</span>
				<button class="lp-sidebar-save-top" id="lp-editor-save-top" type="button">
					<?php esc_html_e( 'Save', 'langpress' ); ?>
				</button>
			</div>

			<div class="lp-sidebar-tabs" role="tablist">
				<button class="lp-sidebar-tab lp-sidebar-tab--active" type="button"
						role="tab" aria-selected="true">
					<?php esc_html_e( 'Translation Editor', 'langpress' ); ?>
				</button>
				<button class="lp-sidebar-tab" type="button"
						role="tab" aria-selected="false">
					<?php esc_html_e( 'String Translation', 'langpress' ); ?>
				</button>
			</div>

			<div class="lp-sidebar-body">

				<div class="lp-sidebar-lang-display">
					<span class="lp-sidebar-lang-pill">
						<?php echo esc_html( $def_meta['flag'] . ' ' . $def_meta['native'] ); ?>
					</span>
				</div>

				<div class="lp-sidebar-hint" id="lp-editor-hint">
					<p><?php esc_html_e( 'Hover over any text on the page and click the ✎ icon to translate it.', 'langpress' ); ?></p>
				</div>

				<div class="lp-sidebar-fields" id="lp-editor-fields" style="display:none">

					<div class="lp-sidebar-field">
						<label class="lp-sidebar-label">
							<?php echo esc_html( $def_meta['flag'] . ' From ' . $def_meta['native'] ); ?>
						</label>
						<textarea class="lp-sidebar-textarea lp-sidebar-textarea--readonly"
								  id="lp-editor-de" readonly rows="3"
								  placeholder="<?php esc_attr_e( 'Original text…', 'langpress' ); ?>"></textarea>
						<small class="lp-sidebar-sublabel">Text</small>
					</div>

					<?php foreach ( $target_langs as $lang_code ) :
						$meta = $all_langs[ $lang_code ] ?? [ 'flag' => '', 'native' => strtoupper( $lang_code ) ];
					?>
					<div class="lp-sidebar-field">
						<label class="lp-sidebar-label" for="lp-editor-<?php echo esc_attr( $lang_code ); ?>">
							<?php echo esc_html( $meta['flag'] . ' To ' . $meta['native'] ); ?>
						</label>
						<textarea class="lp-sidebar-textarea"
								  id="lp-editor-<?php echo esc_attr( $lang_code ); ?>"
								  rows="3"
								  placeholder="<?php echo esc_attr( $meta['native'] . ' translation…' ); ?>"></textarea>
						<small class="lp-sidebar-sublabel">Text</small>
					</div>
					<?php endforeach; ?>

					<div id="lp-editor-msg" class="lp-sidebar-message"></div>

				</div>
			</div>

			<div class="lp-sidebar-footer" id="lp-editor-footer" style="display:none">
				<button class="lp-sidebar-save-btn" id="lp-editor-save" type="button">
					<?php esc_html_e( 'Save', 'langpress' ); ?>
				</button>
			</div>

		</div>
		<?php
	}

	public function start_output_buffer(): void {
		if ( LP_Translator::instance()->is_default_language() ) {
			return;
		}
		ob_start( [ $this, 'process_output' ] );
	}

	public function process_output( string $html ): string {
		if ( trim( $html ) === '' || $this->is_non_html_request() ) {
			return $html;
		}
		return LP_Translator::instance()->translate_html( $html );
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
		if ( ! LP_Translator::instance()->is_default_language() ) {
			return;
		}

		$page_url  = $this->get_current_url();
		$trans_key = 'lp_scanned_' . md5( $page_url );

		if ( get_transient( $trans_key ) ) {
			return;
		}

		$translator = LP_Translator::instance();

		ob_start( function ( string $html ) use ( $translator, $page_url, $trans_key ): string {
			$this->extract_and_register_texts( $html, $translator, $page_url );
			set_transient( $trans_key, 1, DAY_IN_SECONDS );
			return $html;
		} );
	}

	private function extract_and_register_texts( string $html, LP_Translator $translator, string $page_url ): void {
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
