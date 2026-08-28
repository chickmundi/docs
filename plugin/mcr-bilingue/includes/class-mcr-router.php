<?php
/**
 * URL routing, canonicals and hreflang.
 *
 * @package MCR_Bilingue
 */

defined( 'ABSPATH' ) || exit;

/**
 * Serves each language at its own URL.
 */
final class MCR_Router {

	/**
	 * Singleton.
	 *
	 * @var self|null
	 */
	private static ?self $instance = null;

	/**
	 * Singleton accessor.
	 */
	public static function instance(): self {
		return self::$instance ??= new self();
	}

	/**
	 * Private constructor.
	 */
	private function __construct() {}

	/**
	 * Hook up.
	 */
	public function init(): void {
		/*
		 * Immediately, not on a later hook: init() itself runs on
		 * plugins_loaded, and the prefix has to be gone before WP::main()
		 * parses the request a moment from now.
		 */
		$this->strip_prefix();

		add_filter( 'query_vars', array( $this, 'register_query_var' ) );

		// Keep generated links inside the current language.
		add_filter( 'post_type_link', array( $this, 'localise_link' ), 10, 2 );
		add_filter( 'post_link', array( $this, 'localise_link' ), 10, 2 );
		add_filter( 'page_link', array( $this, 'localise_page_link' ), 10, 2 );
		add_filter( 'term_link', array( $this, 'localise_term_link' ), 10, 3 );
		add_filter( 'home_url', array( $this, 'localise_home_url' ), 10, 2 );

		add_action( 'wp_head', array( $this, 'print_hreflang' ), 1 );
	}

	/**
	 * Strip the language prefix before WordPress routes the request.
	 *
	 * Earlier drafts registered /en/ rewrite rules and re-ran the rule table by
	 * hand. That worked, but it silently skipped the post-type mapping core
	 * does inside WP::parse_request(), so /en/product/x/ resolved to the blog
	 * index. Removing the prefix from REQUEST_URI instead lets core do all of
	 * its own routing untouched — every permalink structure, custom post type
	 * and plugin endpoint keeps working, now and after future core changes.
	 *
	 * The language is already known by then: MCR_Languages reads the prefix
	 * straight off the raw path.
	 */
	public function add_rewrite_rules(): void {
		$this->strip_prefix();
	}

	/**
	 * Remove the language segment from the request URI.
	 */
	public function strip_prefix(): void {
		if ( is_admin() || wp_doing_cron() ) {
			return;
		}

		if ( ! isset( $_SERVER['REQUEST_URI'] ) ) {
			return;
		}

		$language = MCR_Languages::instance()->current();
		if ( MCR_Translator::is_default( $language ) ) {
			return;
		}

		$languages = MCR_Languages::instance()->languages();
		$slug      = $languages[ $language ]['slug'] ?? '';

		if ( '' === $slug ) {
			return;
		}

		$home_path = (string) wp_parse_url( (string) get_option( 'home' ), PHP_URL_PATH );
		$home_path = ( '' === $home_path || '/' === $home_path ) ? '' : untrailingslashit( $home_path );

		$pattern = '#^' . preg_quote( $home_path, '#' ) . '/' . preg_quote( $slug, '#' ) . '(/|\?|$)#';

		/*
		 * WP::parse_request() prefers PATH_INFO over REQUEST_URI when the
		 * server provides it (PHP's built-in server and some FastCGI setups
		 * do), so every path variable has to lose the prefix or core routes
		 * from a stale one.
		 */
		foreach ( array( 'REQUEST_URI', 'PATH_INFO', 'ORIG_PATH_INFO' ) as $key ) {
			if ( empty( $_SERVER[ $key ] ) ) {
				continue;
			}

			$value = esc_url_raw( wp_unslash( (string) $_SERVER[ $key ] ) );

			if ( ! preg_match( $pattern, $value ) ) {
				continue;
			}

			$stripped = (string) preg_replace( $pattern, $home_path . '/$1', $value, 1 );
			$stripped = (string) preg_replace( '#^' . preg_quote( $home_path, '#' ) . '//+#', $home_path . '/', $stripped );

			$_SERVER[ $key ] = $stripped;
		}
	}

	/**
	 * Make our query vars public.
	 *
	 * @param string[] $vars Query vars.
	 * @return string[]
	 */
	public function register_query_var( array $vars ): array {
		$vars[] = 'mcr_lang';
		$vars[] = 'mcr_path';
		return $vars;
	}

