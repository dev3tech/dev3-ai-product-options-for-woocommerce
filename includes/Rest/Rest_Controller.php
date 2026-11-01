<?php
/**
 * Admin REST routes under dev3po/v1 (option sets, product options, checker, preview, AI drafts, uploads).
 *
 * @package Dev3\AiProductOptions
 */

namespace Dev3\AiProductOptions\Rest;

use Dev3\AiProductOptions\Ai\Ai_Features;
use Dev3\AiProductOptions\Core3\Core\Settings_Store;
use Dev3\AiProductOptions\Core3\Rest\Base_Controller;
use Dev3\AiProductOptions\Data\Sets_Repository;
use Dev3\AiProductOptions\Data\Uploads_Repository;
use Dev3\AiProductOptions\Engine\Logic_Checker;
use Dev3\AiProductOptions\Engine\Pricing;
use Dev3\AiProductOptions\Engine\Values;
use Dev3\AiProductOptions\Fields\Field_Schema;
use Dev3\AiProductOptions\Fields\Field_Types;
use Dev3\AiProductOptions\Frontend\Renderer;

defined( 'ABSPATH' ) || exit;

/**
 * Routes for the React admin and the product editor.
 */
class Rest_Controller extends Base_Controller {

	/**
	 * Registers routes.
	 */
	public function register_routes() {
		$id = '(?P<id>\d+)';
		$this->route( '/bootstrap', 'GET', 'bootstrap', 'can_edit_products' );
		$this->route( '/stats', 'GET', 'stats', 'can_manage' );
		$this->route( '/sets', 'GET', 'list_sets', 'can_manage' );
		$this->route( '/sets', 'POST', 'create_set', 'can_manage' );
		$this->route( '/sets/import', 'POST', 'import_set', 'can_manage' );
		$this->route( '/sets/' . $id, 'GET', 'get_set', 'can_manage' );
		$this->route( '/sets/' . $id, 'PUT', 'update_set', 'can_manage' );
		$this->route( '/sets/' . $id, 'DELETE', 'delete_set', 'can_manage' );
		$this->route( '/sets/' . $id . '/duplicate', 'POST', 'duplicate_set', 'can_manage' );
		$this->route( '/products', 'GET', 'list_products', 'can_edit_products' );
		$this->route( '/products/' . $id, 'GET', 'get_product', 'can_edit_product' );
		$this->route( '/products/' . $id, 'PUT', 'update_product', 'can_edit_product' );
		$this->route( '/terms', 'GET', 'terms', 'can_edit_products' );
		$this->route( '/check', 'POST', 'check', 'can_edit_products' );
		$this->route( '/preview', 'POST', 'preview', 'can_edit_products' );
		$this->route( '/price', 'POST', 'price', 'can_edit_products' );
		$this->route( '/ai/generate', 'POST', 'ai_generate', 'can_generate' );
		$this->route( '/ai/review', 'POST', 'ai_review', 'can_generate' );
		$this->route( '/ai/formula', 'POST', 'ai_formula', 'can_generate' );
		$this->route( '/ai/labels', 'POST', 'ai_labels', 'can_generate' );
		$this->route( '/uploads', 'GET', 'list_uploads', 'can_manage' );
		$this->route( '/uploads/' . $id, 'DELETE', 'delete_upload', 'can_manage' );
	}

	/**
	 * Route helper.
	 *
	 * @param string $path       Path.
	 * @param string $method     Method.
	 * @param string $callback   Method name.
	 * @param string $permission Permission method.
	 */
	private function route( $path, $method, $callback, $permission ) {
		register_rest_route(
			self::NS,
			$path,
			array(
				'methods'             => $method,
				'callback'            => array( $this, $callback ),
				'permission_callback' => array( $this, $permission ),
			)
		);
	}

