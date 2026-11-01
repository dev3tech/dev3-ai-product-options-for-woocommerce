<?php
/**
 * Option sets: assignment, product modes, overrides, REST permissions + publish guard.
 *
 * @package Dev3\AiProductOptions
 */

use Dev3\AiProductOptions\Data\Sets_Repository;

class SetsTest extends Dev3po_Test_Case {

	private function make_set( array $rules, array $fields = null, $priority = 10 ) {
		return Sets_Repository::save(
			array(
				'title'    => 'Set',
				'status'   => 'publish',
				'priority' => $priority,
				'fields'   => $fields ? $fields : array( array( 'type' => 'checkbox', 'label' => 'Gift wrap', 'price_type' => 'flat', 'price' => 3 ) ),
				'rules'    => $rules,
			)
		);
	}

	public function test_assignment_by_category_parent_tag_product_and_exclusion() {
		$parent = self::factory()->term->create( array( 'taxonomy' => 'product_cat', 'name' => 'Clothing' ) );
		$child  = self::factory()->term->create( array( 'taxonomy' => 'product_cat', 'name' => 'Shirts', 'parent' => $parent ) );
		$tag    = self::factory()->term->create( array( 'taxonomy' => 'product_tag', 'name' => 'personalised' ) );
		$shirt  = $this->product( 20, array( 'category_ids' => array( $child ) ) );
		$mug    = $this->product( 10, array( 'tag_ids' => array( $tag ) ) );
		$other  = $this->product( 5 );
		$by_cat = $this->make_set( array( 'categories' => array( $parent ) ) );
		$by_tag = $this->make_set( array( 'tags' => array( $tag ) ) );
		$all    = $this->make_set( array( 'scope' => 'all', 'exclude_products' => array( $other->get_id() ) ), null, 50 );

		$ids = static fn( $pid ) => array_values( array_unique( wp_list_pluck( Sets_Repository::fields_for_product( $pid ), 'set' ) ) );
		$this->assertSame( array( $by_cat['id'], $all['id'] ), $ids( $shirt->get_id() ), 'parent category matches, priority order' );
		$this->assertSame( array( $by_tag['id'], $all['id'] ), $ids( $mug->get_id() ) );
		$this->assertSame( array(), $ids( $other->get_id() ), 'excluded from the all-products set' );
	}

	public function test_product_modes_and_price_overrides() {
		$product = $this->product();
		$set     = $this->make_set( array( 'scope' => 'all' ) );
		$set_field = $set['fields'][0]['id'];
		Sets_Repository::save_product_config(
			$product->get_id(),
			array(
				'fields'          => array( array( 'type' => 'text', 'label' => 'Name on mug', 'price_type' => 'per_char', 'price' => 0.5 ) ),
				'price_overrides' => array( $set_field => 1.25 ),
			)
		);
		$fields = Sets_Repository::fields_for_product( $product->get_id() );
		$this->assertCount( 2, $fields );
		$this->assertSame( 0, $fields[0]['set'], 'product fields come first' );
		$this->assertSame( 1.25, $fields[1]['price'], 'per-product price override' );

		Sets_Repository::save_product_config( $product->get_id(), array( 'mode' => 'override' ) );
		$this->assertCount( 1, Sets_Repository::fields_for_product( $product->get_id() ) );
		Sets_Repository::save_product_config( $product->get_id(), array( 'mode' => 'inherit', 'exclude_sets' => array( $set['id'] ) ) );
		$this->assertCount( 1, Sets_Repository::fields_for_product( $product->get_id() ) );
		Sets_Repository::save_product_config( $product->get_id(), array( 'mode' => 'none' ) );
		$this->assertCount( 0, Sets_Repository::fields_for_product( $product->get_id() ) );
	}

	public function test_draft_sets_do_not_apply() {
		$product = $this->product();
		Sets_Repository::save( array( 'title' => 'Draft', 'status' => 'draft', 'fields' => array( array( 'type' => 'text', 'label' => 'X' ) ), 'rules' => array( 'scope' => 'all' ) ) );
		$this->assertSame( array(), Sets_Repository::fields_for_product( $product->get_id() ) );
	}

