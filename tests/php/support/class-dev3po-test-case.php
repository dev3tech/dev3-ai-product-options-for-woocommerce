<?php
/**
 * Base test case: fake Gemini over pre_http_request, settings reset, field helpers.
 *
 * @package Dev3\AiProductOptions
 */

use Dev3\AiProductOptions\Core3\Api\Ai;
use Dev3\AiProductOptions\Core3\Api\Prompts;
use Dev3\AiProductOptions\Core3\Core\Cache;
use Dev3\AiProductOptions\Core3\Core\Schema;
use Dev3\AiProductOptions\Core3\Core\Settings_Store;
use Dev3\AiProductOptions\Core3\Data\Usage_Repository;
use Dev3\AiProductOptions\Data\Sets_Repository;
use Dev3\AiProductOptions\Fields\Field_Schema;

abstract class Dev3po_Test_Case extends WP_UnitTestCase {

	/**
	 * Captured Gemini requests.
	 *
	 * @var array
	 */
	protected $requests = array();

	/**
	 * Queued fake responses ([ status, body ] or callable); the last one repeats.
	 *
	 * @var array
	 */
	protected $responses = array();

	public function set_up() {
		parent::set_up();
		Schema::install( DEV3PO_DB_VERSION );
		update_option( Settings_Store::OPTION, array() );
		Settings_Store::flush();
		Usage_Repository::flush();
		Cache::reset_memo();
		Prompts::reset();
		Sets_Repository::flush();
		Ai::$client     = null;
		$GLOBALS['wp_rest_server'] = null;
		$this->requests  = array();
		$this->responses = array();
		add_filter( 'dev3po_retry_delay_ms', '__return_zero' );
		add_filter( 'pre_http_request', array( $this, 'fake_http' ), 10, 3 );
	}

	public function tear_down() {
		remove_filter( 'pre_http_request', array( $this, 'fake_http' ), 10 );
		remove_filter( 'dev3po_retry_delay_ms', '__return_zero' );
		parent::tear_down();
	}

	protected function with_key() {
		Settings_Store::update( array( 'api_key' => 'AIza-test-key-000000000000' ) );
		Settings_Store::flush();
	}

	public function fake_http( $pre, $args, $url ) {
		if ( false === strpos( $url, 'generativelanguage.googleapis.com' ) ) {
			return $pre;
		}
		$this->requests[] = array(
			'url'  => $url,
			'body' => json_decode( (string) $args['body'], true ),
		);
		$next = count( $this->responses ) > 1 ? array_shift( $this->responses ) : ( $this->responses ? $this->responses[0] : $this->json_response( array() ) );
		if ( is_callable( $next ) ) {
			$next = $next( end( $this->requests ) );
		}
		return array(
			'headers'  => array(),
			'body'     => wp_json_encode( $next[1] ),
			'response' => array(
				'code'    => $next[0],
				'message' => 'x',
			),
			'cookies'  => array(),
		);
	}

	protected function json_response( $data, $tokens = 120 ) {
		return array(
			200,
			array(
				'candidates'    => array(
					array(
						'content'      => array( 'parts' => array( array( 'text' => wp_json_encode( $data ) ) ) ),
						'finishReason' => 'STOP',
					),
				),
				'usageMetadata' => array(
					'promptTokenCount'     => $tokens,
					'candidatesTokenCount' => 40,
					'totalTokenCount'      => $tokens + 40,
				),
			),
		);
	}

	/**
	 * Normalised field from a short spec.
	 */
	protected function field( $type, array $extra = array() ) {
		return Field_Schema::normalize_field( array_merge( array( 'type' => $type, 'label' => ucfirst( $type ) ), $extra ) );
	}

	protected function product( $price = 20.0, array $extra = array() ) {
		$p = new WC_Product_Simple();
		$p->set_name( $extra['name'] ?? 'Test mug' );
		$p->set_regular_price( (string) $price );
		$p->set_status( 'publish' );
		if ( isset( $extra['category_ids'] ) ) {
			$p->set_category_ids( $extra['category_ids'] );
		}
		if ( isset( $extra['tag_ids'] ) ) {
			$p->set_tag_ids( $extra['tag_ids'] );
		}
		$p->save();
		return $p;
	}

	protected function rest( $method, $route, $body = null, $nonce = true ) {
		$req = new WP_REST_Request( $method, '/dev3po/v1' . $route );
		if ( $nonce ) {
			$req->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		}
		if ( null !== $body ) {
			$req->set_header( 'Content-Type', 'application/json' );
			$req->set_body( wp_json_encode( $body ) );
		}
		return rest_get_server()->dispatch( $req );
	}

	protected function as_role( $role ) {
		$id = self::factory()->user->create( array( 'role' => $role ) );
		wp_set_current_user( $id );
		return $id;
	}
}