	/**
	 * Prefix a URL for a language.
	 *
	 * @param string $url      Absolute URL.
	 * @param string $language Language code.
	 */
	public function localise_url( string $url, string $language ): string {
		$languages = MCR_Languages::instance()->languages();
		$slug      = $languages[ $language ]['slug'] ?? '';

		/*
		 * The raw option, not home_url(): this class filters home_url(), so
		 * calling it here would re-enter and never return.
		 */
		$home = trailingslashit( (string) get_option( 'home' ) );

		// Only touch our own URLs.
		if ( ! str_starts_with( $url, $home ) ) {
			return $url;
		}

		$path = substr( $url, strlen( $home ) );

		// Strip any existing language prefix first.
		foreach ( $languages as $existing ) {
			if ( '' !== $existing['slug'] && ( $path === $existing['slug'] || str_starts_with( $path, $existing['slug'] . '/' ) ) ) {
				$path = ltrim( substr( $path, strlen( $existing['slug'] ) ), '/' );
				break;
			}
		}

		return '' === $slug ? $home . $path : $home . $slug . '/' . $path;
	}

	/**
	 * Localise a post permalink, swapping in the translated slug when set.
	 *
	 * @param string  $url  Permalink.
	 * @param WP_Post $post Post object.
	 */
	public function localise_link( string $url, $post ): string {
		if ( is_admin() || ! $post instanceof WP_Post ) {
			return $url;
		}

		return $this->localise_url( $url, MCR_Languages::instance()->current() );
	}

	/**
	 * Localise a page link.
	 *
	 * @param string $url     Permalink.
	 * @param int    $post_id Page ID.
	 */
	public function localise_page_link( string $url, $post_id ): string {
		if ( is_admin() ) {
			return $url;
		}

		return $this->localise_url( $url, MCR_Languages::instance()->current() );
	}

	/**
	 * Localise a term link.
	 *
	 * @param string  $url      Term URL.
	 * @param WP_Term $term     Term.
	 * @param string  $taxonomy Taxonomy.
	 */
	public function localise_term_link( string $url, $term, $taxonomy ): string {
		if ( is_admin() ) {
			return $url;
		}

		return $this->localise_url( $url, MCR_Languages::instance()->current() );
	}

	/**
	 * Localise home_url() so menus, logos and redirects stay in language.
	 *
	 * @param string $url  Home URL.
	 * @param string $path Requested path.
	 */
	public function localise_home_url( string $url, $path ): string {
		if ( is_admin() || wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return $url;
		}

		// Never rewrite asset or endpoint URLs.
		if ( is_string( $path ) && preg_match( '#\.(php|xml|json|css|js|ico|txt)$#i', $path ) ) {
			return $url;
		}

		$current = MCR_Languages::instance()->current();

		if ( MCR_Translator::is_default( $current ) ) {
			return $url;
		}

		return $this->localise_url( $url, $current );
	}

	/**
	 * The current URL rendered in another language.
	 *
	 * @param string $language Target language code.
	 */
	public function alternate_url( string $language ): string {
		global $wp;

		$request = ( $wp instanceof WP && $wp->request ) ? user_trailingslashit( $wp->request ) : '';

		// Raw option for the same re-entrancy reason as localise_url().
		$url = trailingslashit( (string) get_option( 'home' ) ) . ltrim( (string) $request, '/' );

		return $this->localise_url( $url, $language );
	}

	/**
	 * Print hreflang alternates and a self-referencing canonical.
	 *
	 * Without these two together, Google reads the French and English versions
	 * of a page as duplicates competing with each other.
	 */
	public function print_hreflang(): void {
		if ( is_404() || is_search() ) {
			return;
		}

		$languages = MCR_Languages::instance()->languages();

		foreach ( $languages as $code => $language ) {
			printf(
				'<link rel="alternate" hreflang="%s" href="%s" />' . "\n",
				esc_attr( $language['locale_short'] ),
				esc_url( $this->alternate_url( $code ) )
			);
		}

		printf(
			'<link rel="alternate" hreflang="x-default" href="%s" />' . "\n",
			esc_url( $this->alternate_url( MCR_Languages::instance()->default_code() ) )
		);
	}
}
