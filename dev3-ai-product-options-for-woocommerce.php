<?php
/**
 * Plugin Name:       DEV3 AI Product Options & Add-Ons for WooCommerce
 * Plugin URI:        https://dev3.tech/item/dev3-ai-product-options-for-woocommerce
 * Description:       AI-assisted product options, add-ons, conditional logic and price formulas for WooCommerce.
 * Version:           1.0.1
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Requires Plugins:  woocommerce
 * WC requires at least: 8.0
 * WC tested up to:   10.3
 * Author:            DEV3 Technologies
 * Author URI:        https://dev3.tech
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       dev3-ai-product-options-for-woocommerce
 * Domain Path:       /languages
 *
 * @package Dev3\AiProductOptions
 */

defined( 'ABSPATH' ) || exit;

define( 'DEV3PO_VERSION', '1.0.1' );
define( 'DEV3PO_FILE', __FILE__ );
define( 'DEV3PO_PATH', plugin_dir_path( __FILE__ ) );
define( 'DEV3PO_URL', plugin_dir_url( __FILE__ ) );
define( 'DEV3PO_SLUG', 'dev3-ai-product-options-for-woocommerce' );
define( 'DEV3PO_NAME', 'DEV3 AI Product Options & Add-Ons for WooCommerce' );
define( 'DEV3PO_MIN_PHP', '7.4' );
define( 'DEV3PO_MIN_WP', '6.0' );
define( 'DEV3PO_DB_VERSION', '1.0.0' );

if ( version_compare( PHP_VERSION, DEV3PO_MIN_PHP, '<' ) ) {
	return;
}

spl_autoload_register(
	static function ( $class_name ) {
		$prefix = 'Dev3\AiProductOptions\\';
		if ( 0 !== strpos( $class_name, $prefix ) ) {
			return;
		}
		$file = DEV3PO_PATH . 'includes/' . str_replace( '\\', '/', substr( $class_name, strlen( $prefix ) ) ) . '.php';
		if ( is_readable( $file ) ) {
			require_once $file;
		}
	}
);

register_activation_hook( __FILE__, array( '\Dev3\AiProductOptions\Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( '\Dev3\AiProductOptions\Plugin', 'deactivate' ) );
add_action( 'plugins_loaded', array( '\Dev3\AiProductOptions\Plugin', 'instance' ) );
add_action( 'before_woocommerce_init', array( '\Dev3\AiProductOptions\Plugin', 'declare_compatibility' ) );