	/**
	 * Permission: users who edit products (shop managers, admins).
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return bool|\WP_Error
	 */
	public function can_edit_products( \WP_REST_Request $request ) {
		if ( ! $this->nonce_ok( $request ) ) {
			return new \WP_Error( 'dev3po_bad_nonce', __( 'Your session has expired. Reload the page and try again.', 'dev3-ai-product-options-for-woocommerce' ), array( 'status' => 403 ) );
		}
		return current_user_can( 'edit_products' ) ? true : new \WP_Error( 'dev3po_forbidden', __( 'You do not have permission to do that.', 'dev3-ai-product-options-for-woocommerce' ), array( 'status' => rest_authorization_required_code() ) );
	}

	/**
	 * Permission: edit this product.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return bool|\WP_Error
	 */
	public function can_edit_product( \WP_REST_Request $request ) {
		$ok = $this->can_edit_products( $request );
		if ( true !== $ok ) {
			return $ok;
		}
		$id = (int) $request['id'];
		if ( 'product' !== get_post_type( $id ) ) {
			return new \WP_Error( 'dev3po_not_found', __( 'Product not found.', 'dev3-ai-product-options-for-woocommerce' ), array( 'status' => 404 ) );
		}
		return current_user_can( 'edit_post', $id ) ? true : new \WP_Error( 'dev3po_forbidden', __( 'You do not have permission to do that.', 'dev3-ai-product-options-for-woocommerce' ), array( 'status' => rest_authorization_required_code() ) );
	}

	/**
	 * Error envelopes keep checker issues (publish guard).
	 *
	 * @param mixed $result Result.
	 * @param int   $status Status.
	 * @return \WP_REST_Response
	 */
	protected function respond( $result, $status = 200 ) {
		$response = parent::respond( $result, $status );
		if ( is_wp_error( $result ) ) {
			$data = $result->get_error_data();
			if ( is_array( $data ) && isset( $data['issues'] ) ) {
				$body           = $response->get_data();
				$body['issues'] = $data['issues'];
				$response->set_data( $body );
			}
		}
		return $response;
	}

	/**
	 * Fields from the request body, normalised.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array
	 */
	private function body_fields( \WP_REST_Request $request ) {
		$fields = $request->get_param( 'fields' );
		return Field_Schema::normalize_list( is_array( $fields ) ? $fields : array() );
	}

	/**
	 * GET /bootstrap: static data for the builder.
	 *
	 * @return \WP_REST_Response
	 */
	public function bootstrap() {
		$types = array();
		foreach ( Field_Types::labels() as $type => $label ) {
			$types[] = array(
				'type'        => $type,
				'label'       => $label,
				'group'       => Field_Types::TYPES[ $type ][0],
				'kind'        => Field_Types::kind( $type ),
				'price_types' => Field_Types::price_types( $type ),
			);
		}
		$locales = array( get_locale() );
		if ( function_exists( 'pll_languages_list' ) ) {
			$locales = array_merge( $locales, (array) pll_languages_list( array( 'fields' => 'locale' ) ) );
		}
		$wpml = apply_filters( 'wpml_active_languages', null ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML API.
		if ( is_array( $wpml ) ) {
			foreach ( $wpml as $lang ) {
				if ( ! empty( $lang['default_locale'] ) ) {
					$locales[] = $lang['default_locale'];
				}
			}
		}
		return $this->respond(
			array(
				'types'     => $types,
				'operators' => Field_Types::OPERATORS,
				'locale'    => get_locale(),
				'locales'   => array_values( array_unique( array_filter( $locales ) ) ),
				'currency'  => Renderer::currency(),
				'features'  => array_combine( Ai_Features::FEATURES, array_map( static fn( $k ) => (bool) Settings_Store::get( $k, true ), Ai_Features::FEATURES ) ),
				'canManage' => current_user_can( \Dev3\AiProductOptions\Core3\Core\Capabilities::manage() ),
			)
		);
	}

	/**
	 * GET /stats: dashboard numbers.
	 *
	 * @return \WP_REST_Response
	 */
	public function stats() {
		global $wpdb;
		$sets      = Sets_Repository::all();
		$published = array_filter( $sets, static fn( $s ) => 'publish' === $s['status'] );
		$products  = Sets_Repository::products( '', 1, 1, true );
		$meta_key  = \Dev3\AiProductOptions\Cart\Cart_Handler::ORDER_META;
		$items_tbl = $wpdb->prefix . 'woocommerce_order_itemmeta';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$order_items = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$items_tbl} WHERE meta_key = %s", $meta_key ) );
		$fields      = 0;
		$issues      = 0;
		$recent      = array();
		foreach ( $sets as $set ) {
			$fields  += count( array_filter( $set['fields'], static fn( $f ) => Field_Types::is_input( $f['type'] ) ) );
			$errors   = array_filter( Logic_Checker::check( Field_Schema::normalize_list( $set['fields'] ) ), static fn( $i ) => 'error' === $i['severity'] );
			$issues  += count( $errors );
			$recent[] = array(
				'id'       => $set['id'],
				'title'    => $set['title'],
				'status'   => $set['status'],
				'fields'   => count( $set['fields'] ),
				'errors'   => count( $errors ),
				'modified' => $set['modified'],
			);
		}
		usort( $recent, static fn( $a, $b ) => strcmp( $b['modified'], $a['modified'] ) );
		return $this->respond(
			array(
				'sets'        => count( $sets ),
				'published'   => count( $published ),
				'fields'      => $fields,
				'products'    => $products['total'],
				'order_items' => $order_items,
				'uploads'     => Uploads_Repository::counts(),
				'issues'      => $issues,
				'recent'      => array_slice( $recent, 0, 5 ),
			)
		);
	}

