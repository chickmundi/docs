<?php
/**
 * Public API.
 *
 * These are the functions the theme calls. Keeping them as thin wrappers means
 * the theme never has to know the class names, and the theme's
 * `mcr_is_bilingual()` check is simply "does mcr_current_language() exist".
 *
 * @package MCR_Bilingue
 */

defined( 'ABSPATH' ) || exit;

/**
 * The language code for the current request.
 */
function mcr_current_language(): string {
	return MCR_Languages::instance()->current();
}

/**
 * All languages this site serves.
 *
 * @return array<string,array>
 */
function mcr_available_languages(): array {
	return MCR_Languages::instance()->languages();
}

/**
 * The locale for a language code.
 *
 * @param string $language Language code.
 */
function mcr_language_locale( string $language ): string {
	return MCR_Languages::instance()->locale( $language );
}

/**
 * The current URL in another language.
 *
 * @param string $language Language code.
 */
function mcr_translated_permalink( string $language ): string {
	return MCR_Router::instance()->alternate_url( $language );
}

/**
 * A translated term field, falling back to the original.
 *
 * @param WP_Term $term  Term.
 * @param string  $field Field key.
 */
function mcr_translate_term_field( WP_Term $term, string $field ): string {
	$language = mcr_current_language();

	$value = MCR_Translator::get_term_field( $term->term_id, $field, $language );
	if ( '' !== $value ) {
		return $value;
	}

	$original = $term->$field ?? '';

	return MCR_Translator::split_piped( (string) $original, $language );
}

/**
 * Pick one of two strings by current language.
 *
 * @param string $fr French.
 * @param string $en English.
 */
function mcr_pick( string $fr, string $en ): string {
	return 'en' === mcr_current_language() ? $en : $fr;
}
