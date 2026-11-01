<?php
/**
 * Test dependencies: WooCommerce + shared helpers.
 *
 * @package Dev3\AiProductOptions
 */

tests_add_filter(
	'dev3po_test_dependencies',
	static function ( $deps ) {
		$deps[] = file_exists( WP_PLUGIN_DIR . '/woocommerce/woocommerce.php' ) ? 'woocommerce/woocommerce.php' : 'woocommerce.latest-stable/woocommerce.php';
		return $deps;
	}
);
// The base test case extends WP_UnitTestCase, which exists only after the WP bootstrap: autoload it.
spl_autoload_register(
	static function ( $name ) {
		if ( 'Dev3po_Test_Case' === $name ) {
			require_once __DIR__ . '/support/class-dev3po-test-case.php';
		}
	}
);
