<?php
/**
 * Admin menu + React app mount.
 *
 * @package Dev3\AiProductOptions
 */

namespace Dev3\AiProductOptions\Admin;

use Dev3\AiProductOptions\Core3\Core\Assets;
use Dev3\AiProductOptions\Core3\Core\Capabilities;

defined( 'ABSPATH' ) || exit;

/**
 * Top-level menu page.
 */
class Admin_Page {

	/**
	 * Hook suffix.
	 *
	 * @var string
	 */
	private $hook = '';

	/**
	 * Registers hooks.
	 */
	public function register() {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( DEV3PO_FILE ), array( $this, 'links' ) );
		add_action( 'admin_post_dev3po_file', array( $this, 'download' ) );
	}

	/**
	 * Streams a customer upload to shop staff (nonce + capability).
	 */
	public function download() {
		$id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
		check_admin_referer( 'dev3po_file_' . $id );
		if ( ! current_user_can( 'edit_shop_orders' ) && ! current_user_can( Capabilities::manage() ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'dev3-ai-product-options-for-woocommerce' ), 403 );
		}
		$row  = \Dev3\AiProductOptions\Data\Uploads_Repository::find( $id );
		$path = $row ? \Dev3\AiProductOptions\Data\Uploads_Repository::path( $row ) : '';
		if ( ! $row || ! is_file( $path ) ) {
			wp_die( esc_html__( 'File not found.', 'dev3-ai-product-options-for-woocommerce' ), 404 );
		}
		$inline = 0 === strpos( $row['mime'], 'image/' ) || 'application/pdf' === $row['mime'];
		nocache_headers();
		header( 'Content-Type: ' . $row['mime'] );
		header( 'Content-Length: ' . filesize( $path ) );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'Content-Disposition: ' . ( $inline ? 'inline' : 'attachment' ) . '; filename="' . rawurlencode( $row['file_name'] ) . '"' );
		readfile( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
		exit;
	}

	/**
	 * Menu.
	 */
	public function menu() {
		$this->hook = (string) add_menu_page( DEV3PO_NAME, __( 'DEV3 AI Product Options', 'dev3-ai-product-options-for-woocommerce' ), Capabilities::manage(), DEV3PO_SLUG, array( $this, 'render' ), 'dashicons-forms', 56 );
	}

	/**
	 * Mount point.
	 */
	public function render() {
		// The h1 names the page for screen readers; wp-header-end keeps admin notices above the app.
		echo '<div class="wrap dev3po-wrap"><h1 class="screen-reader-text">' . esc_html( DEV3PO_NAME ) . '</h1><hr class="wp-header-end"><div id="dev3po-admin-root" class="dev3po-root dev3po-root--app"></div></div>';
	}

	/**
	 * Assets on our page only.
	 *
	 * @param string $hook Hook suffix.
	 */
	public function enqueue( $hook ) {
		if ( $hook === $this->hook ) {
			Assets::enqueue( 'admin' );
		}
	}

	/**
	 * Settings link.
	 *
	 * @param array $links Links.
	 * @return array
	 */
	public function links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( admin_url( 'admin.php?page=' . DEV3PO_SLUG ) ) . '">' . esc_html__( 'Settings', 'dev3-ai-product-options-for-woocommerce' ) . '</a>' );
		return $links;
	}
}
