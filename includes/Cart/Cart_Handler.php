<?php
/**
 * Cart, checkout (classic + blocks / Store API), order, email and admin display.
 *
 * @package Dev3\AiProductOptions
 */

namespace Dev3\AiProductOptions\Cart;

use Dev3\AiProductOptions\Core3\Core\Settings_Store;
use Dev3\AiProductOptions\Data\Sets_Repository;
use Dev3\AiProductOptions\Data\Uploads_Repository;
use Dev3\AiProductOptions\Engine\Conditions;
use Dev3\AiProductOptions\Engine\Pricing;
use Dev3\AiProductOptions\Engine\Values;
use Dev3\AiProductOptions\Fields\Field_Types;
use Dev3\AiProductOptions\Fields\Localizer;
use Dev3\AiProductOptions\Frontend\Renderer;

defined( 'ABSPATH' ) || exit;

/**
 * Cart item data shape: $item['dev3po'] = { values, fields (snapshot), lines, unit, once }.
 */
class Cart_Handler {

	const ORDER_META = '_dev3po_data';

	/**
	 * Cart key being replaced by an edit.
	 *
	 * @var string
	 */
	private $editing = '';

	/**
	 * Base prices captured before add-ons (by product object).
	 *
	 * @var array
	 */
	private $bases = array();

	/**
	 * Set while WooCommerce re-adds items from a previous order.
	 *
	 * @var bool
	 */
	private $order_again = false;

	/**
	 * Registers hooks.
	 */
	public function register() {
		add_filter( 'woocommerce_add_to_cart_validation', array( $this, 'validate' ), 20, 4 );
		add_filter( 'woocommerce_add_cart_item_data', array( $this, 'add_item_data' ), 20, 3 );
		add_action( 'woocommerce_add_to_cart', array( $this, 'after_add' ), 20, 1 );
		add_filter( 'woocommerce_add_to_cart_redirect', array( $this, 'edit_redirect' ), 20 );
		add_filter( 'woocommerce_get_cart_item_from_session', array( $this, 'from_session' ), 20, 2 );
		add_action( 'woocommerce_before_calculate_totals', array( $this, 'apply_prices' ), 20 );
		add_action( 'woocommerce_cart_calculate_fees', array( $this, 'add_fees' ), 20 );
		add_filter( 'woocommerce_get_item_data', array( $this, 'item_data' ), 20, 2 );
		add_filter( 'woocommerce_cart_item_permalink', array( $this, 'edit_permalink' ), 20, 3 );
		add_action( 'woocommerce_after_cart_item_name', array( $this, 'edit_link' ), 20, 2 );
		add_action( 'woocommerce_checkout_create_order_line_item', array( $this, 'order_item_meta' ), 20, 4 );
		add_filter( 'woocommerce_hidden_order_itemmeta', array( $this, 'hidden_meta' ) );
		add_filter( 'woocommerce_order_item_display_meta_value', array( $this, 'display_meta_value' ), 20, 3 );
		add_filter( 'woocommerce_order_again_cart_item_data', array( $this, 'order_again' ), 20, 2 );
		add_action( 'woocommerce_checkout_order_created', array( $this, 'attach_uploads' ) );
		add_action( 'woocommerce_store_api_checkout_order_processed', array( $this, 'attach_uploads' ) );
	}

	/**
	 * Fields for a product (localised) if the plugin is enabled.
	 *
	 * @param int $product_id Product.
	 * @return array
	 */
	private function fields( $product_id ) {
		if ( ! Settings_Store::get( 'enabled', true ) ) {
			return array();
		}
		return Localizer::fields( Sets_Repository::fields_for_product( $product_id ) );
	}

	/**
	 * Raw posted options.
	 *
	 * @return array
	 */
	private function posted() {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- WooCommerce add-to-cart has no nonce; every value is sanitised by type in Values::parse.
		return isset( $_POST['dev3po'] ) && is_array( $_POST['dev3po'] ) ? wp_unslash( $_POST['dev3po'] ) : array();
	}

