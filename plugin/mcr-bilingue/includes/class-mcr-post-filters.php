<?php
/**
 * Swap post content to the current language at render time.
 *
 * @package MCR_Bilingue
 */

defined( 'ABSPATH' ) || exit;

/**
 * Applies translations to titles, excerpts and content.
 */
final class MCR_Post_Filters {

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
		add_filter( 'the_title', array( $this, 'translate_title' ), 10, 2 );
		add_filter( 'the_content', array( $this, 'translate_content' ), 5 );
		add_filter( 'get_the_excerpt', array( $this, 'translate_excerpt' ), 10, 2 );

		// WooCommerce reads product names through the data store, not the_title.
		add_filter( 'woocommerce_product_get_name', array( $this, 'translate_product_name' ), 10, 2 );
		add_filter( 'woocommerce_product_variation_get_name', array( $this, 'translate_product_name' ), 10, 2 );
		add_filter( 'woocommerce_product_get_short_description', array( $this, 'translate_product_excerpt' ), 10, 2 );
		add_filter( 'woocommerce_product_get_description', array( $this, 'translate_product_description' ), 10, 2 );
	}

	/**
	 * Whether translation should apply to this request.
	 */
	private function active(): bool {
		if ( is_admin() && ! wp_doing_ajax() ) {
			return false;
		}

		return ! MCR_Translator::is_default( MCR_Languages::instance()->current() );
	}

	/**
	 * Translate a post title.
	 *
	 * @param string   $title   Title.
	 * @param int|null $post_id Post ID.
	 */
	public function translate_title( string $title, $post_id = null ): string {
		if ( ! $this->active() || ! $post_id ) {
			return $title;
		}

		$translated = MCR_Translator::get_post_field(
			(int) $post_id,
			'title',
			MCR_Languages::instance()->current()
		);

		return '' !== $translated ? $translated : $title;
	}

	/**
	 * Translate post content.
	 *
	 * @param string $content Content.
	 */
	public function translate_content( string $content ): string {
		if ( ! $this->active() ) {
			return $content;
		}

		$post_id = get_the_ID();
		if ( ! $post_id ) {
			return $content;
		}

		$translated = MCR_Translator::get_post_field(
			(int) $post_id,
			'content',
			MCR_Languages::instance()->current()
		);

		return '' !== $translated ? $translated : $content;
	}

	/**
	 * Translate an excerpt.
	 *
	 * @param string       $excerpt Excerpt.
	 * @param WP_Post|null $post    Post.
	 */
	public function translate_excerpt( string $excerpt, $post = null ): string {
		if ( ! $this->active() || ! $post instanceof WP_Post ) {
			return $excerpt;
		}

		$translated = MCR_Translator::get_post_field(
			$post->ID,
			'excerpt',
			MCR_Languages::instance()->current()
		);

		return '' !== $translated ? $translated : $excerpt;
	}

	/**
	 * Translate a product name.
	 *
	 * @param string     $name    Name.
	 * @param WC_Product $product Product.
	 */
	public function translate_product_name( string $name, $product ): string {
		if ( ! $this->active() || ! $product instanceof WC_Product ) {
			return $name;
		}

		// A variation inherits its parent's translated name.
		$id = $product->is_type( 'variation' ) ? $product->get_parent_id() : $product->get_id();

		$translated = MCR_Translator::get_post_field(
			(int) $id,
			'title',
			MCR_Languages::instance()->current()
		);

		return '' !== $translated ? $translated : $name;
	}

	/**
	 * Translate a product short description.
	 *
	 * @param string     $value   Short description.
	 * @param WC_Product $product Product.
	 */
	public function translate_product_excerpt( string $value, $product ): string {
		if ( ! $this->active() || ! $product instanceof WC_Product ) {
			return $value;
		}

		$translated = MCR_Translator::get_post_field(
			$product->get_id(),
			'excerpt',
			MCR_Languages::instance()->current()
		);

		return '' !== $translated ? $translated : $value;
	}

	/**
	 * Translate a product description.
	 *
	 * @param string     $value   Description.
	 * @param WC_Product $product Product.
	 */
	public function translate_product_description( string $value, $product ): string {
		if ( ! $this->active() || ! $product instanceof WC_Product ) {
			return $value;
		}

		$translated = MCR_Translator::get_post_field(
			$product->get_id(),
			'content',
			MCR_Languages::instance()->current()
		);

		return '' !== $translated ? $translated : $value;
	}
}
