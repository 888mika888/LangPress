<?php
defined( 'ABSPATH' ) || exit;

/**
 * Sprachumschalter: Shortcode, Widget, Frontend-HTML.
 */
class LP_Language_Switcher {

	private static ?self $instance = null;

	private function __construct() {
		add_shortcode( 'langpress_switcher', [ $this, 'render_shortcode' ] );
		add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_assets' ] );
		add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_translate_mode' ] );
		add_action( 'wp_footer', [ $this, 'maybe_render_fixed' ] );

		// AJAX: Sprachenwechsel ohne Seiten-Reload
		add_action( 'wp_ajax_nopriv_lp_switch_lang', [ $this, 'ajax_switch_lang' ] );
		add_action( 'wp_ajax_lp_switch_lang',        [ $this, 'ajax_switch_lang' ] );

		// Widget registrieren
		add_action( 'widgets_init', [ $this, 'register_widget' ] );
	}

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	// -------------------------------------------------------------------------
	// Assets
	// -------------------------------------------------------------------------

	public function enqueue_assets(): void {
		$fixed = (bool) get_option( 'lp_position_fixed', false );

		// Fixed-Switcher: Assets auf ALLEN Seiten laden.
		// Seiten-Filter gilt nur für den inline/shortcode Modus.
		if ( ! $fixed && ! $this->should_show_on_current_page() ) {
			return;
		}

		wp_enqueue_style(
			'lp-public',
			LP_PLUGIN_URL . 'public/public.css',
			[],
			LP_VERSION
		);

		wp_enqueue_script(
			'lp-public',
			LP_PLUGIN_URL . 'public/public.js',
			[],
			LP_VERSION,
			true
		);

		wp_localize_script( 'lp-public', 'LP', [
			'ajaxUrl'     => admin_url( 'admin-ajax.php' ),
			'nonce'       => wp_create_nonce( 'lp_switch_lang' ),
			'currentLang' => LP_Translator::instance()->get_current_language(),
			'defaultLang' => get_option( 'lp_default_language', 'de' ),
		] );
	}

	/**
	 * Translate-Mode Assets – nur für eingeloggte Administratoren.
	 * Lädt unabhängig von should_show_on_current_page().
	 */
	public function enqueue_translate_mode(): void {
		if ( ! is_user_logged_in() || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// Full editor mode has its own sidebar — don't load the floating quick-mode too
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['lp_translation_editor'] ) && $_GET['lp_translation_editor'] === '1' ) {
			return;
		}

		// Public-Assets sicherstellen (falls Switcher auf dieser Seite deaktiviert)
		wp_enqueue_style( 'lp-public', LP_PLUGIN_URL . 'public/public.css', [], LP_VERSION );
		wp_enqueue_script( 'lp-public', LP_PLUGIN_URL . 'public/public.js', [], LP_VERSION, true );

		wp_enqueue_style(
			'lp-translate-mode',
			LP_PLUGIN_URL . 'public/translate-mode.css',
			[ 'lp-public' ],
			LP_VERSION
		);

		wp_enqueue_script(
			'lp-translate-mode',
			LP_PLUGIN_URL . 'public/translate-mode.js',
			[],
			LP_VERSION,
			true
		);