	/**
	 * Parses + validates the posted options for a product.
	 *
	 * @param int   $product_id Product.
	 * @param array $raw        Raw input.
	 * @return array { fields, values, visible, errors }
	 */
	public function evaluate( $product_id, array $raw ) {
		$fields  = $this->fields( $product_id );
		$values  = Values::parse_all( $fields, $raw, Uploads_Repository::resolver( $product_id ) );
		$visible = Conditions::visibility( $fields, $values );
		return array(
			'fields'  => $fields,
			'values'  => $values,
			'visible' => $visible,
			'errors'  => Values::validate( $fields, $values, $visible ),
		);
	}

	/**
	 * Validates options on add to cart.
	 *
	 * @param bool $passed       Passed.
	 * @param int  $product_id   Product.
	 * @param int  $quantity     Quantity.
	 * @param int  $variation_id Variation.
	 * @return bool
	 */
	public function validate( $passed, $product_id, $quantity, $variation_id = 0 ) {
		unset( $quantity, $variation_id );
		if ( ! $passed ) {
			return $passed;
		}
		$fields = $this->fields( $product_id );
		if ( ! array_filter( $fields, static fn( $f ) => Field_Types::is_input( $f['type'] ) ) ) {
			return $passed;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- See posted().
		if ( ! isset( $_POST['dev3po_present'] ) && ! $this->is_order_again() ) {
			$required = array_filter( $fields, static fn( $f ) => ! empty( $f['required'] ) && empty( $f['conditions']['enabled'] ) );
			if ( $required ) {
				wc_add_notice( __( 'Please choose the product options first.', 'dev3-ai-product-options-for-woocommerce' ), 'error' );
				return false;
			}
			return $passed;
		}
		if ( $this->is_order_again() ) {
			return $passed;
		}
		$result = $this->evaluate( $product_id, $this->posted() );
		foreach ( $result['errors'] as $message ) {
			wc_add_notice( esc_html( $message ), 'error' );
		}
		return empty( $result['errors'] );
	}

	/**
	 * Whether WooCommerce is re-adding a previous order.
	 *
	 * @return bool
	 */
	private function is_order_again() {
		return $this->order_again || isset( $_GET['order_again'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	}

	/**
	 * Stores the options on the cart item.
	 *
	 * @param array $data         Cart item data.
	 * @param int   $product_id   Product.
	 * @param int   $variation_id Variation.
	 * @return array
	 */
	public function add_item_data( $data, $product_id, $variation_id ) {
		unset( $variation_id );
		if ( isset( $data['dev3po'] ) ) {
			return $data; // Order again: already restored.
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- See posted().
		if ( ! isset( $_POST['dev3po_present'] ) ) {
			return $data;
		}
		$result = $this->evaluate( $product_id, $this->posted() );
		$item   = self::snapshot( $result['fields'], $result['values'], $result['visible'] );
		if ( $item ) {
			$data['dev3po'] = $item;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- See posted().
		$edit = isset( $_POST['dev3po_edit'] ) ? sanitize_key( wp_unslash( $_POST['dev3po_edit'] ) ) : '';
		if ( $edit && WC()->cart && WC()->cart->get_cart_item( $edit ) ) {
			$this->editing = $edit;
		}
		return $data;
	}

	/**
	 * Builds the stored snapshot (visible values only).
	 *
	 * @param array $fields  Fields.
	 * @param array $values  Values.
	 * @param array $visible Visibility.
	 * @return array|null
	 */
	public static function snapshot( array $fields, array $values, array $visible ) {
		$kept = array();
		foreach ( $fields as $f ) {
			if ( ! Field_Types::is_input( $f['type'] ) || empty( $visible[ $f['id'] ] ) ) {
				continue;
			}
			$value = $values[ $f['id'] ] ?? Values::empty_value( $f );
			if ( ! Values::is_empty( $f, $value ) ) {
				$kept[ $f['id'] ] = $value;
			}
		}
		if ( ! $kept ) {
			return null;
		}
		// All fields are kept (not only the filled ones) so conditions re-evaluate exactly the same later.
		return array(
			'values' => $kept,
			'fields' => array_map(
				static function ( $f ) {
					unset( $f['i18n'] );
					return $f;
				},
				$fields
			),
			'hash'   => md5( wp_json_encode( $kept ) ),
		);
	}

	/**
	 * Removes the item that was edited.
	 *
	 * @param string $cart_key New item key.
	 */
	public function after_add( $cart_key ) {
		if ( $this->editing && $this->editing !== $cart_key && WC()->cart ) {
			WC()->cart->remove_cart_item( $this->editing );
		}
	}

	/**
	 * Back to the cart after editing.
	 *
	 * @param string $url URL.
	 * @return string
	 */
	public function edit_redirect( $url ) {
		return $this->editing ? wc_get_cart_url() : $url;
	}

	/**
	 * Restores from session.
	 *
	 * @param array $item   Item.
	 * @param array $values Session values.
	 * @return array
	 */
	public function from_session( $item, $values ) {
		if ( isset( $values['dev3po'] ) ) {
			$item['dev3po'] = $values['dev3po'];
		}
		return $item;
	}

	/**
	 * Price result for a cart item.
	 *
	 * @param array $item Cart item.
	 * @param float $base Base price.
	 * @return array
	 */
	public static function price_for( array $item, $base ) {
		$fields = $item['dev3po']['fields'] ?? array();
		$values = $item['dev3po']['values'] ?? array();
		return Pricing::calculate( $fields, $values, $base, (int) $item['quantity'] );
	}

	/**
	 * Adds per-item add-on prices to product prices (classic + Store API cart).
	 *
	 * @param \WC_Cart $cart Cart.
	 */
	public function apply_prices( $cart ) {
		if ( ( is_admin() && ! wp_doing_ajax() ) || ! $cart ) {
			return;
		}
		foreach ( $cart->get_cart() as $key => $item ) {
			if ( empty( $item['dev3po'] ) || empty( $item['data'] ) ) {
				continue;
			}
			$product = $item['data'];
			$oid     = spl_object_hash( $product );
			if ( ! isset( $this->bases[ $oid ] ) ) {
				$this->bases[ $oid ] = (float) $product->get_price( 'edit' );
			}
			$base   = $this->bases[ $oid ];
			$result = self::price_for( $item, $base );
			$product->set_price( max( 0, $base + $result['unit'] ) );
			$cart->cart_contents[ $key ]['dev3po']['unit'] = $result['unit'];
			$cart->cart_contents[ $key ]['dev3po']['once'] = $result['once'];
			$cart->cart_contents[ $key ]['dev3po']['base'] = $base;
		}
	}

	/**
	 * One-time fees (charge = once), one fee per cart line.
	 *
	 * @param \WC_Cart $cart Cart.
	 */
	public function add_fees( $cart ) {
		foreach ( $cart->get_cart() as $item ) {
			if ( empty( $item['dev3po'] ) || empty( $item['data'] ) ) {
				continue;
			}
			$base   = isset( $item['dev3po']['base'] ) ? (float) $item['dev3po']['base'] : (float) $item['data']->get_price( 'edit' );
			$result = self::price_for( $item, $base );
			foreach ( $result['lines'] as $line ) {
				if ( 'once' !== $line['charge'] ) {
					continue;
				}
				$cart->add_fee(
					sprintf( '%1$s: %2$s', $item['data']->get_name(), $line['label'] ),
					$line['amount'],
					$item['data']->is_taxable(),
					$item['data']->get_tax_class()
				);
			}
		}
	}

	/**
	 * Display lines for a stored snapshot.
	 *
	 * @param array $snapshot dev3po data.
	 * @param float $base     Base price (for percent hints).
	 * @param int   $qty      Quantity.
	 * @return array [ { key, value, price } ]
	 */
	public static function display_lines( array $snapshot, $base = 0.0, $qty = 1 ) {
		$out     = array();
		$fields  = $snapshot['fields'] ?? array();
		$values  = $snapshot['values'] ?? array();
		$visible = Conditions::visibility( $fields, $values );
		$prices  = array();
		foreach ( Pricing::calculate( $fields, $values, $base, $qty )['lines'] as $line ) {
			$prices[ $line['id'] ] = $line;
		}
		foreach ( $fields as $f ) {
			if ( empty( $visible[ $f['id'] ] ) || ! isset( $values[ $f['id'] ] ) ) {
				continue;
			}
			$shown = Values::display( $f, $values[ $f['id'] ] );
			if ( '' === $shown ) {
				continue;
			}
			$out[] = array(
				'id'    => $f['id'],
				'type'  => $f['type'],
				'key'   => '' !== $f['label'] ? $f['label'] : $f['key'],
				'value' => $shown,
				'price' => isset( $prices[ $f['id'] ] ) ? $prices[ $f['id'] ] : null,
			);
		}
		return $out;
	}

	/**
	 * Cart/checkout item data (classic templates and the Store API for block cart/checkout).
	 *
	 * @param array $data Item data.
	 * @param array $item Cart item.
	 * @return array
	 */
	public function item_data( $data, $item ) {
		if ( empty( $item['dev3po'] ) ) {
			return $data;
		}
		$base = isset( $item['dev3po']['base'] ) ? (float) $item['dev3po']['base'] : ( isset( $item['data'] ) ? (float) $item['data']->get_price( 'edit' ) : 0.0 );
		foreach ( self::display_lines( $item['dev3po'], $base, (int) ( $item['quantity'] ?? 1 ) ) as $line ) {
			$value = $line['value'];
			if ( $line['price'] && Settings_Store::get( 'cart_show_prices', true ) ) {
				$value .= ' (' . Renderer::signed( (float) $line['price']['amount'] ) . ( 'once' === $line['price']['charge'] ? ' ' . __( 'one-time', 'dev3-ai-product-options-for-woocommerce' ) : '' ) . ')';
			}
			$data[] = array(
				'key'     => $line['key'],
				'value'   => $value,
				'display' => esc_html( $value ),
			);
		}
		return $data;
	}

	/**
	 * Edit URL for a cart item.
	 *
	 * @param array  $item Item.
	 * @param string $key  Key.
	 * @return string
	 */
	public static function edit_url( array $item, $key ) {
		return add_query_arg( 'dev3po_edit', rawurlencode( $key ), get_permalink( (int) $item['product_id'] ) );
	}

	/**
	 * Product links in the cart (classic + block cart) open the edit view.
	 *
	 * @param string $url  URL.
	 * @param array  $item Item.
	 * @param string $key  Key.
	 * @return string
	 */
	public function edit_permalink( $url, $item, $key ) {
		if ( ! empty( $item['dev3po'] ) && $url && Settings_Store::get( 'cart_edit', true ) ) {
			return self::edit_url( $item, $key );
		}
		return $url;
	}

	/**
	 * "Edit options" link under the item name (classic cart).
	 *
	 * @param array  $item Item.
	 * @param string $key  Key.
	 */
	public function edit_link( $item, $key ) {
		if ( empty( $item['dev3po'] ) || ! Settings_Store::get( 'cart_edit', true ) ) {
			return;
		}
		echo '<div class="dev3po-cart-edit"><a href="' . esc_url( self::edit_url( $item, $key ) ) . '">' . esc_html__( 'Edit options', 'dev3-ai-product-options-for-woocommerce' ) . '</a></div>';
	}

	/**
	 * Saves options on the order item (classic and block checkout; HPOS-safe item meta).
	 *
	 * @param \WC_Order_Item_Product $item     Item.
	 * @param string                 $cart_key Key.
	 * @param array                  $values   Cart item.
	 * @param \WC_Order              $order    Order.
	 */
	public function order_item_meta( $item, $cart_key, $values, $order ) {
		unset( $cart_key, $order );
		if ( empty( $values['dev3po'] ) ) {
			return;
		}
		$base  = isset( $values['dev3po']['base'] ) ? (float) $values['dev3po']['base'] : 0.0;
		$lines = self::display_lines( $values['dev3po'], $base, (int) $values['quantity'] );
		foreach ( $lines as $line ) {
			$value = $line['value'];
			if ( $line['price'] ) {
				$value .= ' (' . Renderer::signed( (float) $line['price']['amount'] ) . ')';
			}
			$item->add_meta_data( $line['key'], $value );
		}
		$tokens = array();
		foreach ( $values['dev3po']['fields'] as $f ) {
			if ( 'file' === $f['type'] ) {
				foreach ( (array) ( $values['dev3po']['values'][ $f['id'] ] ?? array() ) as $file ) {
					$tokens[] = $file['token'];
				}
			}
		}
		$item->add_meta_data(
			self::ORDER_META,
			array(
				'values' => $values['dev3po']['values'],
				'fields' => $values['dev3po']['fields'],
				'unit'   => (float) ( $values['dev3po']['unit'] ?? 0 ),
				'once'   => (float) ( $values['dev3po']['once'] ?? 0 ),
				'base'   => $base,
				'files'  => $tokens,
			),
			true
		);
	}

	/**
	 * Links uploads to the order.
	 *
	 * @param \WC_Order $order Order.
	 */
	public function attach_uploads( $order ) {
		if ( ! $order instanceof \WC_Order ) {
			return;
		}
		foreach ( $order->get_items() as $item ) {
			$data = $item->get_meta( self::ORDER_META );
			if ( is_array( $data ) && ! empty( $data['files'] ) ) {
				Uploads_Repository::attach( $data['files'], $order->get_id() );
			}
		}
	}

	/**
	 * Hides the JSON meta in the admin.
	 *
	 * @param array $keys Keys.
	 * @return array
	 */
	public function hidden_meta( $keys ) {
		$keys[] = self::ORDER_META;
		return $keys;
	}

	/**
	 * In the order admin, file names become download links.
	 *
	 * @param string         $display Display value.
	 * @param \WC_Meta_Data  $meta    Meta.
	 * @param \WC_Order_Item $item    Item.
	 * @return string
	 */
	public function display_meta_value( $display, $meta, $item ) {
		if ( ! is_admin() || ! $item instanceof \WC_Order_Item_Product || ! current_user_can( 'edit_shop_orders' ) ) {
			return $display;
		}
		$data = $item->get_meta( self::ORDER_META );
		if ( ! is_array( $data ) || empty( $data['files'] ) ) {
			return $display;
		}
		foreach ( $data['files'] as $token ) {
			$row = Uploads_Repository::by_token( $token );
			if ( $row && false !== strpos( (string) $meta->value, $row['file_name'] ) ) {
				$display = str_replace( esc_html( $row['file_name'] ), '<a href="' . esc_url( Uploads_Repository::admin_url( $row ) ) . '" target="_blank" rel="noopener">' . esc_html( $row['file_name'] ) . '</a>', $display );
			}
		}
		return $display;
	}

	/**
	 * "Order again" restores options.
	 *
	 * @param array                  $data Cart item data.
	 * @param \WC_Order_Item_Product $item Order item.
	 * @return array
	 */
	public function order_again( $data, $item ) {
		$this->order_again = true;
		$saved             = $item->get_meta( self::ORDER_META );
		if ( is_array( $saved ) && ! empty( $saved['values'] ) ) {
			$data['dev3po'] = array(
				'values' => $saved['values'],
				'fields' => $saved['fields'],
				'hash'   => md5( wp_json_encode( $saved['values'] ) ),
			);
		}
		return $data;
	}
}
