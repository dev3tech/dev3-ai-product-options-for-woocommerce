<?php
/**
 * Cart, fees, item data (classic + Store API share it), order item meta, edit-from-cart and loop buttons.
 *
 * @package Dev3\AiProductOptions
 */

use Dev3\AiProductOptions\Cart\Cart_Handler;
use Dev3\AiProductOptions\Data\Sets_Repository;
use Dev3\AiProductOptions\Frontend\Product_Form;

class CartTest extends Dev3po_Test_Case {

	private $product;

	public function set_up() {
		parent::set_up();
		WC()->frontend_includes();
		WC()->session = new WC_Session_Handler();
		WC()->session->init();
		WC()->customer = new WC_Customer( 0, true );
		WC()->cart     = new WC_Cart();
		$this->product = $this->product( 20 );
		Sets_Repository::save_product_config(
			$this->product->get_id(),
			array(
				'fields' => array(
					array( 'id' => 'f_name', 'type' => 'text', 'label' => 'Name', 'required' => true, 'price_type' => 'per_char', 'price' => 0.5 ),
					array( 'id' => 'f_gift', 'type' => 'checkbox', 'label' => 'Gift box', 'price_type' => 'flat', 'price' => 4 ),
					array( 'id' => 'f_card', 'type' => 'textarea', 'label' => 'Card', 'conditions' => array( 'rules' => array( array( 'field' => 'f_gift', 'op' => 'checked' ) ) ) ),
					array( 'id' => 'f_setup', 'type' => 'hidden', 'label' => 'Setup fee', 'default' => 'yes', 'price_type' => 'flat', 'price' => 10, 'charge' => 'once' ),
				),
			)
		);
	}

	public function tear_down() {
		$_POST = array();
		$_GET  = array();
		WC()->cart->empty_cart();
		parent::tear_down();
	}

	private function post( array $values ) {
		$_POST = array(
			'dev3po_present' => '1',
			'dev3po'         => $values,
		);
	}

	public function test_validation_blocks_missing_required_and_skips_hidden() {
		$this->post( array( 'f_name' => '' ) );
		$this->assertFalse( apply_filters( 'woocommerce_add_to_cart_validation', true, $this->product->get_id(), 1 ) );
		wc_clear_notices();
		$this->post( array( 'f_name' => 'Ann' ) );
		$this->assertTrue( apply_filters( 'woocommerce_add_to_cart_validation', true, $this->product->get_id(), 1 ), 'hidden conditional field is not required' );
		$_POST = array();
		$this->assertFalse( apply_filters( 'woocommerce_add_to_cart_validation', true, $this->product->get_id(), 1 ), 'loop add-to-cart without options is refused' );
		wc_clear_notices();
	}

	public function test_prices_fees_and_item_data() {
		$this->post( array( 'f_name' => 'Anna Lee', 'f_gift' => '1', 'f_card' => 'Happy birthday' ) );
		$key = WC()->cart->add_to_cart( $this->product->get_id(), 2 );
		$this->assertNotEmpty( $key );
		WC()->cart->calculate_totals();
		$item = WC()->cart->get_cart_item( $key );
		// 20 + 7 chars × 0.5 + 4 = 27.5 per item; × 2 = 55; + 10 one-time setup fee.
		$this->assertEqualsWithDelta( 27.5, (float) $item['data']->get_price(), 0.001 );
		$this->assertEqualsWithDelta( 55.0, (float) WC()->cart->get_subtotal(), 0.001 );
		$this->assertEqualsWithDelta( 10.0, (float) WC()->cart->get_fee_total(), 0.001 );
		WC()->cart->calculate_totals();
		$this->assertEqualsWithDelta( 27.5, (float) WC()->cart->get_cart_item( $key )['data']->get_price(), 0.001, 'recalculating does not stack prices' );

		$data = apply_filters( 'woocommerce_get_item_data', array(), $item );
		$keys = wp_list_pluck( $data, 'key' );
		$this->assertSame( array( 'Name', 'Gift box', 'Card', 'Setup fee' ), $keys );
		$this->assertStringContainsString( 'Anna Lee', $data[0]['value'] );

		$url = apply_filters( 'woocommerce_cart_item_permalink', get_permalink( $this->product->get_id() ), $item, $key );
		$this->assertStringContainsString( 'dev3po_edit=' . $key, $url );
	}