		wp_localize_script( 'lp-translate-mode', 'LP_Translate', [
			'ajaxUrl'     => admin_url( 'admin-ajax.php' ),
			'nonce'       => wp_create_nonce( 'lp_admin_nonce' ),
			'currentLang' => LP_Translator::instance()->get_current_language(),
			'defaultLang' => get_option( 'lp_default_language', 'de' ),
			'activeLangs' => get_option( 'lp_active_languages', [ 'de', 'en', 'uk' ] ),
			'langMeta'    => LP_Translator::available_languages(),
		] );
	}

	// -------------------------------------------------------------------------
	// Sichtbarkeitslogik
	// -------------------------------------------------------------------------

	private function should_show_on_current_page(): bool {
		$display_mode = get_option( 'lp_switcher_display', 'all' );

		if ( $display_mode === 'all' ) {
			return true;
		}

		$page_ids = (array) get_option( 'lp_switcher_pages', [] );

		if ( $display_mode === 'specific' ) {
			return is_page( $page_ids ) || ( is_singular() && in_array( get_the_ID(), $page_ids, true ) );
		}

		if ( $display_mode === 'exclude' ) {
			return ! ( is_page( $page_ids ) || ( is_singular() && in_array( get_the_ID(), $page_ids, true ) ) );
		}

		return true;
	}

	// -------------------------------------------------------------------------
	// HTML-Generierung
	// -------------------------------------------------------------------------

	/**
	 * Den Sprachumschalter als HTML-String erzeugen.
	 *
	 * @param bool $shortcode_context  true wenn aus Shortcode aufgerufen
	 */
	public function render( bool $shortcode_context = false ): string {
		$active_langs  = (array) get_option( 'lp_active_languages', [ 'de', 'en', 'uk' ] );
		$current_lang  = LP_Translator::instance()->get_current_language();
		$style         = get_option( 'lp_switcher_style', 'dropdown' );
		$display_mode  = get_option( 'lp_display_mode', 'text' );
		$all_languages = LP_Translator::available_languages();
		$position      = get_option( 'lp_switcher_position', 'bottom-right' );
		$fixed         = (bool) get_option( 'lp_position_fixed', false );

		// CSS-Custom-Properties für Styling
		$custom_css = $this->build_inline_css();

		$wrapper_class = 'lp-switcher lp-switcher--' . esc_attr( $style );
		if ( $shortcode_context ) {
			$wrapper_class .= ' lp-switcher--inline';
		} elseif ( $fixed ) {
			$wrapper_class .= ' lp-switcher--fixed lp-switcher--' . esc_attr( $position );
		}
		if ( LP_Translator::is_rtl_language( $current_lang ) ) {
			$wrapper_class .= ' lp-switcher--rtl';
		}

		$html  = '<div class="' . esc_attr( $wrapper_class ) . '" style="' . esc_attr( $custom_css ) . '">';
		$html .= '<span class="lp-switcher__current">';
		$html .= $this->render_lang_label( $current_lang, $display_mode, $all_languages );
		$html .= '<span class="lp-switcher__arrow" aria-hidden="true">&#9660;</span>';
		$html .= '</span>';
		$html .= '<ul class="lp-switcher__list" role="listbox" aria-label="' . esc_attr__( 'Sprache wählen', 'langpress' ) . '">';

		foreach ( $active_langs as $lang_code ) {
			if ( ! isset( $all_languages[ $lang_code ] ) ) {
				continue;
			}
			$is_active = ( $lang_code === $current_lang );
			$html .= '<li class="lp-switcher__item' . ( $is_active ? ' lp-switcher__item--active' : '' ) . '"'
				   . ' role="option" aria-selected="' . ( $is_active ? 'true' : 'false' ) . '">';
			$html .= '<a href="' . esc_url( $this->get_lang_url( $lang_code ) ) . '"'
				   . ' class="lp-switcher__link"'
				   . ' data-lang="' . esc_attr( $lang_code ) . '">';
			$html .= $this->render_lang_label( $lang_code, $display_mode, $all_languages );
			$html .= '</a>';
			$html .= '</li>';
		}

		$html .= '</ul>';
		$html .= '</div>';

		return $html;
	}

	/**
	 * Label für eine Sprache je nach Darstellungsmodus erzeugen.
	 *
	 * @param string $lang_code
	 * @param string $mode       text|flag|both
	 * @param array  $languages
	 */
	private function render_lang_label( string $lang_code, string $mode, array $languages ): string {
		if ( ! isset( $languages[ $lang_code ] ) ) {
			return esc_html( strtoupper( $lang_code ) );
		}

		$lang = $languages[ $lang_code ];
		$out  = '';

		if ( $mode === 'flag' || $mode === 'both' ) {
			$out .= '<span class="lp-flag" aria-hidden="true">' . esc_html( $lang['flag'] ) . '</span>';
		}

		if ( $mode === 'text' || $mode === 'both' ) {
			$out .= '<span class="lp-lang-label">' . esc_html( $lang['native'] ) . '</span>';
		}

		return $out;
	}

	/**
	 * URL für Sprachenwechsel erzeugen (aktueller Request + lp_lang-Parameter).
	 */
	private function get_lang_url( string $lang_code ): string {
		$current_url = ( is_ssl() ? 'https://' : 'http://' )
					 . sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ?? '' ) )
					 . sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ?? '/' ) );

		// Bestehenden lp_lang-Parameter entfernen, neuen setzen
		$url = remove_query_arg( 'lp_lang', $current_url );
		return add_query_arg( 'lp_lang', $lang_code, $url );
	}

	/**
	 * Inline-CSS-Custom-Properties aus den gespeicherten Styling-Optionen bauen.
	 */
	private function build_inline_css(): string {
		$props = [
			'--lp-bg'            => get_option( 'lp_bg_color', '#ffffff' ),
			'--lp-text'          => get_option( 'lp_text_color', '#333333' ),
			'--lp-border'        => get_option( 'lp_border_color', '#cccccc' ),
			'--lp-hover'         => get_option( 'lp_hover_color', '#f0f0f0' ),
			'--lp-radius'        => get_option( 'lp_border_radius', '4' ) . 'px',
			'--lp-font-size'     => get_option( 'lp_font_size', '14' ) . 'px',
			'--lp-padding'       => get_option( 'lp_padding', '8' ) . 'px',
		];

		$css = '';
		foreach ( $props as $prop => $val ) {
			$css .= sanitize_text_field( $prop ) . ':' . sanitize_text_field( $val ) . ';';
		}
		return $css;
	}

	// -------------------------------------------------------------------------
	// Shortcode & Fixed Position
	// -------------------------------------------------------------------------

	public function render_shortcode( array $atts ): string {
		if ( ! $this->should_show_on_current_page() ) {
			return '';
		}
		return $this->render( true );
	}

	public function maybe_render_fixed(): void {
		$fixed    = (bool) get_option( 'lp_position_fixed', false );
		$position = get_option( 'lp_switcher_position', 'bottom-right' );

		if ( ! $fixed ) {
			return;
		}

		// Fixed-Switcher erscheint auf ALLEN Seiten – Seiten-Filter gilt nur für inline/shortcode.
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo $this->render( false );
	}

	// -------------------------------------------------------------------------
	// AJAX
	// -------------------------------------------------------------------------

	public function ajax_switch_lang(): void {
		check_ajax_referer( 'lp_switch_lang', 'nonce' );

		$lang   = isset( $_POST['lang'] ) ? sanitize_key( wp_unslash( $_POST['lang'] ) ) : '';
		$active = (array) get_option( 'lp_active_languages', [ 'de', 'en', 'uk' ] );

		if ( ! in_array( $lang, $active, true ) ) {
			wp_send_json_error( [ 'message' => 'Invalid language.' ] );
		}

		LP_Translator::instance()->set_language_cookie( $lang );
		wp_send_json_success( [ 'lang' => $lang ] );
	}

	// -------------------------------------------------------------------------
	// Widget
	// -------------------------------------------------------------------------

	public function register_widget(): void {
		register_widget( 'LP_Language_Widget' );
	}
}

