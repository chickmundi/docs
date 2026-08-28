<?php
/**
 * Language registry and detection.
 *
 * @package MCR_Bilingue
 */

defined( 'ABSPATH' ) || exit;

/**
 * Knows which languages exist and which one the current request is in.
 */
final class MCR_Languages {

	/**
	 * Singleton.
	 *
	 * @var self|null
	 */
	private static ?self $instance = null;

	/**
	 * Resolved language for this request.
	 *
	 * @var string|null
	 */
	private ?string $current = null;

	/**
	 * Re-entrancy guard.
	 *
	 * Detection calls home_url(), and the router filters home_url() by asking
	 * which language it is. Without this flag those two call each other until
	 * the request dies. Any re-entrant call gets the default language, which is
	 * always the right answer for the unprefixed URL home_url() is building.
	 *
	 * @var bool
	 */
	private bool $resolving = false;

	/**
	 * Cookie remembering a visitor's explicit choice.
	 */
	public const COOKIE = 'mcr_lang';

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
		add_filter( 'locale', array( $this, 'filter_locale' ) );
		add_action( 'init', array( $this, 'maybe_set_cookie' ) );
	}

	/**
	 * The languages this site serves.
	 *
	 * French is the default and owns the bare URLs; English is served under a
	 * path prefix. Adding a third language is a matter of adding a row.
	 *
	 * @return array<string,array{label:string,locale:string,locale_short:string,slug:string,default:bool}>
	 */
	public function languages(): array {
		/*
		 * Nothing in this table may call __() or any other translation
		 * function. Translating calls get_locale(), the locale filter asks this
		 * class which language it is, and the request never returns. The names
		 * below are endonyms — a French speaker sees "Français" in either
		 * language — so there is nothing to translate anyway.
		 */
		return apply_filters( 'mcrb_languages', array(
			'fr' => array(
				'label'        => 'FR',
				'name'         => 'Français',
				'locale'       => 'fr_CA',
				'locale_short' => 'fr-CA',
				'slug'         => '',
				'default'      => true,
			),
			'en' => array(
				'label'        => 'EN',
				'name'         => 'English',
				'locale'       => 'en_CA',
				'locale_short' => 'en-CA',
				'slug'         => 'en',
				'default'      => false,
			),
		) );
	}

	/**
	 * Language codes.
	 *
	 * @return string[]
	 */
	public function codes(): array {
		return array_keys( $this->languages() );
	}

	/**
	 * The default language code.
	 */
	public function default_code(): string {
		/*
		 * Deliberately does not read languages(): default_code() is the answer
		 * the re-entrancy guard hands back, so it must never call anything that
		 * could route back into language detection.
		 */
		return (string) apply_filters( 'mcrb_default_language', 'fr' );
	}

	/**
	 * Whether a code is one we serve.
	 *
	 * @param string $code Candidate code.
	 */
	public function is_valid( string $code ): bool {
		return array_key_exists( $code, $this->languages() );
	}

	/**
	 * The language for this request.
	 *
	 * Resolution order, most explicit first:
	 *   1. the URL prefix, so a shared link always opens in its own language
	 *   2. an explicit ?lang= override, used by the switcher on unprefixed pages
	 *   3. the visitor's saved choice
	 *   4. the browser's Accept-Language header
	 *   5. the site default
	 *
	 * IP geolocation is deliberately not used: a francophone in Toronto and an
	 * anglophone in Trois-Rivières both get the wrong answer from it.
	 */
	public function current(): string {
		if ( null !== $this->current ) {
			return $this->current;
		}

		if ( $this->resolving ) {
			return $this->default_code();
		}

		$this->resolving = true;

		try {
			return $this->current = $this->resolve();
		} finally {
			$this->resolving = false;
		}
	}

	/**
	 * Work out the language for this request.
	 *
	 * Split from current() so the guard above wraps every path out of it.
	 */
	private function resolve(): string {

		// Admin and REST stay in the user's own locale, not the visitor's.
		if ( is_admin() && ! wp_doing_ajax() ) {
			return $this->default_code();
		}

		$from_url = $this->from_request_path();
		if ( $from_url ) {
			return $from_url;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- public language selection.
		$requested = isset( $_GET['lang'] ) ? sanitize_key( wp_unslash( (string) $_GET['lang'] ) ) : '';
		if ( $requested && $this->is_valid( $requested ) ) {
			return $requested;
		}

		$cookie = isset( $_COOKIE[ self::COOKIE ] ) ? sanitize_key( wp_unslash( (string) $_COOKIE[ self::COOKIE ] ) ) : '';
		if ( $cookie && $this->is_valid( $cookie ) ) {
			return $cookie;
		}

		$from_header = $this->from_accept_language();
		if ( $from_header ) {
			return $from_header;
		}

		return $this->default_code();
	}

	/**
	 * Read the language prefix off the request path.
	 *
	 * Parsed from the raw path rather than a query var, because this has to
	 * answer correctly before WordPress has parsed the request.
	 */
	private function from_request_path(): string {
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( (string) $_SERVER['REQUEST_URI'] ) ) : '';
		if ( '' === $uri ) {
			return '';
		}

		$path = (string) wp_parse_url( $uri, PHP_URL_PATH );

		/*
		 * Read the subdirectory from the stored option rather than home_url(),
		 * which is filtered and would re-enter this method.
		 */
		$home_path = (string) wp_parse_url( (string) get_option( 'home' ), PHP_URL_PATH );
		$home_path = '' === $home_path ? '/' : trailingslashit( $home_path );
		if ( '/' !== $home_path && str_starts_with( $path, $home_path ) ) {
			$path = substr( $path, strlen( $home_path ) - 1 );
		}

		$first = strtok( trim( $path, '/' ), '/' );
		if ( false === $first || '' === $first ) {
			return '';
		}

		foreach ( $this->languages() as $code => $language ) {
			if ( '' !== $language['slug'] && $language['slug'] === $first ) {
				return $code;
			}
		}

		return '';
	}

	/**
	 * Best match from the browser's Accept-Language header.
	 */
	private function from_accept_language(): string {
		$header = isset( $_SERVER['HTTP_ACCEPT_LANGUAGE'] )
			? sanitize_text_field( wp_unslash( (string) $_SERVER['HTTP_ACCEPT_LANGUAGE'] ) )
			: '';

		if ( '' === $header ) {
			return '';
		}

		$best    = '';
		$best_q  = 0.0;
		$codes   = $this->codes();

		foreach ( explode( ',', $header ) as $chunk ) {
			$parts    = explode( ';', trim( $chunk ) );
			$tag      = strtolower( trim( $parts[0] ) );
			$quality  = 1.0;

			if ( isset( $parts[1] ) && str_starts_with( trim( $parts[1] ), 'q=' ) ) {
				$quality = (float) substr( trim( $parts[1] ), 2 );
			}

			$primary = substr( $tag, 0, 2 );

			if ( in_array( $primary, $codes, true ) && $quality > $best_q ) {
				$best   = $primary;
				$best_q = $quality;
			}
		}

		return $best;
	}

	/**
	 * Remember an explicit choice for next time.
	 */
	public function maybe_set_cookie(): void {
		if ( is_admin() || headers_sent() ) {
			return;
		}

		$current = $this->current();
		$stored  = isset( $_COOKIE[ self::COOKIE ] ) ? sanitize_key( wp_unslash( (string) $_COOKIE[ self::COOKIE ] ) ) : '';

		if ( $stored === $current ) {
			return;
		}

		/*
		 * A functional preference cookie, not tracking: no identifier, no
		 * third party, and it only records which of two languages to show.
		 */
		setcookie(
			self::COOKIE,
			$current,
			array(
				'expires'  => time() + YEAR_IN_SECONDS,
				'path'     => COOKIEPATH ?: '/',
				'domain'   => COOKIE_DOMAIN,
				'secure'   => is_ssl(),
				'httponly' => false,
				'samesite' => 'Lax',
			)
		);
	}

	/**
	 * Translate WordPress's own locale to the displayed language.
	 *
	 * This is what makes WooCommerce's built-in strings — "Add to cart",
	 * "Proceed to checkout", every email — come out in the right language,
	 * using the official language packs rather than anything reimplemented here.
	 *
	 * @param string $locale Incoming locale.
	 */
	public function filter_locale( string $locale ): string {
		if ( is_admin() && ! wp_doing_ajax() ) {
			return $locale;
		}

		$languages = $this->languages();
		$current   = $this->current();

		return $languages[ $current ]['locale'] ?? $locale;
	}

	/**
	 * Locale for a language code.
	 *
	 * @param string $code Language code.
	 */
	public function locale( string $code ): string {
		$languages = $this->languages();
		return $languages[ $code ]['locale'] ?? get_locale();
	}
}
