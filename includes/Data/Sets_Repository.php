<?php
/**
 * Option sets (global, CPT dev3po_set) and product-level options (post meta).
 *
 * @package Dev3\AiProductOptions
 */

namespace Dev3\AiProductOptions\Data;

use Dev3\AiProductOptions\Fields\Field_Schema;

defined( 'ABSPATH' ) || exit;

/**
 * Storage + resolution of the fields that apply to a product.
 */
class Sets_Repository {

	const POST_TYPE   = 'dev3po_set';
	const META_FIELDS = '_dev3po_fields';
	const META_RULES  = '_dev3po_rules';
	const META_MODE   = '_dev3po_mode';
	const META_EXCL   = '_dev3po_exclude_sets';
	const META_PRICES = '_dev3po_price_overrides';

	/**
	 * Request memo of resolved fields.
	 *
	 * @var array
	 */
	private static $memo = array();

	/**
	 * Registers the CPT (no UI: managed in the React app).
	 */
	public static function register_post_type() {
		register_post_type(
			self::POST_TYPE,
			array(
				'labels'          => array(
					'name'          => __( 'Option sets', 'dev3-ai-product-options-for-woocommerce' ),
					'singular_name' => __( 'Option set', 'dev3-ai-product-options-for-woocommerce' ),
				),
				'public'          => false,
				'show_ui'         => false,
				'show_in_rest'    => false,
				'supports'        => array( 'title' ),
				'capability_type' => 'product',
				'map_meta_cap'    => true,
			)
		);
	}

	/**
	 * Clears the memo (after writes; tests).
	 */
	public static function flush() {
		self::$memo = array();
	}

	/**
	 * Default assignment rules.
	 *
	 * @return array
	 */
	public static function default_rules() {
		return array(
			'scope'            => 'rules',
			'products'         => array(),
			'categories'       => array(),
			'tags'             => array(),
			'exclude_products' => array(),
		);
	}

	/**
	 * Sanitises assignment rules.
	 *
	 * @param mixed $raw Raw.
	 * @return array
	 */
	public static function sanitize_rules( $raw ) {
		$raw   = is_array( $raw ) ? $raw : array();
		$ids   = static fn( $items ) => array_values( array_unique( array_filter( array_map( 'absint', is_array( $items ) ? $items : array() ) ) ) );
		$rules = array(
			'scope'            => isset( $raw['scope'] ) && 'all' === $raw['scope'] ? 'all' : 'rules',
			'products'         => $ids( $raw['products'] ?? array() ),
			'categories'       => $ids( $raw['categories'] ?? array() ),
			'tags'             => $ids( $raw['tags'] ?? array() ),
			'exclude_products' => $ids( $raw['exclude_products'] ?? array() ),
		);
		return $rules;
	}

	/**
	 * One set as an array.
	 *
	 * @param int|\WP_Post $post Post.
	 * @return array|null
	 */
	public static function get( $post ) {
		$post = get_post( $post );
		if ( ! $post || self::POST_TYPE !== $post->post_type ) {
			return null;
		}
		$fields = json_decode( (string) get_post_meta( $post->ID, self::META_FIELDS, true ), true );
		$rules  = get_post_meta( $post->ID, self::META_RULES, true );
		return array(
			'id'       => $post->ID,
			'title'    => $post->post_title,
			'status'   => 'publish' === $post->post_status ? 'publish' : 'draft',
			'priority' => (int) $post->menu_order,
			'fields'   => is_array( $fields ) ? $fields : array(),
			'rules'    => is_array( $rules ) ? array_merge( self::default_rules(), $rules ) : self::default_rules(),
			'modified' => mysql_to_rfc3339( $post->post_modified_gmt ),
		);
	}

	/**
	 * All sets.
	 *
	 * @param string $status any|publish.
	 * @return array
	 */
	public static function all( $status = 'any' ) {
		$posts = get_posts(
			array(
				'post_type'        => self::POST_TYPE,
				'post_status'      => 'publish' === $status ? 'publish' : array( 'publish', 'draft' ),
				'posts_per_page'   => 500, // phpcs:ignore WordPress.WP.PostsPerPage.posts_per_page_posts_per_page -- Option sets are few; one query per request (memoised).
				'orderby'          => array(
					'menu_order' => 'ASC',
					'ID'         => 'ASC',
				),
				'suppress_filters' => false,
			)
		);
		return array_values( array_filter( array_map( array( self::class, 'get' ), $posts ) ) );
	}

