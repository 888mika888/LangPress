<?php
defined( 'ABSPATH' ) || exit;

/**
 * Handles two frontend concerns:
 *   1. Visual translation editor mode (?lp_translation_editor=1)
 *   2. PHP output buffering that swaps text nodes for the active language
 */
class LP_Frontend {

	private static ?self $instance = null;

	private function __construct() {
		if ( is_admin() ) {
			return;
		}

		add_action( 'wp_head',           [ $this, 'inject_hreflang_tags' ] );
		add_filter( 'language_attributes', [ $this, 'maybe_add_rtl_dir' ] );
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
			</div>

			<div class="lp-sidebar-body">

				<div class="lp-sidebar-lang-display">
					<span class="lp-sidebar-lang-pill">
						<?php echo esc_html( $def_meta['flag'] . ' ' . $def_meta['native'] ); ?>
					</span>
					<a class="lp-sidebar-admin-link" href="<?php echo esc_url( admin_url( 'admin.php?page=lp-translations' ) ); ?>" target="_blank">
						<?php esc_html_e( 'Manage strings ↗', 'langpress' ); ?>
					</a>
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
								  id="lp-editor-original" readonly rows="3"
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

			// Mirror the block-level strategy in LP_Translator::walk_dom():
			// collect the full combined text for block elements so the registered hash
			// matches what the visual editor saves (JS innerText of the whole element).
			if ( in_array( $tag, LP_Translator::BLOCK_TAGS, true ) ) {
				$translator = LP_Translator::instance();
				if ( ! $translator->subtree_has_bail_tag( $node ) ) {
					$combined = $translator->get_block_text( $node );
					if ( $combined !== '' && mb_strlen( $combined ) >= 2 && preg_match( '/\p{L}/u', $combined ) ) {
						$texts[] = $combined;
						return;
					}
				}
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

	public function inject_hreflang_tags(): void {
		$active_langs = (array) get_option( 'lp_active_languages', [ 'de', 'en', 'uk' ] );
		if ( count( $active_langs ) < 2 ) {
			return;
		}

		$default_lang = get_option( 'lp_default_language', 'de' );
		$base_url     = remove_query_arg( [ 'lp_lang', 'lp_translation_editor' ], $this->get_current_url() );

		foreach ( $active_langs as $lang_code ) {
			$href = ( $lang_code === $default_lang )
				? $base_url
				: add_query_arg( 'lp_lang', $lang_code, $base_url );
			echo '<link rel="alternate" hreflang="' . esc_attr( $lang_code ) . '" href="' . esc_url( $href ) . '">' . "\n";
		}

		// x-default points to the default-language URL with no lang override.
		echo '<link rel="alternate" hreflang="x-default" href="' . esc_url( $base_url ) . '">' . "\n";
	}

	public function maybe_add_rtl_dir( string $output ): string {
		$lang    = LP_Translator::instance()->get_current_language();
		$default = get_option( 'lp_default_language', 'de' );

		if ( $lang === $default ) {
			return $output;
		}

		// Swap the lang attribute to match the active language so screen
		// readers and SEO tools see the correct language code (e.g. "ar" not "de").
		$output = preg_replace( '/lang="[^"]*"/', 'lang="' . esc_attr( $lang ) . '"', $output ) ?? $output;

		if ( LP_Translator::is_rtl_language( $lang ) ) {
			if ( strpos( $output, 'dir=' ) !== false ) {
				$output = preg_replace( '/dir="[^"]*"/', 'dir="rtl"', $output ) ?? $output;
			} else {
				$output .= ' dir="rtl"';
			}
		}

		return $output;
	}

	private function get_current_url(): string {
		return ( is_ssl() ? 'https://' : 'http://' )
			 . sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ?? 'localhost' ) )
			 . sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ?? '/' ) );
	}
}

