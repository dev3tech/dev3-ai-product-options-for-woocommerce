<?php
/**
 * Product page integration: renders options inside the add-to-cart form, loop buttons, assets.
 *
 * @package Dev3\AiProductOptions
 */

namespace Dev3\AiProductOptions\Frontend;

use Dev3\AiProductOptions\Core3\Core\Assets;
use Dev3\AiProductOptions\Core3\Core\Settings_Store;
use Dev3\AiProductOptions\Data\Sets_Repository;
use Dev3\AiProductOptions\Engine\Values;
use Dev3\AiProductOptions\Fields\Field_Types;
use Dev3\AiProductOptions\Fields\Localizer;

defined( 'ABSPATH' ) || exit;

/**
 * Storefront hooks.
 */
class Product_Form {

	/**
	 * Registers hooks.
	 */
	public function register() {
		add_action( 'woocommerce_before_add_to_cart_button', array( $this, 'output' ), 15 );
		add_action( 'wp_enqueue_scripts', array( $this, 'maybe_enqueue' ) );
		add_filter( 'woocommerce_product_add_to_cart_url', array( $this, 'loop_url' ), 20, 2 );
		add_filter( 'woocommerce_product_add_to_cart_text', array( $this, 'loop_text' ), 20, 2 );
		add_filter( 'woocommerce_product_supports', array( $this, 'supports' ), 20, 3 );
		add_filter( 'woocommerce_quantity_input_args', array( $this, 'edit_quantity' ), 20, 2 );
	}

	/**
	 * Whether a product has input fields.
	 *
	 * @param \WC_Product|int $product Product.
	 * @return bool
	 */
	public static function has_options( $product ) {
		$id = is_object( $product ) ? $product->get_id() : (int) $product;
		foreach ( Sets_Repository::fields_for_product( $id ) as $f ) {
			if ( Field_Types::is_input( $f['type'] ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Echoes the form for the global product.
	 */
	public function output() {
		global $product;
		if ( ! $product instanceof \WC_Product || ! Settings_Store::get( 'enabled', true ) ) {
			return;
		}
		echo self::html_for( $product ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Renderer escapes every value.
	}

	/**
	 * Form HTML for a product (used by the hook, shortcode, block and page-builder widgets).
	 *
	 * @param \WC_Product $product Product.
	 * @return string
	 */
	public static function html_for( \WC_Product $product ) {
		$fields = Sets_Repository::fields_for_product( $product->get_id() );
		if ( ! $fields ) {
			return '';
		}
		self::enqueue();
		$fields = Localizer::fields( $fields );
		$values = array();
		$extra  = array();
		$edit   = self::edit_item( $product->get_id() );
		if ( $edit ) {
			$values        = $edit['dev3po']['values'];
			$extra['edit'] = $edit['key'];
		} elseif ( isset( $_POST['dev3po'] ) && is_array( $_POST['dev3po'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Refill after a failed add-to-cart (WooCommerce has no nonce here).
			$raw    = wp_unslash( $_POST['dev3po'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitised per field type in Values::parse.
			$values = Values::parse_all( $fields, $raw, \Dev3\AiProductOptions\Data\Uploads_Repository::resolver( $product->get_id() ) );
		}
		$base = (float) wc_get_price_to_display( $product );
		return ( new Renderer() )->render( $fields, $product->get_id(), $base, $values, $extra );
	}

	/**
	 * Cart item being edited (?dev3po_edit=<cart key>).
	 *
	 * @param int $product_id Product.
	 * @return array|null Cart item with `key`.
	 */
	public static function edit_item( $product_id ) {
		if ( empty( $_GET['dev3po_edit'] ) || ! function_exists( 'WC' ) || ! WC()->cart ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only prefill of the visitor's own cart.
			return null;
		}
		$key  = sanitize_key( wp_unslash( $_GET['dev3po_edit'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$item = WC()->cart->get_cart_item( $key );
		if ( ! $item || empty( $item['dev3po'] ) || (int) $item['product_id'] !== (int) $product_id ) {
			return null;
		}
		$item['key'] = $key;
		return $item;
	}

	/**
	 * Quantity of the edited cart item.
	 *
	 * @param array       $args    Args.
	 * @param \WC_Product $product Product.
	 * @return array
	 */
	public function edit_quantity( $args, $product ) {
		if ( is_product() && $product instanceof \WC_Product ) {
			$item = self::edit_item( $product->get_parent_id() ? $product->get_parent_id() : $product->get_id() );
			if ( $item ) {
				$args['input_value'] = (int) $item['quantity'];
			}
		}
		return $args;
	}

	/**
	 * Enqueue on product pages with options.
	 */
	public function maybe_enqueue() {
		if ( function_exists( 'is_product' ) && is_product() ) {
			$product = wc_get_product( get_queried_object_id() );
			if ( $product && self::has_options( $product ) ) {
				self::enqueue();
			}
		}
	}

	/**
	 * Enqueues storefront assets (also from shortcodes/blocks).
	 */
	public static function enqueue() {
		Assets::enqueue( 'storefront' );
		$accent = (string) Settings_Store::get( 'accent_color', '' );
		if ( $accent && wp_style_is( Assets::handle( 'storefront' ), 'enqueued' ) ) {
			wp_add_inline_style( Assets::handle( 'storefront' ), '.dev3po-form{--dev3po-accent:' . esc_attr( $accent ) . ';--dev3po-accent-strong:' . esc_attr( $accent ) . ';}' );
		}
	}

	/**
	 * Loop "Select options" link for products whose options must be chosen first.
	 *
	 * @param string      $url     URL.
	 * @param \WC_Product $product Product.
	 * @return string
	 */
	public function loop_url( $url, $product ) {
		if ( $product instanceof \WC_Product && $product->is_type( 'simple' ) && ! is_product() && self::needs_choice( $product ) ) {
			return get_permalink( $product->get_id() );
		}
		return $url;
	}

	/**
	 * Loop button text.
	 *
	 * @param string      $text    Text.
	 * @param \WC_Product $product Product.
	 * @return string
	 */
	public function loop_text( $text, $product ) {
		if ( $product instanceof \WC_Product && $product->is_type( 'simple' ) && $product->is_purchasable() && $product->is_in_stock() && self::needs_choice( $product ) ) {
			return (string) Settings_Store::get( 'loop_button_text', __( 'Select options', 'dev3-ai-product-options-for-woocommerce' ) );
		}
		return $text;
	}

	/**
	 * Disables AJAX add-to-cart for those products.
	 *
	 * @param bool        $supports Supports.
	 * @param string      $feature  Feature.
	 * @param \WC_Product $product  Product.
	 * @return bool
	 */
	public function supports( $supports, $feature, $product ) {
		if ( 'ajax_add_to_cart' === $feature && $product instanceof \WC_Product && self::needs_choice( $product ) ) {
			return false;
		}
		return $supports;
	}

	/**
	 * Whether the shopper must visit the product page first (required fields, or setting "always").
	 *
	 * @param \WC_Product $product Product.
	 * @return bool
	 */
	public static function needs_choice( \WC_Product $product ) {
		if ( ! Settings_Store::get( 'enabled', true ) ) {
			return false;
		}
		$mode = (string) Settings_Store::get( 'loop_button', 'required' );
		if ( 'never' === $mode ) {
			return false;
		}
		foreach ( Sets_Repository::fields_for_product( $product->get_id() ) as $f ) {
			if ( Field_Types::is_input( $f['type'] ) && ( 'always' === $mode || ! empty( $f['required'] ) ) ) {
				return true;
			}
		}
		return false;
	}
}