	/**
	 * Creates or updates a set.
	 *
	 * @param array $data { id?, title, status, priority, fields, rules }.
	 * @return array|\WP_Error
	 */
	public static function save( array $data ) {
		$id      = isset( $data['id'] ) ? absint( $data['id'] ) : 0;
		$postarr = array(
			'post_type'   => self::POST_TYPE,
			'post_title'  => mb_substr( sanitize_text_field( (string) ( $data['title'] ?? '' ) ), 0, 120 ),
			'post_status' => isset( $data['status'] ) && 'publish' === $data['status'] ? 'publish' : 'draft',
			'menu_order'  => max( -1000, min( 1000, (int) ( $data['priority'] ?? 10 ) ) ),
		);
		if ( '' === $postarr['post_title'] ) {
			$postarr['post_title'] = __( 'Untitled option set', 'dev3-ai-product-options-for-woocommerce' );
		}
		if ( $id ) {
			if ( ! self::get( $id ) ) {
				return new \WP_Error( 'dev3po_not_found', __( 'Option set not found.', 'dev3-ai-product-options-for-woocommerce' ), array( 'status' => 404 ) );
			}
			$postarr['ID'] = $id;
			$result        = wp_update_post( $postarr, true );
		} else {
			$result = wp_insert_post( $postarr, true );
		}
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		if ( array_key_exists( 'fields', $data ) ) {
			update_post_meta( $result, self::META_FIELDS, wp_slash( wp_json_encode( Field_Schema::normalize_list( $data['fields'] ) ) ) );
		}
		if ( array_key_exists( 'rules', $data ) ) {
			update_post_meta( $result, self::META_RULES, self::sanitize_rules( $data['rules'] ) );
		}
		self::flush();
		/**
		 * Fires after an option set is saved.
		 *
		 * @param int $set_id Set id.
		 */
		do_action( 'dev3po_set_saved', (int) $result );
		return self::get( $result );
	}

	/**
	 * Deletes a set.
	 *
	 * @param int $id Id.
	 * @return bool
	 */
	public static function delete( $id ) {
		if ( ! self::get( $id ) ) {
			return false;
		}
		self::flush();
		return (bool) wp_delete_post( $id, true );
	}

	/**
	 * Product-level options and overrides.
	 *
	 * @param int $product_id Product.
	 * @return array { fields, mode, exclude_sets, price_overrides }
	 */
	public static function product_config( $product_id ) {
		$fields = json_decode( (string) get_post_meta( $product_id, self::META_FIELDS, true ), true );
		$mode   = (string) get_post_meta( $product_id, self::META_MODE, true );
		$excl   = get_post_meta( $product_id, self::META_EXCL, true );
		$prices = get_post_meta( $product_id, self::META_PRICES, true );
		return array(
			'fields'          => is_array( $fields ) ? $fields : array(),
			'mode'            => in_array( $mode, array( 'inherit', 'override', 'none' ), true ) ? $mode : 'inherit',
			'exclude_sets'    => is_array( $excl ) ? array_map( 'absint', $excl ) : array(),
			'price_overrides' => is_array( $prices ) ? $prices : array(),
		);
	}

	/**
	 * Saves product-level config.
	 *
	 * @param int   $product_id Product.
	 * @param array $data       Partial config.
	 * @return array
	 */
	public static function save_product_config( $product_id, array $data ) {
		if ( array_key_exists( 'fields', $data ) ) {
			$fields = Field_Schema::normalize_list( $data['fields'] );
			if ( $fields ) {
				update_post_meta( $product_id, self::META_FIELDS, wp_slash( wp_json_encode( $fields ) ) );
			} else {
				delete_post_meta( $product_id, self::META_FIELDS );
			}
		}
		if ( isset( $data['mode'] ) ) {
			update_post_meta( $product_id, self::META_MODE, in_array( $data['mode'], array( 'inherit', 'override', 'none' ), true ) ? $data['mode'] : 'inherit' );
		}
		if ( isset( $data['exclude_sets'] ) ) {
			update_post_meta( $product_id, self::META_EXCL, array_values( array_filter( array_map( 'absint', (array) $data['exclude_sets'] ) ) ) );
		}
		if ( isset( $data['price_overrides'] ) && is_array( $data['price_overrides'] ) ) {
			$clean = array();
			foreach ( array_slice( $data['price_overrides'], 0, 200, true ) as $ref => $price ) {
				if ( preg_match( '/^[a-z][a-z0-9_]{1,30}$/', (string) $ref ) && is_numeric( $price ) ) {
					$clean[ $ref ] = round( (float) $price, 4 );
				}
			}
			update_post_meta( $product_id, self::META_PRICES, $clean );
		}
		self::flush();
		return self::product_config( $product_id );
	}

	/**
	 * Global sets that match a product.
	 *
	 * @param int $product_id Product (parent id for variations).
	 * @return array Sets.
	 */
	public static function matching_sets( $product_id ) {
		$cats = wp_get_post_terms( $product_id, 'product_cat', array( 'fields' => 'ids' ) );
		$tags = wp_get_post_terms( $product_id, 'product_tag', array( 'fields' => 'ids' ) );
		$cats = is_wp_error( $cats ) ? array() : array_map( 'intval', $cats );
		$tags = is_wp_error( $tags ) ? array() : array_map( 'intval', $tags );
		// Parent categories count too: a set on "Clothing" applies to "Clothing > Shirts".
		foreach ( $cats as $cat ) {
			$cats = array_merge( $cats, array_map( 'intval', get_ancestors( $cat, 'product_cat', 'taxonomy' ) ) );
		}
		$out = array();
		foreach ( self::all( 'publish' ) as $set ) {
			$r = $set['rules'];
			if ( in_array( (int) $product_id, $r['exclude_products'], true ) ) {
				continue;
			}
			$match = 'all' === $r['scope']
				|| in_array( (int) $product_id, $r['products'], true )
				|| array_intersect( $cats, $r['categories'] )
				|| array_intersect( $tags, $r['tags'] );
			if ( $match ) {
				$out[] = $set;
			}
		}
		return $out;
	}

