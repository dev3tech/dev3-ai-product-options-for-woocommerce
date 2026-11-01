<?php
/**
 * Public upload endpoint for file fields (same-origin + rate limit; no nonce because of page caches).
 *
 * @package Dev3\AiProductOptions
 */

namespace Dev3\AiProductOptions\Rest;

use Dev3\AiProductOptions\Ai\Ai_Features;
use Dev3\AiProductOptions\Core3\Core\Settings_Store;
use Dev3\AiProductOptions\Core3\Rest\Base_Controller;
use Dev3\AiProductOptions\Data\Sets_Repository;
use Dev3\AiProductOptions\Data\Uploads_Repository;
use Dev3\AiProductOptions\Fields\Upload_Rules;

defined( 'ABSPATH' ) || exit;

/**
 * POST dev3po/v1/upload (multipart: file, product_id, field_id).
 */
class Upload_Controller extends Base_Controller {

	/**
	 * Registers routes.
	 */
	public function register_routes() {
		register_rest_route(
			self::NS,
			'/upload',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'upload' ),
				'permission_callback' => array( $this, 'permission' ),
			)
		);
	}

	/**
	 * Same-origin + per-IP rate limit.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return bool|\WP_Error
	 */
	public function permission( \WP_REST_Request $request ) {
		return $this->public_permission( $request, 'upload', (int) Settings_Store::get( 'upload_rate', 12 ) );
	}

	/**
	 * Error helper.
	 *
	 * @param string $code    Code.
	 * @param string $message Shopper message.
	 * @param int    $status  Status.
	 * @return \WP_REST_Response
	 */
	private function fail( $code, $message, $status = 422 ) {
		return $this->respond( new \WP_Error( $code, $message, array( 'status' => $status ) ) );
	}

	/**
	 * Finds a file field (top level or inside a repeater).
	 *
	 * @param int    $product_id Product.
	 * @param string $field_id   Field.
	 * @return array|null
	 */
	public static function find_field( $product_id, $field_id ) {
		foreach ( Sets_Repository::fields_for_product( $product_id ) as $f ) {
			if ( $f['id'] === $field_id && 'file' === $f['type'] ) {
				return $f;
			}
			foreach ( $f['subfields'] ?? array() as $sub ) {
				if ( $sub['id'] === $field_id && 'file' === $sub['type'] ) {
					return $sub;
				}
			}
		}
		return null;
	}

	/**
	 * Handles one file.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function upload( \WP_REST_Request $request ) {
		$product_id = (int) $request['product_id'];
		$field_id   = preg_replace( '/[^a-z0-9_]/', '', (string) $request['field_id'] );
		$product    = function_exists( 'wc_get_product' ) ? wc_get_product( $product_id ) : null;
		$field      = $product && 'publish' === get_post_status( $product_id ) ? self::find_field( $product_id, $field_id ) : null;
		if ( ! $field ) {
			return $this->fail( 'dev3po_bad_field', __( 'This upload is not available.', 'dev3-ai-product-options-for-woocommerce' ), 404 );
		}
		$files = $request->get_file_params();
		$file  = isset( $files['file'] ) && is_array( $files['file'] ) ? $files['file'] : null;
		if ( ! $file || ! empty( $file['error'] ) || empty( $file['tmp_name'] ) ) {
			return $this->fail( 'dev3po_no_file', __( 'The file could not be uploaded. Please try again.', 'dev3-ai-product-options-for-woocommerce' ), 400 );
		}
		$max_mb = min( (float) $field['max_size'], (float) Settings_Store::get( 'upload_max_mb', 8 ) );
		if ( (int) $file['size'] > $max_mb * 1048576 ) {
			/* translators: %s: size in MB */
			return $this->fail( 'dev3po_too_big', sprintf( __( 'The file is too large. The limit is %s MB.', 'dev3-ai-product-options-for-woocommerce' ), $max_mb ) );
		}
		$name    = sanitize_file_name( wp_basename( (string) $file['name'] ) );
		$ext     = strtolower( pathinfo( $name, PATHINFO_EXTENSION ) );
		$allowed = array_values( array_intersect( explode( ',', $field['accept'] ), Upload_Rules::allowed_extensions() ) );
		if ( ! in_array( $ext, $allowed, true ) ) {
			/* translators: %s: extensions */
			return $this->fail( 'dev3po_bad_type', sprintf( __( 'This file type is not allowed. Use: %s.', 'dev3-ai-product-options-for-woocommerce' ), strtoupper( implode( ', ', $allowed ) ) ) );
		}
		$check = wp_check_filetype_and_ext( $file['tmp_name'], $name, Upload_Rules::mimes_for( $allowed ) );
		if ( empty( $check['ext'] ) || empty( $check['type'] ) ) {
			return $this->fail( 'dev3po_bad_type', __( 'The file content does not match its type.', 'dev3-ai-product-options-for-woocommerce' ) );
		}
		$width  = 0;
		$height = 0;
		if ( in_array( $check['ext'], Upload_Rules::IMAGE_EXTENSIONS, true ) ) {
			$size = @getimagesize( $file['tmp_name'] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			if ( ! $size ) {
				return $this->fail( 'dev3po_bad_image', __( 'The image could not be read.', 'dev3-ai-product-options-for-woocommerce' ) );
			}
			$width  = (int) $size[0];
			$height = (int) $size[1];
			if ( ( $field['min_width'] && $width < $field['min_width'] ) || ( $field['min_height'] && $height < $field['min_height'] ) ) {
				return $this->fail(
					'dev3po_low_resolution',
					sprintf(
						/* translators: 1: min width, 2: min height, 3: width, 4: height */
						__( 'The image is too small: it needs at least %1$d×%2$d px, this one is %3$d×%4$d px.', 'dev3-ai-product-options-for-woocommerce' ),
						$field['min_width'],
						$field['min_height'],
						$width,
						$height
					)
				);
			}
		}

		$sub = gmdate( 'Y/m' );
		$dir = Uploads_Repository::base_dir() . '/' . $sub;
		wp_mkdir_p( $dir );
		$stored = wp_generate_password( 24, false, false ) . '.' . $check['ext'];
		$dest   = $dir . '/' . $stored;
		if ( ! $this->move( $file['tmp_name'], $dest ) ) {
			return $this->fail( 'dev3po_move_failed', __( 'The file could not be saved. Please try again.', 'dev3-ai-product-options-for-woocommerce' ), 500 );
		}
		$row = Uploads_Repository::insert(
			array(
				'session'    => Uploads_Repository::session( true ),
				'product_id' => $product_id,
				'field_id'   => $field['id'],
				'file_path'  => $sub . '/' . $stored,
				'file_name'  => mb_substr( $name, 0, 190 ),
				'mime'       => $check['type'],
				'size'       => (int) $file['size'],
				'width'      => $width,
				'height'     => $height,
				'hash'       => (string) sha1_file( $dest ),
			)
		);

		$vision = Ai_Features::vision_check( $dest, $check['type'], $field, $width, $height, $product->get_name() );
		Uploads_Repository::update(
			$row['id'],
			array(
				'check_status'  => $vision['status'],
				'check_message' => $vision['message'],
			)
		);
		if ( 'fail' === $vision['status'] ) {
			Uploads_Repository::delete( $row['id'] );
			return $this->fail( 'dev3po_vision_rejected', '' !== $vision['message'] ? $vision['message'] : __( 'This image does not fit this product. Please choose another one.', 'dev3-ai-product-options-for-woocommerce' ) );
		}
		return $this->respond(
			array(
				'token'  => $row['token'],
				'name'   => $row['file_name'],
				'size'   => $row['size'],
				'width'  => $width,
				'height' => $height,
				'check'  => array(
					'status'  => $vision['status'],
					'message' => $vision['message'],
				),
			),
			201
		);
	}

	/**
	 * Moves the uploaded file (copy for local files only in tests via filter).
	 *
	 * @param string $from Temp path.
	 * @param string $to   Destination.
	 * @return bool
	 */
	private function move( $from, $to ) {
		if ( is_uploaded_file( $from ) ) {
			return move_uploaded_file( $from, $to );
		}
		/**
		 * Allows non-HTTP uploads (unit tests, WP-CLI imports).
		 *
		 * @param bool $allow Allow.
		 */
		if ( apply_filters( 'dev3po_allow_local_upload', false ) ) {
			return copy( $from, $to );
		}
		return false;
	}
}
