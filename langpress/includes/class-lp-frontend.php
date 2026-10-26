<?php
defined( 'ABSPATH' ) || exit;

/**
 * Handles two frontend concerns:
 *   1. Visual translation editor mode (?lp_translation_editor=1)
 *   2. PHP output buffering that swaps text nodes for the active language
 *
 * ALL hooks are registered inside the 'wp' action so they are never
 * active during REST API, AJAX, cron, or XML-RPC requests; those exit
 * before 'wp' fires, making it impossible for LP to interfere.
 */
class LP_Frontend {

	private static ?self $instance = null;

	private function __construct() {
		// Use 'wp' as the registration point, not plugins_loaded/template_redirect.
		// REST API calls exit during parse_request (before 'wp').
		// admin-ajax.php exits after its handler (before 'wp').
		// wp-cron.php exits after its jobs (before 'wp').
		// So if 'wp' fires we are guaranteed to be on a real frontend HTML page.
		add_action( 'wp', [ $this, 'register_hooks' ], 1 );
	}

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Register all frontend hooks.
	 * Only called on real frontend page loads, never on REST/AJAX/cron.
	 */
	public function register_hooks(): void {
		// Skip admin-initiated page loads just in case.
		if ( is_admin() ) {
			return;
		}

		// Belt-and-suspenders: by 'wp' time these constants are reliable.
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST )     return;
		if ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST ) return;
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) return;
		if ( wp_doing_cron() )                               return;
		if ( wp_doing_ajax() )                               return;

		add_action( 'wp_head',            [ $this, 'inject_hreflang_tags' ] );
		add_filter( 'language_attributes', [ $this, 'maybe_add_rtl_dir' ] );

		if ( $this->is_editor_mode() ) {
			// Editor mode: enqueue editor assets, inject sidebar, no output buffer.
			add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_editor_assets' ] );
			add_action( 'wp_footer',          [ $this, 'inject_editor_sidebar' ], 9999 );
			return;
		}

		$translator = LP_Translator::instance();

		if ( ! $translator->is_default_language() ) {
			// Non-default language: capture output and translate it.
			ob_start( [ $this, 'process_output' ] );
			return;
		}

		// Default language: scan the rendered HTML for new translatable strings.
		// Throttled to once per page per day; see get_scan_key() for what "page" means.
		$scan_key = $this->get_scan_key();
		if ( $scan_key === null ) {
			return;
		}

		$trans_key = 'lp_scan_' . md5( $scan_key );
		if ( get_transient( $trans_key ) ) {
			return;
		}

		// Claim the slot before rendering so concurrent requests for the same page
		// don't all scan it; a failed scan is simply retried after the TTL.
		set_transient( $trans_key, 1, DAY_IN_SECONDS );

		$page_url = self::get_current_url();
		ob_start( function ( string $html ) use ( $translator, $page_url, $trans_key ): string {
			if ( $this->is_html_response( $html )
				&& ! $this->extract_and_register_texts( $html, $translator, $page_url ) ) {
				// A very long page hit the per-scan limit: continue soon, not tomorrow.
				set_transient( $trans_key, 1, 10 * MINUTE_IN_SECONDS );
			}
			return $html;
		} );
	}

	/**
	 * Identify the page being rendered for the scan throttle, or return null
	 * when this request should not be scanned at all.
	 *
	 * The previous throttle keyed on the full request URL, so every unique query
	 * string (?x=1, ?x=2, …) triggered a fresh scan and left a transient behind:
	 * unauthenticated, unbounded writes. The key is now derived from what
	 * WordPress resolved the request to, so its range is bounded by the site's own
	 * content, and it includes the content's modification time so edits are
	 * picked up without waiting for the throttle to expire.
	 */
	private function get_scan_key(): ?string {
		if ( ! get_option( 'lp_auto_scan', 1 ) ) {
			return null;
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		if ( 'GET' !== ( $_SERVER['REQUEST_METHOD'] ?? 'GET' ) ) {
			return null;
		}

		// Search results and 404s echo visitor input; previews and feeds are not pages.
		if ( is_search() || is_404() || is_preview() || is_feed() || is_embed()
			|| is_robots() || is_trackback() || is_customize_preview() ) {
			return null;
		}

		// Only scan clean URLs. Arguments WordPress doesn't route on (tracking
		// parameters, cache busters, plugin parameters) could change what a theme
		// prints, so a request carrying them never triggers registration.
		global $wp;
		$routing = array_merge( $wp->public_query_vars ?? [], [ 'lp_lang' ] );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		foreach ( array_keys( $_GET ) as $arg ) {
			if ( ! in_array( $arg, $routing, true ) ) {
				return null;
			}
		}

		$object = get_queried_object();
		if ( $object instanceof WP_Post ) {
			$id = 'post:' . $object->ID . ':' . $object->post_modified_gmt;
		} elseif ( $object instanceof WP_Term ) {
			$id = 'term:' . $object->term_id;
		} elseif ( $object instanceof WP_User ) {
			$id = 'user:' . $object->ID;
		} elseif ( $object instanceof WP_Post_Type ) {
			$id = 'type:' . $object->name;
		} elseif ( is_front_page() || is_home() ) {
			$id = 'home';
		} else {
			return null; // date archives and other unusual views are not worth scanning
		}

		// Listing pages (including a static "posts page") change whenever any post does.
		$listing = ! $object instanceof WP_Post || is_home();
		if ( $listing ) {
			$id .= ':' . get_lastpostmodified( 'gmt' );
		}

		// `paged` only selects content on listings. On a single post WordPress
		// ignores it, so including it there let ?paged=1..1000 force 1,000 scans.
		$paged = $listing ? (int) get_query_var( 'paged' ) : 0;
		$page  = (int) get_query_var( 'page' );
		if ( $paged > 1000 || $page > 1000 ) {
			return null;
		}

		return $id . '|' . $paged . '|' . $page;
	}

	private function is_editor_mode(): bool {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return isset( $_GET['lp_translation_editor'] )
			&& $_GET['lp_translation_editor'] === '1'
			&& current_user_can( 'manage_options' );
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
			// esc_url() would turn & into &#038; inside this JS string and break the URL.
			'closeUrl'    => esc_url_raw( remove_query_arg( 'lp_translation_editor', self::get_current_url() ) ),
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

	public function process_output( string $html ): string {
		if ( ! $this->is_html_response( $html ) ) {
			return $html;
		}

		return LP_Translator::instance()->translate_html( $html );
	}

	/**
	 * Whether a buffered response is an HTML page we may translate or scan.
	 * Used for both, so a plugin that answers a front-end URL with JSON or XML
	 * is neither rewritten nor scanned for strings.
	 */
	private function is_html_response( string $html ): bool {
		if ( trim( $html ) === '' ) {
			return false;
		}

		// Never translate JSON, XML, or non-HTML responses.
		$first = ltrim( $html );
		if ( $first[0] === '{' || $first[0] === '[' ) {
			return false;
		}
		if ( 0 === stripos( $first, '<?xml' ) || 0 === stripos( $first, '<rss' ) ) {
			return false;
		}
		if ( false === stripos( $html, '<html' ) && false === stripos( $html, '<!DOCTYPE' ) ) {
			return false;
		}

		// One final check now that all WP constants are defined.
		if ( ( defined( 'REST_REQUEST' ) && REST_REQUEST )
			|| ( defined( 'DOING_AJAX' ) && DOING_AJAX )
			|| is_feed() ) {
			return false;
		}

		// Verify Content-Type header hasn't been changed to non-HTML.
		foreach ( headers_list() as $header ) {
			if ( stripos( $header, 'Content-Type:' ) === 0 && stripos( $header, 'text/html' ) === false ) {
				return false;
			}
		}

		return true;
	}

	/** @return bool False if registration hit a limit and the page should be scanned again soon. */
	private function extract_and_register_texts( string $html, LP_Translator $translator, string $page_url ): bool {
		$texts = $this->collect_page_texts( $html );
		return ! $texts || $translator->register_texts( $texts, $page_url );
	}

	/** @return string[] Unique translatable strings found in a rendered page. */
	private function collect_page_texts( string $html ): array {
		if ( trim( $html ) === '' ) {
			return [];
		}

		$use_errors = libxml_use_internal_errors( true );
		$doc        = new DOMDocument( '1.0', 'UTF-8' );
		$doc->loadHTML( '<?xml encoding="UTF-8">' . $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD );
		libxml_clear_errors();
		libxml_use_internal_errors( $use_errors );

		$texts = [];
		$this->collect_texts( $doc, $texts );

		return array_values( array_unique( $texts ) );
	}

	private function collect_texts( DOMNode $node, array &$texts ): void {
		if ( count( $texts ) >= LP_Translator::MAX_STRINGS_PER_PAGE * 2 ) {
			return; // bounded work on pathological pages; duplicates are removed later
		}

		if ( $node instanceof DOMElement ) {
			$tag = strtolower( $node->nodeName );
			if ( $tag === 'head' || in_array( $tag, LP_Translator::SKIP_TAGS, true ) ) {
				return;
			}
			if ( $node->getAttribute( 'translate' ) === 'no' ) {
				return;
			}
			// The admin bar is per-user chrome ("Howdy, …"), not page content.
			if ( $node->getAttribute( 'id' ) === 'wpadminbar' ) {
				return;
			}

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
		$base_url     = remove_query_arg( [ 'lp_lang', 'lp_translation_editor' ], self::get_current_url() );

		foreach ( $active_langs as $lang_code ) {
			$href = ( $lang_code === $default_lang )
				? $base_url
				: add_query_arg( 'lp_lang', $lang_code, $base_url );
			echo '<link rel="alternate" hreflang="' . esc_attr( $lang_code ) . '" href="' . esc_url( $href ) . '">' . "\n";
		}

		echo '<link rel="alternate" hreflang="x-default" href="' . esc_url( $base_url ) . '">' . "\n";
	}

	public function maybe_add_rtl_dir( string $output ): string {
		$lang    = LP_Translator::instance()->get_current_language();
		$default = get_option( 'lp_default_language', 'de' );

		if ( $lang === $default ) {
			return $output;
		}

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

	/**
	 * Absolute URL of the current request, for hreflang and switcher links.
	 *
	 * Scheme, host and port come from the configured home URL, never from the
	 * Host header, which the client controls. Building on HTTP_HOST let a forged
	 * header point every generated link (and any cached copy of the page) at an
	 * attacker's domain. Only the path and query are taken from the request, and
	 * they are not passed through sanitize_text_field(), which strips
	 * percent-encoded octets and mangled non-ASCII slugs. Callers escape for
	 * their output context (esc_url / esc_url_raw).
	 */
	public static function get_current_url(): string {
		$home   = wp_parse_url( home_url() );
		$origin = ( $home['scheme'] ?? 'http' ) . '://' . ( $home['host'] ?? 'localhost' )
				. ( isset( $home['port'] ) ? ':' . $home['port'] : '' );

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- validated below
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : '/';

		// An absolute-form request target ("GET http://other.host/path") must not
		// contribute an authority; keep only its path and query.
		if ( ! str_starts_with( $uri, '/' ) ) {
			$parts = wp_parse_url( $uri );
			$uri   = '/' . ltrim( (string) ( $parts['path'] ?? '' ), '/' )
				   . ( isset( $parts['query'] ) ? '?' . $parts['query'] : '' );
		}

		// Whitespace and control characters never belong in a request target.
		$uri = preg_replace( '/[\x00-\x20\x7F]/', '', $uri ) ?? '/';

		return $origin . $uri;
	}
}
