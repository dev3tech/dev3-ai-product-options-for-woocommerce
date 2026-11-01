<?php
/**
 * Bootstrap and wiring.
 *
 * @package Dev3\AiProductOptions
 */

namespace Dev3\AiProductOptions;

use Dev3\AiProductOptions\Core3\Core\Assets;
use Dev3\AiProductOptions\Core3\Core\Privacy;
use Dev3\AiProductOptions\Core3\Core\Schema;
use Dev3\AiProductOptions\Core3\Core\Settings_Store;
use Dev3\AiProductOptions\Data\Sets_Repository;
use Dev3\AiProductOptions\Data\Uploads_Repository;

defined( 'ABSPATH' ) || exit;

/**
 * Singleton entry point.
 */
final class Plugin {

	const CRON_HOOK = 'dev3po_daily';

	/**
	 * Instance.
	 *
	 * @var Plugin|null
	 */
	private static $instance = null;

	/**
	 * Boots once.
	 *
	 * @return Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
			self::$instance->boot();
		}
		return self::$instance;
	}

	/**
	 * Activation.
	 */
	public static function activate() {
		self::register_schema();
		Schema::install( DEV3PO_DB_VERSION );
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON_HOOK );
		}
	}

	/**
	 * Deactivation.
	 */
	public static function deactivate() {
		wp_clear_scheduled_hook( self::CRON_HOOK );
	}

	/**
	 * Plugin tables / settings defaults are registered here so activation and runtime agree.
	 */
	public static function register_schema() {
		add_filter( 'dev3po_settings_defaults', array( Settings::class, 'defaults' ) );
		add_filter( 'dev3po_settings_sanitize', array( Settings::class, 'sanitize' ), 10, 3 );
		add_filter( 'dev3po_schema_sql', array( Uploads_Repository::class, 'schema' ), 10, 2 );
	}

	/**
	 * Declares HPOS and Cart/Checkout blocks compatibility.
	 */
	public static function declare_compatibility() {
		if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', DEV3PO_FILE, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', DEV3PO_FILE, true );
		}
	}

	/**
	 * Registers bundles (+ RTL stylesheets built by wp-scripts).
	 */
	public static function register_assets() {
		$entries = array( 'admin', 'product', 'storefront' );
		Assets::register( $entries );
		foreach ( $entries as $entry ) {
			wp_style_add_data( Assets::handle( $entry ), 'rtl', 'replace' );
		}
	}

	/**
	 * Daily housekeeping.
	 */
	public static function daily() {
		Schema::prune_usage();
		Uploads_Repository::prune( (int) Settings_Store::get( 'upload_retention', 14 ) );
	}

	/**
	 * Hooks.
	 */
	private function boot() {
		add_action( 'init', static fn() => load_plugin_textdomain( 'dev3-ai-product-options-for-woocommerce', false, dirname( plugin_basename( DEV3PO_FILE ) ) . '/languages' ), 0 );
		self::register_schema();
		// Shop managers run this plugin (WooCommerce capability), and anyone who edits products may use DEV3 AI drafts.
		add_filter( 'dev3po_manage_capability', static fn() => 'manage_woocommerce', 5 );
		add_filter( 'dev3po_capability', static fn() => 'edit_products', 5 );
		add_action( 'admin_init', static fn() => Schema::maybe_install( DEV3PO_DB_VERSION ) );
		add_action( self::CRON_HOOK, array( self::class, 'daily' ) );
		add_action( 'rest_api_init', array( $this, 'rest' ) );
		add_action( 'init', array( Sets_Repository::class, 'register_post_type' ) );
		Privacy::init();
		add_action( 'init', array( self::class, 'privacy' ) );
		add_action( 'init', array( self::class, 'register_assets' ) );

		if ( ! class_exists( 'WooCommerce' ) && ! function_exists( 'WC' ) ) {
			add_action( 'admin_notices', array( $this, 'missing_woocommerce' ) );
			return;
		}
		( new Frontend\Product_Form() )->register();
		( new Cart\Cart_Handler() )->register();
		( new Integrations\Builders() )->register();
		if ( is_admin() ) {
			( new Admin\Admin_Page() )->register();
			( new Admin\Product_Panel() )->register();
		}
	}

	/**
	 * Personal data: uploads linked to a customer's orders.
	 */
	public static function privacy() {
		Privacy::add_exporter(
			__( 'Product option uploads', 'dev3-ai-product-options-for-woocommerce' ),
			static function ( $email ) {
				$data = array();
				foreach ( self::uploads_for_email( $email ) as $row ) {
					$data[] = array(
						'group_id'    => 'dev3po-uploads',
						'group_label' => __( 'Product option uploads', 'dev3-ai-product-options-for-woocommerce' ),
						'item_id'     => 'dev3po-upload-' . $row['id'],
						'data'        => array(
							array(
								'name'  => __( 'File', 'dev3-ai-product-options-for-woocommerce' ),
								'value' => $row['file_name'],
							),
							array(
								'name'  => __( 'Order', 'dev3-ai-product-options-for-woocommerce' ),
								'value' => '#' . $row['order_id'],
							),
						),
					);
				}
				return array(
					'data' => $data,
					'done' => true,
				);
			}
		);
		Privacy::add_eraser(
			__( 'Product option uploads', 'dev3-ai-product-options-for-woocommerce' ),
			static function ( $email ) {
				$rows = self::uploads_for_email( $email );
				foreach ( $rows as $row ) {
					Uploads_Repository::delete( (int) $row['id'] );
				}
				return array(
					'items_removed'  => count( $rows ),
					'items_retained' => false,
					'messages'       => array(),
					'done'           => true,
				);
			}
		);
	}

	/**
	 * Upload rows for orders of an email address.
	 *
	 * @param string $email Email.
	 * @return array
	 */
	private static function uploads_for_email( $email ) {
		if ( ! function_exists( 'wc_get_orders' ) || ! is_email( $email ) ) {
			return array();
		}
		global $wpdb;
		$ids = wc_get_orders(
			array(
				'billing_email' => $email,
				'limit'         => 200,
				'return'        => 'ids',
			)
		);
		if ( ! $ids ) {
			return array();
		}
		$table        = Uploads_Repository::table();
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE order_id IN ({$placeholders})", array_map( 'intval', $ids ) ), ARRAY_A );
	}

	/**
	 * Notice when WooCommerce is missing.
	 */
	public function missing_woocommerce() {
		echo '<div class="notice notice-warning"><p>' . esc_html__( 'DEV3 AI Product Options & Add-Ons for WooCommerce needs WooCommerce to be installed and active.', 'dev3-ai-product-options-for-woocommerce' ) . '</p></div>';
	}

	/**
	 * REST routes.
	 */
	public function rest() {
		( new Core3\Rest\Core_Controller() )->register_routes();
		( new Rest\Rest_Controller() )->register_routes();
		( new Rest\Upload_Controller() )->register_routes();
	}
}
