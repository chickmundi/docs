<?php
/**
 * Bilingual presentation.
 *
 * All storage and routing lives in the MCR Bilingue plugin. This file only
 * renders, and every entry point degrades to French-only when the plugin is
 * inactive.
 *
 * @package MCR
 */

defined( 'ABSPATH' ) || exit;

/**
 * Render the FR/EN switch used in the top bar.
 *
 * Two links, not a <select>: a link is crawlable, gives Google the alternate
 * URL, and works without JavaScript. aria-pressed marks the active language.
 */
function mcr_language_switcher(): void {
	if ( ! mcr_is_bilingual() ) {
		return;
	}

	$languages = mcr_available_languages();
	if ( count( $languages ) < 2 ) {
		return;
	}

	$current = mcr_lang();

	echo '<div class="lang" role="group" aria-label="' . esc_attr__( 'Language', 'mcr' ) . '">';
	foreach ( $languages as $code => $language ) {
		$is_current = ( $code === $current );
		printf(
			'<a href="%1$s" hreflang="%2$s" lang="%2$s" aria-pressed="%3$s"%4$s>%5$s</a>',
			esc_url( mcr_translated_permalink( $code ) ),
			esc_attr( $language['locale_short'] ),
			$is_current ? 'true' : 'false',
			$is_current ? ' aria-current="true"' : '',
			esc_html( $language['label'] )
		);
	}
	echo '</div>';
}

add_filter( 'language_attributes', 'mcr_language_attributes' );
/**
 * Keep <html lang> in step with the displayed language.
 *
 * Screen readers switch pronunciation on this attribute, so a French page
 * announced with lang="en" is genuinely hard to listen to.
 *
 * @param string $output Existing attributes.
 */
function mcr_language_attributes( string $output ): string {
	if ( ! mcr_is_bilingual() ) {
		return $output;
	}

	$locale = mcr_language_locale( mcr_lang() );

	return (string) preg_replace(
		'/lang="[^"]*"/',
		'lang="' . esc_attr( str_replace( '_', '-', $locale ) ) . '"',
		$output
	);
}
