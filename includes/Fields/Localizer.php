<?php
/**
 * Applies stored translations (field i18n) for the visitor's language.
 *
 * @package Dev3\AiProductOptions
 */

namespace Dev3\AiProductOptions\Fields;

defined( 'ABSPATH' ) || exit;

/**
 * Locale detection (WPML, Polylang, TranslatePress or WordPress) and per-field translation.
 */
class Localizer {

	/**
	 * Current locale, e.g. de_DE.
	 *
	 * @return string
	 */
	public static function locale() {
		$locale = '';
		if ( function_exists( 'pll_current_language' ) ) {
			$locale = (string) pll_current_language( 'locale' );
		}
		if ( '' === $locale && has_filter( 'wpml_current_language' ) ) {
			$code = (string) apply_filters( 'wpml_current_language', null ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML API.
			if ( '' !== $code ) {
				$details = apply_filters( 'wpml_active_languages', null ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML API.
				$locale  = is_array( $details ) && isset( $details[ $code ]['default_locale'] ) ? (string) $details[ $code ]['default_locale'] : $code;
			}
		}
		if ( '' === $locale ) {
			$locale = function_exists( 'determine_locale' ) ? determine_locale() : get_locale();
		}
		/**
		 * Locale used to pick option translations.
		 *
		 * @param string $locale Locale.
		 */
		return (string) apply_filters( 'dev3po_locale', $locale );
	}

	/**
	 * Translation entry for a locale (exact, then language prefix).
	 *
	 * @param array  $i18n   Field i18n map.
	 * @param string $locale Locale.
	 * @return array
	 */
	public static function entry( array $i18n, $locale ) {
		if ( isset( $i18n[ $locale ] ) ) {
			return $i18n[ $locale ];
		}
		$lang = strtolower( substr( $locale, 0, 2 ) );
		foreach ( $i18n as $key => $entry ) {
			if ( strtolower( substr( (string) $key, 0, 2 ) ) === $lang ) {
				return $entry;
			}
		}
		return array();
	}

	/**
	 * Translates fields for a locale.
	 *
	 * @param array       $fields Fields.
	 * @param string|null $locale Locale (default: current).
	 * @return array
	 */
	public static function fields( array $fields, $locale = null ) {
		$locale = null === $locale ? self::locale() : $locale;
		foreach ( $fields as &$f ) {
			$t = self::entry( $f['i18n'] ?? array(), $locale );
			foreach ( array( 'label', 'help', 'tooltip', 'placeholder' ) as $k ) {
				if ( ! empty( $t[ $k ] ) ) {
					$f[ $k ] = $t[ $k ];
				}
			}
			if ( ! empty( $t['options'] ) && ! empty( $f['options'] ) ) {
				foreach ( $f['options'] as $i => $opt ) {
					if ( ! empty( $t['options'][ $opt['id'] ] ) ) {
						$f['options'][ $i ]['label'] = $t['options'][ $opt['id'] ];
					}
				}
			}
			if ( ! empty( $f['subfields'] ) ) {
				$f['subfields'] = self::fields( $f['subfields'], $locale );
			}
		}
		unset( $f );
		return $fields;
	}
}
