<?php
/**
 * Public upload endpoint (type/size/resolution rules, session binding, DEV3 AI vision) and storefront markup.
 *
 * @package Dev3\AiProductOptions
 */

use Dev3\AiProductOptions\Core3\Core\Settings_Store;
use Dev3\AiProductOptions\Data\Sets_Repository;
use Dev3\AiProductOptions\Data\Uploads_Repository;
use Dev3\AiProductOptions\Fields\Field_Schema;
use Dev3\AiProductOptions\Fields\Localizer;
use Dev3\AiProductOptions\Frontend\Renderer;

class UploadAndRenderTest extends Dev3po_Test_Case {

	private $product;

	public function set_up() {
		parent::set_up();
		add_filter( 'dev3po_allow_local_upload', '__return_true' );
		$this->product = $this->product( 30, array( 'name' => 'Pet portrait' ) );
		Sets_Repository::save_product_config(
			$this->product->get_id(),
			array(
				'fields' => array(
					array( 'id' => 'f_photo', 'type' => 'file', 'label' => 'Your photo', 'accept' => 'jpg,png', 'max_size' => 2, 'min_width' => 400, 'min_height' => 300, 'vision' => true, 'vision_rule' => 'One pet, clear face' ),
				),
			)
		);
		wp_set_current_user( 0 );
		$_COOKIE[ Uploads_Repository::COOKIE ] = 'testvisitor123';
	}

	public function tear_down() {
		remove_filter( 'dev3po_allow_local_upload', '__return_true' );
		unset( $_COOKIE[ Uploads_Repository::COOKIE ] );
		parent::tear_down();
	}

	private function png( $w, $h, $name = 'pet.png' ) {
		$path = wp_tempnam( $name );
		$img  = imagecreatetruecolor( $w, $h );
		imagefill( $img, 0, 0, imagecolorallocate( $img, 200, 120, 40 ) );
		imagepng( $img, $path );
		imagedestroy( $img );
		return array(
			'name'     => $name,
			'type'     => 'image/png',
			'tmp_name' => $path,
			'error'    => 0,
			'size'     => filesize( $path ),
		);
	}

	private function upload( array $file, $field = 'f_photo', array $headers = array() ) {
		$req = new WP_REST_Request( 'POST', '/dev3po/v1/upload' );
		$req->set_param( 'product_id', $this->product->get_id() );
		$req->set_param( 'field_id', $field );
		$req->set_file_params( array( 'file' => $file ) );
		foreach ( $headers as $k => $v ) {
			$req->set_header( $k, $v );
		}
		return rest_get_server()->dispatch( $req );
	}

	public function test_accepts_a_valid_image_without_ai() {
		$res = $this->upload( $this->png( 800, 600 ) );
		$this->assertSame( 201, $res->get_status() );
		$data = $res->get_data();
		$this->assertSame( 32, strlen( $data['token'] ) );
		$this->assertSame( 800, $data['width'] );
		$this->assertSame( 'unchecked', $data['check']['status'], 'no key: vision falls back' );
		$row = Uploads_Repository::by_token( $data['token'] );
		$this->assertFileExists( Uploads_Repository::path( $row ) );
		$this->assertFileExists( Uploads_Repository::base_dir() . '/.htaccess' );
		$field    = Sets_Repository::fields_for_product( $this->product->get_id() )[0];
		$resolver = Uploads_Repository::resolver( $this->product->get_id() );
		$this->assertSame( 'pet.png', $resolver( $data['token'], $field )['name'] );
		$_COOKIE[ Uploads_Repository::COOKIE ] = 'someone-else';
		$resolver = Uploads_Repository::resolver( $this->product->get_id() );
		$this->assertNull( $resolver( $data['token'], $field ), 'tokens are bound to the uploader session' );
	}