	/**
	 * GET /sets.
	 *
	 * @return \WP_REST_Response
	 */
	public function list_sets() {
		$out = array();
		foreach ( Sets_Repository::all() as $set ) {
			$issues = Logic_Checker::check( Field_Schema::normalize_list( $set['fields'] ) );
			$out[]  = array_merge(
				$set,
				array(
					'field_count' => count( array_filter( $set['fields'], static fn( $f ) => Field_Types::is_input( $f['type'] ) ) ),
					'errors'      => count( array_filter( $issues, static fn( $i ) => 'error' === $i['severity'] ) ),
					'summary'     => $this->rules_summary( $set['rules'] ),
				)
			);
		}
		return $this->respond( $out );
	}

	/**
	 * Human summary of assignment rules.
	 *
	 * @param array $rules Rules.
	 * @return string
	 */
	private function rules_summary( array $rules ) {
		if ( 'all' === $rules['scope'] ) {
			return __( 'All products', 'dev3-ai-product-options-for-woocommerce' );
		}
		$parts = array();
		if ( $rules['categories'] ) {
			$names   = array_filter( array_map( static fn( $id ) => html_entity_decode( (string) ( get_term( $id )->name ?? '' ), ENT_QUOTES, 'UTF-8' ), $rules['categories'] ) );
			$parts[] = implode( ', ', $names );
		}
		if ( $rules['tags'] ) {
			/* translators: %d: number of tags */
			$parts[] = sprintf( _n( '%d tag', '%d tags', count( $rules['tags'] ), 'dev3-ai-product-options-for-woocommerce' ), count( $rules['tags'] ) );
		}
		if ( $rules['products'] ) {
			/* translators: %d: number of products */
			$parts[] = sprintf( _n( '%d product', '%d products', count( $rules['products'] ), 'dev3-ai-product-options-for-woocommerce' ), count( $rules['products'] ) );
		}
		return $parts ? implode( ' · ', $parts ) : __( 'Not assigned', 'dev3-ai-product-options-for-woocommerce' );
	}

