<?php
/**
 * Translation storage.
 *
 * One record per product, with the English values stored alongside the French
 * in post meta. This is the deliberate alternative to duplicating posts: stock,
 * price and SKU stay singular, which is what a grocery with one physical shelf
 * actually needs.
 *
 * @package MCR_Bilingue
 */

defined( 'ABSPATH' ) || exit;

/**
 * Reads and writes translated field values.
 */
final class MCR_Translator {

	/**
	 * Meta key prefix. Leading underscore keeps these out of the generic
	 * custom-fields box in the editor.
	 */
	public const META_PREFIX = '_mcr_t_';

	/**
	 * Fields that can be translated on a post.
	 *
	 * @return array<string,string> Field key => human label.
	 */
	public static function post_fields(): array {
		return apply_filters( 'mcrb_post_fields', array(
			'title'   => __( 'Title', 'mcr-bilingue' ),
			'excerpt' => __( 'Short description', 'mcr-bilingue' ),
			'content' => __( 'Description', 'mcr-bilingue' ),
		) );
	}

	/**
	 * Fields that can be translated on a term.
	 *
	 * @return array<string,string>
	 */
	public static function term_fields(): array {
		return apply_filters( 'mcrb_term_fields', array(
			'name'        => __( 'Name', 'mcr-bilingue' ),
			'slug'        => __( 'URL slug', 'mcr-bilingue' ),
			'description' => __( 'Description', 'mcr-bilingue' ),
		) );
	}

	/**
	 * Meta key for a field in a language.
	 *
	 * @param string $field    Field key.
	 * @param string $language Language code.
	 */
	public static function meta_key( string $field, string $language ): string {
		return self::META_PREFIX . $language . '_' . $field;
	}

	/**
	 * Read a translated post field.
	 *
	 * Returns an empty string when untranslated so callers can decide whether
	 * to fall back — silently returning French would hide gaps from the shop.
	 *
	 * @param int    $post_id  Post ID.
	 * @param string $field    Field key.
	 * @param string $language Language code.
	 */
	public static function get_post_field( int $post_id, string $field, string $language ): string {
		if ( self::is_default( $language ) ) {
			return '';
		}

		$value = get_post_meta( $post_id, self::meta_key( $field, $language ), true );

		return is_string( $value ) ? $value : '';
	}

	/**
	 * Write a translated post field.
	 *
	 * @param int    $post_id  Post ID.
	 * @param string $field    Field key.
	 * @param string $language Language code.
	 * @param string $value    Value; an empty string deletes the translation.
	 */
	public static function set_post_field( int $post_id, string $field, string $language, string $value ): void {
		if ( self::is_default( $language ) ) {
			return;
		}

		$key = self::meta_key( $field, $language );

		if ( '' === trim( $value ) ) {
			delete_post_meta( $post_id, $key );
			return;
		}

		$value = 'content' === $field || 'excerpt' === $field
			? wp_kses_post( $value )
			: sanitize_text_field( $value );

		update_post_meta( $post_id, $key, $value );
	}

	/**
	 * Read a translated term field.
	 *
	 * @param int    $term_id  Term ID.
	 * @param string $field    Field key.
	 * @param string $language Language code.
	 */
	public static function get_term_field( int $term_id, string $field, string $language ): string {
		if ( self::is_default( $language ) ) {
			return '';
		}

		$value = get_term_meta( $term_id, self::meta_key( $field, $language ), true );

		return is_string( $value ) ? $value : '';
	}

	/**
	 * Write a translated term field.
	 *
	 * @param int    $term_id  Term ID.
	 * @param string $field    Field key.
	 * @param string $language Language code.
	 * @param string $value    Value; empty deletes.
	 */
	public static function set_term_field( int $term_id, string $field, string $language, string $value ): void {
		if ( self::is_default( $language ) ) {
			return;
		}

		$key = self::meta_key( $field, $language );

		if ( '' === trim( $value ) ) {
			delete_term_meta( $term_id, $key );
			return;
		}

		$value = 'slug' === $field ? sanitize_title( $value ) : (
			'description' === $field ? wp_kses_post( $value ) : sanitize_text_field( $value )
		);

		update_term_meta( $term_id, $key, $value );
	}

	/**
	 * Whether a code is the default (untranslated) language.
	 *
	 * @param string $language Language code.
	 */
	public static function is_default( string $language ): bool {
		return $language === MCR_Languages::instance()->default_code();
	}

	/**
	 * Split a piped "Français|English" string, as used in the source artifact.
	 *
	 * Kept so catalogue data written in that convention — attribute values like
	 * "Vert|Green" — can be imported and read without a migration step.
	 *
	 * @param string $value    Raw value.
	 * @param string $language Language code.
	 */
	public static function split_piped( string $value, string $language ): string {
		if ( ! str_contains( $value, '|' ) ) {
			return $value;
		}

		$parts = explode( '|', $value, 2 );

		return self::is_default( $language ) ? $parts[0] : ( $parts[1] ?: $parts[0] );
	}

	/**
	 * Translation completeness for a post, as a percentage.
	 *
	 * Drives the "needs translation" column in the admin list so gaps are
	 * visible rather than discovered by a customer.
	 *
	 * @param int    $post_id  Post ID.
	 * @param string $language Language code.
	 */
	public static function post_completeness( int $post_id, string $language ): int {
		$fields = self::post_fields();
		$total  = 0;
		$done   = 0;

		foreach ( array_keys( $fields ) as $field ) {
			$source = 'title' === $field
				? get_the_title( $post_id )
				: ( 'excerpt' === $field ? get_post_field( 'post_excerpt', $post_id ) : get_post_field( 'post_content', $post_id ) );

			// Only count fields that actually have French content to translate.
			if ( '' === trim( (string) $source ) ) {
				continue;
			}

			$total++;

			if ( '' !== self::get_post_field( $post_id, $field, $language ) ) {
				$done++;
			}
		}

		return $total > 0 ? (int) round( ( $done / $total ) * 100 ) : 100;
	}
}