	public function test_rest_permissions() {
		wp_set_current_user( 0 );
		$this->assertSame( 401, $this->rest( 'GET', '/sets' )->get_status() );
		$this->as_role( 'subscriber' );
		$this->assertSame( 403, $this->rest( 'GET', '/sets' )->get_status() );
		$this->assertSame( 403, $this->rest( 'POST', '/check', array( 'fields' => array() ) )->get_status() );
		$this->assertSame( 403, $this->rest( 'POST', '/ai/generate', array( 'title' => 'x' ) )->get_status() );
		$this->as_role( 'shop_manager' );
		$this->assertSame( 200, $this->rest( 'GET', '/sets' )->get_status() );
		$this->assertSame( 403, $this->rest( 'GET', '/sets', null, false )->get_status(), 'nonce required' );
	}

	public function test_rest_crud_and_publish_guard() {
		$this->as_role( 'administrator' );
		$bad = array(
			'title'  => 'Broken',
			'status' => 'publish',
			'fields' => array( array( 'id' => 'f_a', 'type' => 'select', 'label' => 'No choices' ) ),
			'rules'  => array( 'scope' => 'all' ),
		);
		$res = $this->rest( 'POST', '/sets', $bad );
		$this->assertSame( 409, $res->get_status() );
		$this->assertSame( 'dev3po_logic_errors', $res->get_data()['code'] );
		$this->assertNotEmpty( $res->get_data()['issues'] );
		$res = $this->rest( 'POST', '/sets', array_merge( $bad, array( 'force' => true ) ) );
		$this->assertSame( 201, $res->get_status() );
		$id = $res->get_data()['id'];
		$this->assertSame( 'publish', $res->get_data()['status'] );

		$res = $this->rest( 'POST', "/sets/{$id}/duplicate" );
		$this->assertSame( 'draft', $res->get_data()['status'] );
		$this->assertStringContainsString( '(copy)', $res->get_data()['title'] );

		$list = $this->rest( 'GET', '/sets' )->get_data();
		$this->assertCount( 2, $list );
		$this->assertSame( 1, $list[0]['errors'] );
		$this->assertSame( 'All products', $list[0]['summary'] );

		$imported = $this->rest( 'POST', '/sets/import', array( 'set' => array( 'title' => 'Imported', 'fields' => array( array( 'type' => 'text', 'label' => 'Name' ) ) ) ) );
		$this->assertSame( 201, $imported->get_status() );
		$this->assertSame( 400, $this->rest( 'POST', '/sets/import', array( 'set' => 'junk' ) )->get_status() );

		$this->assertSame( 200, $this->rest( 'DELETE', "/sets/{$id}" )->get_status() );
		$this->assertSame( 404, $this->rest( 'GET', "/sets/{$id}" )->get_status() );
	}

	public function test_product_routes_and_check_preview() {
		$this->as_role( 'shop_manager' );
		$product = $this->product( 15 );
		$res     = $this->rest( 'PUT', '/products/' . $product->get_id(), array( 'fields' => array( array( 'type' => 'text', 'label' => 'Engraving', 'price_type' => 'per_char', 'price' => 0.5 ) ) ) );
		$this->assertSame( 200, $res->get_status() );
		$this->assertCount( 1, $res->get_data()['fields'] );
		$this->assertSame( 404, $this->rest( 'GET', '/products/999999' )->get_status() );

		$check = $this->rest( 'POST', '/check', array( 'fields' => array( array( 'type' => 'number', 'label' => 'W', 'min' => 5, 'max' => 1 ) ) ) )->get_data();
		$this->assertContains( 'min_max', wp_list_pluck( $check['issues'], 'code' ) );

		$preview = $this->rest( 'POST', '/preview', array( 'fields' => $res->get_data()['fields'], 'price' => 15 ) )->get_data();
		$this->assertStringContainsString( 'dev3po-form', $preview['html'] );
		$this->assertStringContainsString( 'per character', $preview['html'] );

		$price = $this->rest( 'POST', '/price', array( 'fields' => array( array( 'id' => 'f_e', 'type' => 'text', 'label' => 'E', 'price_type' => 'per_char', 'price' => 0.5 ) ), 'values' => array( 'f_e' => 'ABCD' ), 'base' => 10, 'qty' => 2 ) )->get_data();
		$this->assertSame( 24.0, (float) $price['total'] );
	}
}