	/**
	 * POST /sets.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function create_set( \WP_REST_Request $request ) {
		$data = (array) $request->get_json_params();
		unset( $data['id'] );
		$result = $this->publish_guard( $data );
		return $this->respond( is_wp_error( $result ) ? $result : Sets_Repository::save( $result ), 201 );
	}

	/**
	 * PUT /sets/<id>.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function update_set( \WP_REST_Request $request ) {
		$data       = (array) $request->get_json_params();
		$data['id'] = (int) $request['id'];
		$result     = $this->publish_guard( $data );
		return $this->respond( is_wp_error( $result ) ? $result : Sets_Repository::save( $result ) );
	}

	/**
	 * Blocks publishing a set with checker errors unless `force` is set.
	 *
	 * @param array $data Data.
	 * @return array|\WP_Error
	 */
	private function publish_guard( array $data ) {
		if ( isset( $data['status'] ) && 'publish' === $data['status'] && empty( $data['force'] ) && isset( $data['fields'] ) ) {
			$issues = Logic_Checker::check( Field_Schema::normalize_list( $data['fields'] ) );
			if ( Logic_Checker::has_errors( $issues ) ) {
				return new \WP_Error(
					'dev3po_logic_errors',
					__( 'Fix the logic errors before publishing, or publish anyway.', 'dev3-ai-product-options-for-woocommerce' ),
					array(
						'status' => 409,
						'issues' => $issues,
					)
				);
			}
		}
		return $data;
	}

	/**
	 * GET /sets/<id>.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function get_set( \WP_REST_Request $request ) {
		$set = Sets_Repository::get( (int) $request['id'] );
		if ( ! $set ) {
			return $this->respond( new \WP_Error( 'dev3po_not_found', __( 'Option set not found.', 'dev3-ai-product-options-for-woocommerce' ), array( 'status' => 404 ) ) );
		}
		$set['rule_labels'] = $this->rule_labels( $set['rules'] );
		return $this->respond( $set );
	}

	/**
	 * Names for assigned ids (for chips in the editor).
	 *
	 * @param array $rules Rules.
	 * @return array
	 */
	private function rule_labels( array $rules ) {
		$out = array();
		foreach ( array_merge( $rules['categories'], $rules['tags'] ) as $term_id ) {
			$term = get_term( $term_id );
			if ( $term && ! is_wp_error( $term ) ) {
				$out[ 't' . $term_id ] = html_entity_decode( $term->name, ENT_QUOTES, 'UTF-8' );
			}
		}
		foreach ( array_merge( $rules['products'], $rules['exclude_products'] ) as $pid ) {
			$out[ 'p' . $pid ] = html_entity_decode( get_the_title( $pid ), ENT_QUOTES, 'UTF-8' );
		}
		return $out;
	}