/**
 * Einfaches WordPress-Widget für den Sprachumschalter.
 */
class LP_Language_Widget extends WP_Widget {

	public function __construct() {
		parent::__construct(
			'lp_language_widget',
			__( 'Sprachumschalter', 'langpress' ),
			[ 'description' => __( 'Zeigt den Sprachumschalter an.', 'langpress' ) ]
		);
	}

	public function widget( $args, $instance ): void {
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo $args['before_widget'];
		if ( ! empty( $instance['title'] ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo $args['before_title'] . esc_html( $instance['title'] ) . $args['after_title'];
		}
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo LP_Language_Switcher::instance()->render( true );
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo $args['after_widget'];
	}

	public function form( $instance ): void {
		$title = esc_attr( $instance['title'] ?? '' );
		echo '<p><label for="' . esc_attr( $this->get_field_id( 'title' ) ) . '">'
		   . esc_html__( 'Titel:', 'langpress' )
		   . '</label>'
		   . '<input class="widefat" id="' . esc_attr( $this->get_field_id( 'title' ) ) . '"'
		   . ' name="' . esc_attr( $this->get_field_name( 'title' ) ) . '"'
		   . ' type="text" value="' . $title . '"></p>';
	}

	public function update( $new_instance, $old_instance ): array {
		return [ 'title' => sanitize_text_field( $new_instance['title'] ?? '' ) ];
	}
}
