<?php
/**
 * Elementor widget (loaded only when Elementor is active).
 *
 * @package Dev3\AiProductOptions
 */

namespace Dev3\AiProductOptions\Integrations;

defined( 'ABSPATH' ) || exit;

/**
 * "Product options & add to cart" widget.
 */
class Elementor_Widget extends \Elementor\Widget_Base {

	/**
	 * Name.
	 *
	 * @return string
	 */
	public function get_name() {
		return 'dev3po_product_options';
	}

	/**
	 * Title.
	 *
	 * @return string
	 */
	public function get_title() {
		return __( 'Product options & add to cart', 'dev3-ai-product-options-for-woocommerce' );
	}

	/**
	 * Icon.
	 *
	 * @return string
	 */
	public function get_icon() {
		return 'eicon-form-horizontal';
	}

	/**
	 * Categories.
	 *
	 * @return array
	 */
	public function get_categories() {
		return array( 'woocommerce-elements', 'general' );
	}

	/**
	 * Keywords.
	 *
	 * @return array
	 */
	public function get_keywords() {
		return array( 'options', 'add-ons', 'product', 'woocommerce', 'dev3' );
	}

	/**
	 * Controls.
	 */
	protected function register_controls() {
		$this->start_controls_section( 'content', array( 'label' => __( 'Product', 'dev3-ai-product-options-for-woocommerce' ) ) );
		$this->add_control(
			'product_id',
			array(
				'label'       => __( 'Product ID', 'dev3-ai-product-options-for-woocommerce' ),
				'type'        => \Elementor\Controls_Manager::NUMBER,
				'default'     => 0,
				'description' => __( '0 uses the current product (single product templates).', 'dev3-ai-product-options-for-woocommerce' ),
			)
		);
		$this->end_controls_section();
	}

	/**
	 * Render.
	 */
	protected function render() {
		$settings = $this->get_settings_for_display();
		echo Builders::render_product( absint( $settings['product_id'] ?? 0 ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- WooCommerce template + escaped renderer output.
	}
}
