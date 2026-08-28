<?php
/**
 * Translate taxonomy terms: categories, tags and attribute values.
 *
 * This is what makes "Vert / Green" work on a variation dropdown, which the
 * source artifact encoded as a piped string.
 *
 * @package MCR_Bilingue
 */

defined( 'ABSPATH' ) || exit;

/**
 * Applies translations to terms.
 */
final class MCR_Term_Filters {

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
		add_filter( 'get_term', array( $this, 'translate_term' ), 10, 2 );
		add_filter( 'get_terms', array( $this, 'translate_terms' ), 10, 2 );
		add_filter( 'wc_product_attribute_label', array( $this, 'translate_attribute_label' ), 10, 2 );
		add_filter( 'woocommerce_variation_option_name', array( $this, 'translate_option_name' ) );
	}

	/**
	 * Whether translation should apply.
	 */
	private function active(): bool {
		if ( is_admin() && ! wp_doing_ajax() ) {
			return false;
		}

		return ! MCR_Translator::is_default( MCR_Languages::instance()->current() );
	}

	/**
	 * Translate one term object.
	 *
	 * @param WP_Term|mixed $term     Term.
	 * @param string        $taxonomy Taxonomy.
	 * @return WP_Term|mixed
	 */
	public function translate_term( $term, $taxonomy = '' ) {
		if ( ! $this->active() || ! $term instanceof WP_Term ) {
			return $term;
		}

		$language = MCR_Languages::instance()->current();

		$name = MCR_Translator::get_term_field( $term->term_id, 'name', $language );
		if ( '' !== $name ) {
			$term->name = $name;
		} else {
			// Support the artifact's "Vert|Green" convention with no migration.
			$term->name = MCR_Translator::split_piped( $term->name, $language );
		}

		$description = MCR_Translator::get_term_field( $term->term_id, 'description', $language );
		if ( '' !== $description ) {
			$term->description = $description;
		}

		return $term;
	}

	/**
	 * Translate a list of terms.
	 *
	 * @param array|mixed $terms Terms.
	 * @param array|mixed $args  Query args.
	 * @return array|mixed
	 */
	public function translate_terms( $terms, $args = array() ) {
		if ( ! $this->active() || ! is_array( $terms ) ) {
			return $terms;
		}

		foreach ( $terms as $index => $term ) {
			if ( $term instanceof WP_Term ) {
				$terms[ $index ] = $this->translate_term( $term );
			}
		}

		return $terms;
	}

	/**
	 * Translate an attribute label, e.g. "Longueur" to "Length".
	 *
	 * @param string $label     Label.
	 * @param string $attribute Attribute name.
	 */
	public function translate_attribute_label( string $label, $attribute = '' ): string {
		if ( ! $this->active() ) {
			return $label;
		}

		$language = MCR_Languages::instance()->current();

		$map = apply_filters( 'mcrb_attribute_labels', array(
			'Longueur' => array( 'en' => 'Length' ),
			'Format'   => array( 'en' => 'Size' ),
			'Couleur'  => array( 'en' => 'Colour' ),
			'Marque'   => array( 'en' => 'Brand' ),
		) );

		if ( isset( $map[ $label ][ $language ] ) ) {
			return $map[ $label ][ $language ];
		}

		return MCR_Translator::split_piped( $label, $language );
	}

	/**
	 * Translate a variation option shown in a dropdown.
	 *
	 * Handles custom (non-taxonomy) attributes, where the value is a plain
	 * string on the product rather than a term.
	 *
	 * @param string $name Option name.
	 */
	public function translate_option_name( string $name ): string {
		if ( ! $this->active() ) {
			return $name;
		}

		return MCR_Translator::split_piped( $name, MCR_Languages::instance()->current() );
	}
}