	public function test_rejects_bad_type_size_resolution_origin_and_field() {
		$this->assertSame( 'dev3po_low_resolution', $this->upload( $this->png( 200, 100 ) )->get_data()['code'] );
		$fake = $this->png( 800, 600, 'shell.php' );
		$this->assertSame( 'dev3po_bad_type', $this->upload( $fake )->get_data()['code'] );
		$spoof         = $this->png( 800, 600 );
		$spoof['name'] = 'photo.gif';
		$this->assertSame( 'dev3po_bad_type', $this->upload( $spoof )->get_data()['code'] );
		$big         = $this->png( 800, 600 );
		$big['size'] = 3 * 1048576;
		$this->assertSame( 'dev3po_too_big', $this->upload( $big )->get_data()['code'] );
		$this->assertSame( 404, $this->upload( $this->png( 800, 600 ), 'f_nope' )->get_status() );
		$this->assertSame( 403, $this->upload( $this->png( 800, 600 ), 'f_photo', array( 'Origin' => 'https://evil.example' ) )->get_status() );
	}

	public function test_vision_check_warns_or_blocks() {
		$this->with_key();
		$this->responses = array( $this->json_response( array( 'fit' => false, 'score' => 20, 'issues' => array( 'No pet visible' ), 'message' => 'We could not see a pet. Please upload a photo of your pet.' ) ) );
		$res             = $this->upload( $this->png( 800, 600 ) );
		$this->assertSame( 201, $res->get_status() );
		$this->assertSame( 'warn', $res->get_data()['check']['status'] );
		$this->assertStringContainsString( 'pet', $res->get_data()['check']['message'] );
		$this->assertArrayHasKey( 'inline_data', $this->requests[0]['body']['contents'][0]['parts'][0] );

		Settings_Store::update( array( 'vision_mode' => 'block' ) );
		Settings_Store::flush();
		$this->responses = array( $this->json_response( array( 'fit' => false, 'score' => 10, 'message' => 'Not a pet.' ) ) );
		$res             = $this->upload( $this->png( 801, 600 ) );
		$this->assertSame( 422, $res->get_status() );
		$this->assertSame( 'dev3po_vision_rejected', $res->get_data()['code'] );
		$this->assertSame( 1, Uploads_Repository::counts()['total'], 'rejected file is deleted' );

		Settings_Store::update( array( 'public_ai' => false ) );
		Settings_Store::flush();
		$before = count( $this->requests );
		$this->assertSame( 'unchecked', $this->upload( $this->png( 802, 600 ) )->get_data()['check']['status'], 'storefront AI kill switch' );
		$this->assertSame( $before, count( $this->requests ) );
	}

	public function test_renderer_markup_is_escaped_accessible_and_translated() {
		$fields = Field_Schema::normalize_list(
			array(
				array( 'id' => 'f_name', 'type' => 'text', 'label' => 'Name "quoted"', 'required' => true, 'tooltip' => 'Shown on the lid', 'price_type' => 'per_char', 'price' => 0.5, 'maxlength' => 12, 'i18n' => array( 'de_DE' => array( 'label' => 'Name auf dem Deckel' ) ) ),
				array( 'id' => 'f_col', 'type' => 'color_swatch', 'label' => 'Colour', 'options' => array( array( 'label' => 'Red', 'color' => '#ff0000', 'price' => 2 ) ) ),
				array( 'id' => 'f_s1', 'type' => 'step', 'label' => 'Extras' ),
				array( 'id' => 'f_rows', 'type' => 'repeater', 'label' => 'Names', 'subfields' => array( array( 'type' => 'text', 'label' => 'Name' ) ) ),
			)
		);
		$html = ( new Renderer() )->render( $fields, $this->product->get_id(), 30 );
		$this->assertStringContainsString( 'name="dev3po[f_name]"', $html );
		$this->assertStringContainsString( 'aria-required="true"', $html );
		$this->assertStringContainsString( 'role="tooltip"', $html );
		$this->assertStringContainsString( 'maxlength="12"', $html );
		$this->assertStringContainsString( 'dev3po-form--steps', $html );
		$this->assertStringContainsString( '<template class="dev3po-repeater__tpl">', $html );
		$this->assertStringContainsString( '--dev3po-chip:#ff0000', $html );
		$this->assertStringNotContainsString( 'Name "quoted"', $html, 'quotes are escaped' );
		$this->assertStringNotContainsString( 'vision_rule', $html );
		$de = ( new Renderer() )->render( Localizer::fields( $fields, 'de_DE' ), $this->product->get_id(), 30 );
		$this->assertStringContainsString( 'Name auf dem Deckel', $de );
	}
}
