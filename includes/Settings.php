<?php
/**
 * Plugin-specific settings (shared AI settings live in Core3\Core\Settings_Store).
 *
 * @package Dev3\AiProductOptions
 */

namespace Dev3\AiProductOptions;

defined( 'ABSPATH' ) || exit;

/**
 * Hooked into dev3po_settings_defaults / dev3po_settings_sanitize.
 */
class Settings {

	const FLAGS = array( 'enabled', 'show_totals', 'cart_edit', 'cart_show_prices', 'ai_generate', 'ai_logic', 'ai_formula', 'ai_labels', 'ai_vision' );

	/**
	 * Adds plugin defaults.
	 *
	 * @param array $defaults Shared defaults.
	 * @return array
	 */
	public static function defaults( $defaults ) {
		return array_merge(
			$defaults,
			array(
				'enabled'             => true,
				'show_totals'         => true,
				'cart_edit'           => true,
				'cart_show_prices'    => true,
				'loop_button'         => 'required',
				'loop_button_text'    => __( 'Select options', 'dev3-ai-product-options-for-woocommerce' ),
				'label_options_total' => __( 'Options total', 'dev3-ai-product-options-for-woocommerce' ),
				'label_grand_total'   => __( 'Total', 'dev3-ai-product-options-for-woocommerce' ),
				'accent_color'        => '',
				'upload_max_mb'       => 8,
				'upload_retention'    => 14,
				'upload_rate'         => 12,
				'vision_mode'         => 'warn',
				'ai_generate'         => true,
				'ai_logic'            => true,
				'ai_formula'          => true,
				'ai_labels'           => true,
				'ai_vision'           => true,
			)
		);
	}

	/**
	 * Sanitizes plugin keys.
	 *
	 * @param array $out     Sanitized so far.
	 * @param array $input   Raw input.
	 * @param array $current Current.
	 * @return array
	 */
	public static function sanitize( $out, $input, $current ) {
		unset( $current );
		foreach ( self::FLAGS as $flag ) {
			if ( isset( $input[ $flag ] ) ) {
				$out[ $flag ] = (bool) $input[ $flag ];
			}
		}
		if ( isset( $input['loop_button'] ) && in_array( $input['loop_button'], array( 'required', 'always', 'never' ), true ) ) {
			$out['loop_button'] = $input['loop_button'];
		}
		if ( isset( $input['vision_mode'] ) && in_array( $input['vision_mode'], array( 'warn', 'block' ), true ) ) {
			$out['vision_mode'] = $input['vision_mode'];
		}
		foreach ( array(
			'loop_button_text'    => 40,
			'label_options_total' => 40,
			'label_grand_total'   => 40,
		) as $key => $max ) {
			if ( isset( $input[ $key ] ) ) {
				$value       = mb_substr( sanitize_text_field( wp_unslash( (string) $input[ $key ] ) ), 0, $max );
				$out[ $key ] = '' !== $value ? $value : self::defaults( array() )[ $key ];
			}
		}
		if ( isset( $input['accent_color'] ) ) {
			$out['accent_color'] = '' === $input['accent_color'] ? '' : (string) sanitize_hex_color( (string) $input['accent_color'] );
		}
		if ( isset( $input['upload_max_mb'] ) ) {
			$out['upload_max_mb'] = round( min( 64, max( 0.5, (float) $input['upload_max_mb'] ) ), 1 );
		}
		if ( isset( $input['upload_retention'] ) ) {
			$out['upload_retention'] = min( 365, max( 1, (int) $input['upload_retention'] ) );
		}
		if ( isset( $input['upload_rate'] ) ) {
			$out['upload_rate'] = min( 120, max( 1, (int) $input['upload_rate'] ) );
		}
		return $out;
	}
}
