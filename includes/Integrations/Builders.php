<?php
/**
 * Shortcode, Gutenberg block, Elementor widget and WPBakery element: "product options + add to cart".
 *
 * @package Dev3\AiProductOptions
 */

namespace Dev3\AiProductOptions\Integrations;

use Dev3\AiProductOptions\Core3\Core\Assets;

defined( 'ABSPATH' ) || exit;

/**
 * Registers every page-builder entry point; all of them render the same server markup.
 */
class Builders {

	/**
	 * Registers hooks.
	 */
	public function register() {
		add_shortcode( 'dev3po_product_options', array( self::class, 'shortcode' ) );
		add_action( 'init', array( $this, 'block' ), 20 );
		add_action( 'elementor/widgets/register', array( $this, 'elementor' ) );
		add_action( 'vc_before_init', array( $this, 'wpbakery' ) );
	}

	/**
	 * Renders the add-to-cart form (with options) for a product.
	 *
	 * @param int $product_id Product id (0 = current product).
	 * @return string
	 */
	public static function render_product( $product_id ) {
		if ( ! function_exists( 'wc_get_product' ) ) {
			return '';
		}
		$product_id = $product_id ? (int) $product_id : (int) get_the_ID();
		$product    = wc_get_product( $product_id );
		if ( ! $product || ! $product->is_visible() ) {
			return '';
		}
		global $post;
		$previous_product   = $GLOBALS['product'] ?? null;
		$previous_post      = $post;
		$GLOBALS['product'] = $product; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Restored below; WooCommerce templates read the global.
		$post               = get_post( $product_id ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		setup_postdata( $post );
		ob_start();
		echo '<div class="dev3po-embed woocommerce">';
		woocommerce_template_single_add_to_cart();
		echo '</div>';
		$html               = (string) ob_get_clean();
		$GLOBALS['product'] = $previous_product; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
		$post               = $previous_post; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		if ( $previous_post ) {
			setup_postdata( $previous_post );
		} else {
			wp_reset_postdata();
		}
		\Dev3\AiProductOptions\Frontend\Product_Form::enqueue();
		return $html;
	}

	/**
	 * [dev3po_product_options id="123"].
	 *
	 * @param array $atts Attributes.
	 * @return string
	 */
	public static function shortcode( $atts ) {
		$atts = shortcode_atts( array( 'id' => 0 ), $atts, 'dev3po_product_options' );
		return self::render_product( absint( $atts['id'] ) );
	}

	/**
	 * Gutenberg block (server-rendered; editor script in build/block.js).
	 */
	public function block() {
		if ( ! function_exists( 'register_block_type' ) ) {
			return;
		}
		Assets::register( array( 'block' ) );
		register_block_type(
			'dev3po/product-options',
			array(
				'api_version'     => 2,
				'title'           => __( 'Product options & add to cart', 'dev3-ai-product-options-for-woocommerce' ),
				'category'        => 'woocommerce',
				'icon'            => 'forms',
				'editor_script'   => Assets::handle( 'block' ),
				'attributes'      => array(
					'productId' => array(
						'type'    => 'number',
						'default' => 0,
					),
				),
				'supports'        => array(
					'align'   => array( 'wide', 'full' ),
					'spacing' => array( 'margin' => true ),
				),
				'render_callback' => static function ( $attrs ) {
					$html = self::render_product( absint( $attrs['productId'] ?? 0 ) );
					return '' !== $html ? '<div ' . get_block_wrapper_attributes() . '>' . $html . '</div>' : '';
				},
			)
		);
	}

	/**
	 * Elementor widget.
	 *
	 * @param object $manager Widgets manager.
	 */
	public function elementor( $manager ) {
		if ( class_exists( '\Elementor\Widget_Base' ) ) {
			require_once __DIR__ . '/Elementor_Widget.php';
			$manager->register( new Elementor_Widget() );
		}
	}

	/**
	 * WPBakery element mapped to the shortcode.
	 */
	public function wpbakery() {
		if ( ! function_exists( 'vc_map' ) ) {
			return;
		}
		vc_map(
			array(
				'name'        => __( 'Product options & add to cart', 'dev3-ai-product-options-for-woocommerce' ),
				'base'        => 'dev3po_product_options',
				'category'    => 'WooCommerce',
				'icon'        => 'icon-wpb-woocommerce',
				'description' => __( 'Option fields with live price and the add-to-cart button.', 'dev3-ai-product-options-for-woocommerce' ),
				'params'      => array(
					array(
						'type'        => 'textfield',
						'heading'     => __( 'Product ID', 'dev3-ai-product-options-for-woocommerce' ),
						'param_name'  => 'id',
						'description' => __( 'Leave empty on product templates to use the current product.', 'dev3-ai-product-options-for-woocommerce' ),
						'admin_label' => true,
					),
				),
			)
		);
	}
}