	public function test_different_options_make_separate_lines() {
		$this->post( array( 'f_name' => 'A' ) );
		$a = WC()->cart->add_to_cart( $this->product->get_id(), 1 );
		$this->post( array( 'f_name' => 'B' ) );
		$b = WC()->cart->add_to_cart( $this->product->get_id(), 1 );
		$this->assertNotSame( $a, $b );
		$this->post( array( 'f_name' => 'A' ) );
		$this->assertSame( $a, WC()->cart->add_to_cart( $this->product->get_id(), 1 ), 'same options merge' );
	}

	public function test_edit_replaces_the_cart_line() {
		$this->post( array( 'f_name' => 'Old' ) );
		$old = WC()->cart->add_to_cart( $this->product->get_id(), 1 );
		$this->post( array( 'f_name' => 'New' ) );
		$_POST['dev3po_edit'] = $old;
		$new = WC()->cart->add_to_cart( $this->product->get_id(), 1 );
		$this->assertEmpty( WC()->cart->get_cart_item( $old ) );
		$this->assertSame( 'New', WC()->cart->get_cart_item( $new )['dev3po']['values']['f_name'] );
		$this->assertSame( wc_get_cart_url(), apply_filters( 'woocommerce_add_to_cart_redirect', 'x' ) );
		$_GET['dev3po_edit'] = $new;
		$edit = Product_Form::edit_item( $this->product->get_id() );
		$this->assertSame( $new, $edit['key'] );
	}

	public function test_order_item_meta_and_order_again() {
		$this->post( array( 'f_name' => 'Zoe', 'f_gift' => '1' ) );
		$key = WC()->cart->add_to_cart( $this->product->get_id(), 1 );
		WC()->cart->calculate_totals();
		$values = WC()->cart->get_cart_item( $key );
		$order  = wc_create_order();
		$item   = new WC_Order_Item_Product();
		$item->set_product( $this->product );
		do_action( 'woocommerce_checkout_create_order_line_item', $item, $key, $values, $order );
		$order->add_item( $item );
		$order->save();
		$saved = $order->get_item( $item->get_id() );
		$this->assertSame( 'Zoe (+$1.50)', $saved->get_meta( 'Name' ) );
		$this->assertSame( 'Yes (+$4.00)', $saved->get_meta( 'Gift box' ) );
		$data = $saved->get_meta( Cart_Handler::ORDER_META );
		$this->assertSame( 'Zoe', $data['values']['f_name'] );
		$this->assertContains( Cart_Handler::ORDER_META, apply_filters( 'woocommerce_hidden_order_itemmeta', array() ) );
		$again = apply_filters( 'woocommerce_order_again_cart_item_data', array(), $saved, $order );
		$this->assertSame( 'Zoe', $again['dev3po']['values']['f_name'] );
	}

	public function test_loop_button_links_to_product_when_required() {
		$this->assertFalse( $this->product->supports( 'ajax_add_to_cart' ) );
		$this->assertSame( 'Select options', $this->product->add_to_cart_text() );
		$plain = $this->product( 9 );
		$this->assertTrue( $plain->supports( 'ajax_add_to_cart' ) );
	}

	public function test_hpos_and_blocks_compatibility_declared() {
		$this->assertTrue( has_action( 'before_woocommerce_init' ) !== false );
		$compat = \Automattic\WooCommerce\Utilities\FeaturesUtil::get_compatible_plugins_for_feature( 'custom_order_tables' );
		$this->assertContains( plugin_basename( DEV3PO_FILE ), $compat['compatible'] );
	}
}