	/**
	 * Fields that apply to a product: product fields first, then matching global sets by priority,
	 * minus excluded sets, with per-product price overrides applied.
	 *
	 * @param int $product_id Product or variation id.
	 * @return array Fields (each with `set` = set id or 0 for product fields).
	 */
	public static function fields_for_product( $product_id ) {
		$product_id = (int) $product_id;
		$parent     = (int) wp_get_post_parent_id( $product_id );
		if ( $parent && 'product_variation' === get_post_type( $product_id ) ) {
			$product_id = $parent;
		}
		if ( isset( self::$memo[ $product_id ] ) ) {
			return self::$memo[ $product_id ];
		}
		$config = self::product_config( $product_id );
		$fields = array();
		if ( 'none' !== $config['mode'] ) {
			foreach ( $config['fields'] as $f ) {
				$f['set'] = 0;
				$fields[] = $f;
			}
			if ( 'inherit' === $config['mode'] ) {
				foreach ( self::matching_sets( $product_id ) as $set ) {
					if ( in_array( (int) $set['id'], $config['exclude_sets'], true ) ) {
						continue;
					}
					foreach ( $set['fields'] as $f ) {
						$f['set'] = (int) $set['id'];
						$fields[] = $f;
					}
				}
			}
		}
		$fields = self::apply_overrides( $fields, $config['price_overrides'] );
		/**
		 * Filters the option fields shown for a product.
		 *
		 * @param array $fields     Fields.
		 * @param int   $product_id Product id.
		 */
		$fields                    = (array) apply_filters( 'dev3po_product_fields', $fields, $product_id );
		self::$memo[ $product_id ] = $fields;
		return $fields;
	}

	/**
	 * Applies { field or option id => price } overrides.
	 *
	 * @param array $fields    Fields.
	 * @param array $overrides Overrides.
	 * @return array
	 */
	private static function apply_overrides( array $fields, array $overrides ) {
		if ( ! $overrides ) {
			return $fields;
		}
		foreach ( $fields as &$f ) {
			if ( isset( $overrides[ $f['id'] ] ) ) {
				$f['price'] = (float) $overrides[ $f['id'] ];
			}
			foreach ( $f['options'] ?? array() as $i => $opt ) {
				if ( isset( $overrides[ $opt['id'] ] ) ) {
					$f['options'][ $i ]['price'] = (float) $overrides[ $opt['id'] ];
				}
			}
		}
		unset( $f );
		return $fields;
	}

	/**
	 * Products that have their own fields (for the admin list).
	 *
	 * @param string $search Search.
	 * @param int    $page   Page.
	 * @param int    $per    Per page.
	 * @param bool   $only   Only products with own options.
	 * @return array { items, total }
	 */
	public static function products( $search = '', $page = 1, $per = 20, $only = false ) {
		$args = array(
			'post_type'      => 'product',
			'post_status'    => array( 'publish', 'draft', 'private', 'pending' ),
			'posts_per_page' => $per,
			'paged'          => max( 1, $page ),
			's'              => $search,
			'orderby'        => 'title',
			'order'          => 'ASC',
		);
		if ( $only ) {
			$args['meta_query'] = array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				array(
					'key'     => self::META_FIELDS,
					'compare' => 'EXISTS',
				),
			);
		}
		$query = new \WP_Query( $args );
		$items = array();
		foreach ( $query->posts as $post ) {
			$config  = self::product_config( $post->ID );
			$all     = self::fields_for_product( $post->ID );
			$product = function_exists( 'wc_get_product' ) ? wc_get_product( $post->ID ) : null;
			$items[] = array(
				'id'         => $post->ID,
				'title'      => get_the_title( $post ),
				'status'     => $post->post_status,
				'type'       => $product ? $product->get_type() : 'simple',
				'price'      => $product ? (float) $product->get_price() : 0,
				'image'      => (string) get_the_post_thumbnail_url( $post, 'thumbnail' ),
				'own_fields' => count( $config['fields'] ),
				'total'      => count( array_filter( $all, static fn( $f ) => \Dev3\AiProductOptions\Fields\Field_Types::is_input( $f['type'] ) ) ),
				'sets'       => array_values( array_unique( array_filter( wp_list_pluck( $all, 'set' ) ) ) ),
				'mode'       => $config['mode'],
				'edit_url'   => get_edit_post_link( $post->ID, 'raw' ),
				'view_url'   => get_permalink( $post ),
			);
		}
		return array(
			'items' => $items,
			'total' => (int) $query->found_posts,
		);
	}
}
