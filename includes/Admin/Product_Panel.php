<?php
/**
 * "DEV3 AI Options" tab in the WooCommerce product data box (React builder, saved with the product).
 *
 * @package Dev3\AiProductOptions
 */

namespace Dev3\AiProductOptions\Admin;

use Dev3\AiProductOptions\Core3\Core\Assets;
use Dev3\AiProductOptions\Data\Sets_Repository;

defined( 'ABSPATH' ) || exit;

/**
 * Product editor integration.
 */
class Product_Panel {

	/**
	 * Registers hooks.
	 */
	public function register() {
		add_filter( 'woocommerce_product_data_tabs', array( $this, 'tab' ) );
		add_action( 'woocommerce_product_data_panels', array( $this, 'panel' ) );
		add_action( 'woocommerce_process_product_meta', array( $this, 'save' ), 20 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	/**
	 * Adds the tab.
	 *
	 * @param array $tabs Tabs.
	 * @return array
	 */
	public function tab( $tabs ) {
		$tabs['dev3po'] = array(
			'label'    => __( 'DEV3 AI Options', 'dev3-ai-product-options-for-woocommerce' ),
			'target'   => 'dev3po_product_data',
			'class'    => array(),
			'priority' => 65,
		);
		return $tabs;
	}

	/**
	 * Panel markup: React mounts into #dev3po-product-root; the hidden input carries the JSON on save.
	 */
	public function panel() {
		global $post;
		$config = Sets_Repository::product_config( $post->ID );
		echo '<div id="dev3po_product_data" class="panel woocommerce_options_panel hidden">';
		wp_nonce_field( 'dev3po_product_save', 'dev3po_product_nonce' );
		echo '<input type="hidden" id="dev3po-product-config" name="dev3po_product_config" value="' . esc_attr( wp_json_encode( $config ) ) . '">';
		echo '<div id="dev3po-product-root" class="dev3po-root" data-product="' . esc_attr( (string) $post->ID ) . '"><p style="padding:12px">' . esc_html__( 'Loading options…', 'dev3-ai-product-options-for-woocommerce' ) . '</p></div>';
		echo '</div>';
	}

	/**
	 * Saves the config posted by the panel.
	 *
	 * @param int $post_id Product id.
	 */
	public function save( $post_id ) {
		if ( ! isset( $_POST['dev3po_product_nonce'], $_POST['dev3po_product_config'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['dev3po_product_nonce'] ) ), 'dev3po_product_save' ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- JSON; every key is sanitised by Field_Schema / save_product_config.
		$config = json_decode( wp_unslash( $_POST['dev3po_product_config'] ), true );
		if ( is_array( $config ) ) {
			Sets_Repository::save_product_config( $post_id, $config );
		}
	}

	/**
	 * Assets on the product editor.
	 *
	 * @param string $hook Hook.
	 */
	public function enqueue( $hook ) {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( in_array( $hook, array( 'post.php', 'post-new.php' ), true ) && $screen && 'product' === $screen->post_type ) {
			Assets::enqueue( 'product' );
		}
	}
}