	/**
	 * DELETE /sets/<id>.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function delete_set( \WP_REST_Request $request ) {
		$ok = Sets_Repository::delete( (int) $request['id'] );
		return $this->respond( $ok ? array( 'deleted' => true ) : new \WP_Error( 'dev3po_not_found', __( 'Option set not found.', 'dev3-ai-product-options-for-woocommerce' ), array( 'status' => 404 ) ) );
	}

	/**
	 * POST /sets/<id>/duplicate (new ids, saved as draft).
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function duplicate_set( \WP_REST_Request $request ) {
		$set = Sets_Repository::get( (int) $request['id'] );
		if ( ! $set ) {
			return $this->respond( new \WP_Error( 'dev3po_not_found', __( 'Option set not found.', 'dev3-ai-product-options-for-woocommerce' ), array( 'status' => 404 ) ) );
		}
		unset( $set['id'] );
		/* translators: %s: set title */
		$set['title']  = sprintf( __( '%s (copy)', 'dev3-ai-product-options-for-woocommerce' ), $set['title'] );
		$set['status'] = 'draft';
		return $this->respond( Sets_Repository::save( $set ), 201 );
	}

	/**
	 * POST /sets/import: { set: exported JSON }.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function import_set( \WP_REST_Request $request ) {
		$set = $request->get_param( 'set' );
		if ( ! is_array( $set ) || ! isset( $set['fields'] ) || ! is_array( $set['fields'] ) ) {
			return $this->respond( new \WP_Error( 'dev3po_bad_import', __( 'That file is not an option set export.', 'dev3-ai-product-options-for-woocommerce' ), array( 'status' => 400 ) ) );
		}
		return $this->respond(
			Sets_Repository::save(
				array(
					'title'    => $set['title'] ?? '',
					'status'   => 'draft',
					'priority' => $set['priority'] ?? 10,
					'fields'   => $set['fields'],
					'rules'    => Sets_Repository::default_rules(),
				)
			),
			201
		);
	}

	/**
	 * GET /products?search=&page=&only=1.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function list_products( \WP_REST_Request $request ) {
		$per = max( 1, min( 50, (int) ( $request['per_page'] ?? 20 ) ) );
		return $this->respond( Sets_Repository::products( sanitize_text_field( (string) $request['search'] ), max( 1, (int) $request['page'] ), $per, ! empty( $request['only'] ) ) );
	}

	/**
	 * GET /products/<id>: own config + effective fields + matching sets.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function get_product( \WP_REST_Request $request ) {
		return $this->respond( $this->product_payload( (int) $request['id'] ) );
	}

	/**
	 * Product payload.
	 *
	 * @param int $id Product id.
	 * @return array
	 */
	private function product_payload( $id ) {
		$product = wc_get_product( $id );
		return array_merge(
			Sets_Repository::product_config( $id ),
			array(
				'id'          => $id,
				'title'       => $product ? $product->get_name() : get_the_title( $id ),
				'price'       => $product ? (float) $product->get_price() : 0,
				'description' => $product ? mb_substr( wp_strip_all_tags( $product->get_short_description() ? $product->get_short_description() : $product->get_description() ), 0, 1200 ) : '',
				'sets'        => array_map(
					static fn( $s ) => array(
						'id'     => $s['id'],
						'title'  => $s['title'],
						'fields' => $s['fields'],
					),
					Sets_Repository::matching_sets( $id )
				),
				'view_url'    => get_permalink( $id ),
				'edit_url'    => get_edit_post_link( $id, 'raw' ),
			)
		);
	}

	/**
	 * PUT /products/<id>.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function update_product( \WP_REST_Request $request ) {
		$id = (int) $request['id'];
		Sets_Repository::save_product_config( $id, (array) $request->get_json_params() );
		return $this->respond( $this->product_payload( $id ) );
	}

	/**
	 * GET /terms?taxonomy=product_cat|product_tag|product&search=.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function terms( \WP_REST_Request $request ) {
		$tax    = (string) $request['taxonomy'];
		$search = sanitize_text_field( (string) $request['search'] );
		if ( 'product' === $tax ) {
			$posts = get_posts(
				array(
					'post_type'      => 'product',
					'post_status'    => array( 'publish', 'draft', 'private' ),
					's'              => $search,
					'posts_per_page' => 20,
				)
			);
			return $this->respond(
				array_map(
					static fn( $p ) => array(
						'id'    => $p->ID,
						'label' => html_entity_decode( get_the_title( $p ), ENT_QUOTES, 'UTF-8' ),
					),
					$posts
				)
			);
		}
		if ( ! in_array( $tax, array( 'product_cat', 'product_tag' ), true ) ) {
			$tax = 'product_cat';
		}
		$terms = get_terms(
			array(
				'taxonomy'   => $tax,
				'hide_empty' => false,
				'search'     => $search,
				'number'     => 50,
			)
		);
		return $this->respond(
			is_wp_error( $terms ) ? array() : array_map(
				static fn( $t ) => array(
					'id'    => $t->term_id,
					'label' => html_entity_decode( $t->name, ENT_QUOTES, 'UTF-8' ),
					'count' => $t->count,
				),
				$terms
			)
		);
	}

	/**
	 * POST /check: deterministic logic checker.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function check( \WP_REST_Request $request ) {
		$fields = $this->body_fields( $request );
		return $this->respond(
			array(
				'issues' => Logic_Checker::check( $fields ),
				'fields' => $fields,
			)
		);
	}

	/**
	 * POST /preview: storefront HTML for the builder preview.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function preview( \WP_REST_Request $request ) {
		$fields = $this->body_fields( $request );
		$base   = is_numeric( $request['price'] ) ? (float) $request['price'] : 20.0;
		$locale = preg_replace( '/[^A-Za-z_]/', '', (string) $request['locale'] );
		if ( '' !== $locale ) {
			$fields = \Dev3\AiProductOptions\Fields\Localizer::fields( $fields, $locale );
		}
		return $this->respond(
			array(
				'html' => ( new Renderer() )->render( $fields, 0, $base ),
				'css'  => DEV3PO_URL . 'build/storefront.css',
			)
		);
	}

	/**
	 * POST /price: server-side price for given values (parity checks, admin tools).
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function price( \WP_REST_Request $request ) {
		$fields = $this->body_fields( $request );
		$raw    = $request->get_param( 'values' );
		$values = Values::parse_all( $fields, is_array( $raw ) ? $raw : array() );
		$result = Pricing::calculate( $fields, $values, (float) $request['base'], max( 1, (int) $request['qty'] ) );
		unset( $result['visible'] );
		return $this->respond( $result );
	}

	/**
	 * POST /ai/generate { product_id?, title?, description?, price?, instructions? }.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function ai_generate( \WP_REST_Request $request ) {
		$ctx = array(
			'product_id'   => (int) $request['product_id'],
			'title'        => (string) $request['title'],
			'description'  => (string) $request['description'],
			'price'        => $request['price'],
			'instructions' => (string) $request['instructions'],
		);
		if ( $ctx['product_id'] && ! current_user_can( 'edit_post', $ctx['product_id'] ) ) {
			$ctx['product_id'] = 0;
		}
		return $this->respond( Ai_Features::generate_options( $ctx ) );
	}

	/**
	 * POST /ai/review { fields, product }.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function ai_review( \WP_REST_Request $request ) {
		return $this->respond( Ai_Features::review_logic( $this->body_fields( $request ), sanitize_text_field( (string) $request['product'] ) ) );
	}

	/**
	 * POST /ai/formula { rule, field_id, fields }.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function ai_formula( \WP_REST_Request $request ) {
		$fields = $this->body_fields( $request );
		$target = null;
		foreach ( $fields as $f ) {
			if ( $f['id'] === (string) $request['field_id'] ) {
				$target = $f;
			}
			foreach ( $f['subfields'] ?? array() as $sub ) {
				if ( $sub['id'] === (string) $request['field_id'] ) {
					$target = $sub;
				}
			}
		}
		if ( ! $target ) {
			$target = Field_Schema::normalize_field(
				array(
					'type'  => 'text',
					'label' => 'Field',
				)
			);
		}
		return $this->respond( Ai_Features::formula( (string) $request['rule'], $target, $fields ) );
	}

	/**
	 * POST /ai/labels { fields, mode, locale, tone, product }.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function ai_labels( \WP_REST_Request $request ) {
		return $this->respond( Ai_Features::labels( $this->body_fields( $request ), (string) $request['mode'], (string) $request['locale'], (string) $request['tone'], sanitize_text_field( (string) $request['product'] ) ) );
	}

	/**
	 * GET /uploads.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function list_uploads( \WP_REST_Request $request ) {
		return $this->respond( Uploads_Repository::list( max( 1, (int) $request['page'] ), 20, sanitize_key( (string) ( $request['status'] ?? 'all' ) ) ) );
	}

	/**
	 * DELETE /uploads/<id>.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function delete_upload( \WP_REST_Request $request ) {
		return $this->respond( array( 'deleted' => Uploads_Repository::delete( (int) $request['id'] ) ) );
	}
}
