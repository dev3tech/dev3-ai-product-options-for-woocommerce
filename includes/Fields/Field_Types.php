<?php
/**
 * Field type registry (mirrored in src/shared/types.js).
 *
 * @package Dev3\AiProductOptions
 */

namespace Dev3\AiProductOptions\Fields;

defined( 'ABSPATH' ) || exit;

/**
 * Field type metadata.
 */
class Field_Types {

	/**
	 * Type => [ group, kind ] where kind is text|number|choice|multi|bool|file|date|layout|repeater|color|hidden.
	 */
	const TYPES = array(
		'text'         => array( 'text', 'text' ),
		'textarea'     => array( 'text', 'text' ),
		'email'        => array( 'text', 'text' ),
		'url'          => array( 'text', 'text' ),
		'tel'          => array( 'text', 'text' ),
		'number'       => array( 'number', 'number' ),
		'range'        => array( 'number', 'number' ),
		'select'       => array( 'choice', 'choice' ),
		'radio'        => array( 'choice', 'choice' ),
		'checkboxes'   => array( 'choice', 'multi' ),
		'color_swatch' => array( 'choice', 'choice' ),
		'image_swatch' => array( 'choice', 'choice' ),
		'image_picker' => array( 'choice', 'choice' ),
		'checkbox'     => array( 'choice', 'bool' ),
		'toggle'       => array( 'choice', 'bool' ),
		'date'         => array( 'date', 'date' ),
		'time'         => array( 'date', 'date' ),
		'datetime'     => array( 'date', 'date' ),
		'file'         => array( 'media', 'file' ),
		'color'        => array( 'media', 'color' ),
		'hidden'       => array( 'advanced', 'hidden' ),
		'repeater'     => array( 'layout', 'repeater' ),
		'section'      => array( 'layout', 'layout' ),
		'step'         => array( 'layout', 'layout' ),
		'paragraph'    => array( 'layout', 'layout' ),
	);

	/**
	 * Price types valid per kind.
	 */
	const PRICE_TYPES = array(
		'text'     => array( 'none', 'flat', 'percent', 'per_char', 'qty_tiers', 'formula' ),
		'number'   => array( 'none', 'flat', 'percent', 'per_unit', 'qty_tiers', 'formula' ),
		'choice'   => array( 'none', 'formula' ),
		'multi'    => array( 'none', 'formula' ),
		'bool'     => array( 'none', 'flat', 'percent', 'qty_tiers', 'formula' ),
		'date'     => array( 'none', 'flat', 'percent', 'formula' ),
		'file'     => array( 'none', 'flat', 'percent', 'per_unit', 'formula' ),
		'color'    => array( 'none', 'flat', 'percent', 'formula' ),
		'hidden'   => array( 'none', 'flat', 'percent', 'formula' ),
		'repeater' => array( 'none', 'flat', 'formula' ),
		'layout'   => array( 'none' ),
	);

	const OPTION_PRICE_TYPES = array( 'flat', 'percent' );

	const OPERATORS = array( 'is', 'is_not', 'contains', 'not_contains', 'empty', 'not_empty', 'gt', 'lt', 'gte', 'lte', 'checked', 'unchecked' );

	/**
	 * Whether a type exists.
	 *
	 * @param string $type Type.
	 * @return bool
	 */
	public static function exists( $type ) {
		return isset( self::TYPES[ $type ] );
	}

	/**
	 * Kind of a type.
	 *
	 * @param string $type Type.
	 * @return string
	 */
	public static function kind( $type ) {
		return isset( self::TYPES[ $type ] ) ? self::TYPES[ $type ][1] : 'text';
	}

	/**
	 * Whether the type has an options list.
	 *
	 * @param string $type Type.
	 * @return bool
	 */
	public static function has_options( $type ) {
		return in_array( self::kind( $type ), array( 'choice', 'multi' ), true );
	}

	/**
	 * Whether the type collects input from the shopper.
	 *
	 * @param string $type Type.
	 * @return bool
	 */
	public static function is_input( $type ) {
		return 'layout' !== self::kind( $type );
	}

	/**
	 * Allowed price types for a type.
	 *
	 * @param string $type Type.
	 * @return string[]
	 */
	public static function price_types( $type ) {
		return self::PRICE_TYPES[ self::kind( $type ) ];
	}

	/**
	 * Labels for the admin palette.
	 *
	 * @return array
	 */
	public static function labels() {
		return array(
			'text'         => __( 'Text', 'dev3-ai-product-options-for-woocommerce' ),
			'textarea'     => __( 'Textarea', 'dev3-ai-product-options-for-woocommerce' ),
			'email'        => __( 'Email', 'dev3-ai-product-options-for-woocommerce' ),
			'url'          => __( 'URL', 'dev3-ai-product-options-for-woocommerce' ),
			'tel'          => __( 'Phone', 'dev3-ai-product-options-for-woocommerce' ),
			'number'       => __( 'Number', 'dev3-ai-product-options-for-woocommerce' ),
			'range'        => __( 'Range slider', 'dev3-ai-product-options-for-woocommerce' ),
			'select'       => __( 'Dropdown', 'dev3-ai-product-options-for-woocommerce' ),
			'radio'        => __( 'Radio buttons', 'dev3-ai-product-options-for-woocommerce' ),
			'checkboxes'   => __( 'Checkbox group', 'dev3-ai-product-options-for-woocommerce' ),
			'color_swatch' => __( 'Colour swatches', 'dev3-ai-product-options-for-woocommerce' ),
			'image_swatch' => __( 'Image swatches', 'dev3-ai-product-options-for-woocommerce' ),
			'image_picker' => __( 'Image picker', 'dev3-ai-product-options-for-woocommerce' ),
			'checkbox'     => __( 'Single checkbox', 'dev3-ai-product-options-for-woocommerce' ),
			'toggle'       => __( 'Toggle switch', 'dev3-ai-product-options-for-woocommerce' ),
			'date'         => __( 'Date', 'dev3-ai-product-options-for-woocommerce' ),
			'time'         => __( 'Time', 'dev3-ai-product-options-for-woocommerce' ),
			'datetime'     => __( 'Date & time', 'dev3-ai-product-options-for-woocommerce' ),
			'file'         => __( 'File upload', 'dev3-ai-product-options-for-woocommerce' ),
			'color'        => __( 'Colour picker', 'dev3-ai-product-options-for-woocommerce' ),
			'hidden'       => __( 'Hidden value', 'dev3-ai-product-options-for-woocommerce' ),
			'repeater'     => __( 'Repeater', 'dev3-ai-product-options-for-woocommerce' ),
			'section'      => __( 'Section', 'dev3-ai-product-options-for-woocommerce' ),
			'step'         => __( 'Step', 'dev3-ai-product-options-for-woocommerce' ),
			'paragraph'    => __( 'Paragraph', 'dev3-ai-product-options-for-woocommerce' ),
		);
	}
}
