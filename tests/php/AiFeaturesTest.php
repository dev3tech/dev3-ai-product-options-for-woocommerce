<?php
/**
 * DEV3 AI features with Gemini mocked over pre_http_request: generate, review, formula, labels, toggles.
 *
 * @package Dev3\AiProductOptions
 */

use Dev3\AiProductOptions\Ai\Ai_Features;
use Dev3\AiProductOptions\Ai\Prompt_Library;
use Dev3\AiProductOptions\Core3\Core\Settings_Store;
use Dev3\AiProductOptions\Fields\Field_Schema;

class AiFeaturesTest extends Dev3po_Test_Case {

	private function generated() {
		return array(
			'fields' => array(
				array( 'key' => 'engraving', 'type' => 'text', 'label' => 'Engraving text', 'required' => false, 'price_type' => 'per_char', 'price' => 0.5, 'maxlength' => 20 ),
				array( 'key' => 'finish', 'type' => 'radio', 'label' => 'Finish', 'required' => true, 'price_type' => 'none', 'options' => array( array( 'label' => 'Matte', 'price' => 0 ), array( 'label' => 'Gloss', 'price' => 3 ) ) ),
				array( 'key' => 'gift', 'type' => 'checkbox', 'label' => 'Gift box', 'required' => false, 'price_type' => 'flat', 'price' => 4 ),
				array( 'key' => 'gift_note', 'type' => 'textarea', 'label' => 'Gift note', 'required' => false, 'price_type' => 'none', 'condition' => array( 'action' => 'show', 'match' => 'all', 'rules' => array( array( 'field' => 'gift', 'operator' => 'checked', 'value' => '' ) ) ) ),
				array( 'key' => 'gloss_note', 'type' => 'text', 'label' => 'Gloss care', 'required' => false, 'price_type' => 'none', 'condition' => array( 'rules' => array( array( 'field' => 'finish', 'operator' => 'is', 'value' => 'Gloss' ) ) ) ),
			),
			'notes'  => 'Engraving is priced per letter.',
		);
	}

	public function test_generate_options_returns_a_valid_reviewed_draft() {
		$this->with_key();
		$product         = $this->product( 25, array( 'name' => 'Walnut cutting board' ) );
		$this->responses = array( $this->json_response( $this->generated() ) );
		$result          = Ai_Features::generate_options( array( 'product_id' => $product->get_id(), 'instructions' => 'engraving' ) );
		$this->assertIsArray( $result );
		$this->assertSame( array(), $result['schema_errors'], 'generated set validates against the schema' );
		$this->assertCount( 5, $result['fields'] );
		$this->assertSame( $result['fields'][2]['id'], $result['fields'][3]['conditions']['rules'][0]['field'], 'condition key resolved to field id' );
		$this->assertSame( $result['fields'][1]['options'][1]['id'], $result['fields'][4]['conditions']['rules'][0]['value'], 'option label resolved to option id' );
		$this->assertArrayHasKey( 'issues', $result );
		$this->assertGreaterThan( 25, $result['sample'] );
		$body = $this->requests[0]['body'];
		$this->assertStringContainsString( 'Walnut cutting board', $body['contents'][0]['parts'][0]['text'] );
		$this->assertSame( 'application/json', $body['generationConfig']['responseMimeType'] );
		$this->assertArrayHasKey( 'responseSchema', $body['generationConfig'] );
		$this->assertSame( 2048, $body['generationConfig']['thinkingConfig']['thinkingBudget'] );
		$this->assertSame( array(), Field_Schema::validate_generated( $this->generated() ) );
		$this->assertNotEmpty( Field_Schema::validate_generated( array( 'fields' => array( array( 'type' => 'script', 'label' => '' ) ) ) ) );
	}

	public function test_generate_schema_has_max_lengths_on_strings() {
		$walk = static function ( $node ) use ( &$walk ) {
			$missing = 0;
			if ( isset( $node['type'] ) && 'STRING' === $node['type'] && ! isset( $node['enum'] ) && ! isset( $node['maxLength'] ) ) {
				++$missing;
			}
			foreach ( array( 'properties', 'items' ) as $k ) {
				if ( isset( $node[ $k ] ) ) {
					foreach ( 'items' === $k ? array( $node[ $k ] ) : $node[ $k ] as $child ) {
						$missing += $walk( $child );
					}
				}
			}
			return $missing;
		};
		$this->assertSame( 0, $walk( Prompt_Library::generate_schema() ) );
		$this->assertSame( 0, $walk( Prompt_Library::labels_schema() ) );
	}

	public function test_ai_off_and_feature_toggles_leave_plugin_usable() {
		$this->with_key();
		Settings_Store::update( array( 'ai_generate' => false ) );
		Settings_Store::flush();
		$off = Ai_Features::generate_options( array( 'title' => 'Mug' ) );
		$this->assertWPError( $off );
		$this->assertSame( 'dev3po_feature_off', $off->get_error_code() );
		Settings_Store::update( array( 'ai_generate' => true, 'ai_enabled' => false ) );
		Settings_Store::flush();
		$this->assertSame( 'dev3po_ai_disabled', Ai_Features::generate_options( array( 'title' => 'Mug' ) )->get_error_code() );
		$this->assertCount( 0, $this->requests, 'no HTTP call when AI is off' );
	}

	public function test_review_logic_merges_checker_and_ai_issues() {
		$this->with_key();
		$fields          = Field_Schema::normalize_list( array( array( 'type' => 'select', 'key' => 'size', 'label' => 'Size' ), array( 'type' => 'text', 'key' => 'note', 'label' => 'Note' ) ) );
		$this->responses = array( $this->json_response( array( 'summary' => 'One risk.', 'issues' => array( array( 'severity' => 'warning', 'field' => 'note', 'message' => 'Note has no help text.', 'suggestion' => 'Add an example.' ) ) ) ) );
		$result          = Ai_Features::review_logic( $fields, 'Mug' );
		$sources         = wp_list_pluck( $result['issues'], 'source' );
		$this->assertContains( 'checker', $sources );
		$this->assertContains( 'ai', $sources );
		$ai = array_values( array_filter( $result['issues'], static fn( $i ) => 'ai' === $i['source'] ) )[0];
		$this->assertSame( $fields[1]['id'], $ai['field'] );
		$this->assertContains( 'no_options', wp_list_pluck( $result['issues'], 'code' ) );
	}

	public function test_formula_helper_retries_until_valid_and_explains() {
		$this->with_key();
		$field           = Field_Schema::normalize_field( array( 'type' => 'text', 'label' => 'Engraving', 'price_type' => 'formula' ) );
		$this->responses = array(
			$this->json_response( array( 'formula' => 'max(0, {letters} - 10) * 2', 'explanation' => 'x' ) ),
			$this->json_response( array( 'formula' => 'max(0, {len} - 10) * 2', 'explanation' => '2 per letter after the first 10.' ) ),
		);
		$result = Ai_Features::formula( 'add 2 per letter over 10 letters', $field, array( $field ) );
		$this->assertSame( 'max(0, {len} - 10) * 2', $result['formula'] );
		$this->assertCount( 2, $this->requests );
		$this->assertStringContainsString( 'rejected', $this->requests[1]['body']['contents'][0]['parts'][0]['text'] );
		$prices = wp_list_pluck( $result['examples'], 'price' );
		$this->assertSame( array( 0.0, 0.0, 0.0, 4.0, 20.0 ), array_map( 'floatval', $prices ) );
	}

	public function test_labels_draft_keeps_ids_and_drops_unknown() {
		$this->with_key();
		$fields          = Field_Schema::normalize_list( array( array( 'type' => 'radio', 'label' => 'Colour', 'options' => array( 'Red', 'Blue' ) ) ) );
		$opt             = $fields[0]['options'][0]['id'];
		$this->responses = array(
			$this->json_response(
				array(
					'fields' => array(
						array( 'id' => $fields[0]['id'], 'label' => 'Farbe', 'help' => 'Wähle eine Farbe.', 'options' => array( array( 'id' => $opt, 'label' => 'Rot' ), array( 'id' => 'o_fake', 'label' => 'X' ) ) ),
						array( 'id' => 'f_unknown', 'label' => 'Nope' ),
					),
				)
			),
		);
		$result = Ai_Features::labels( $fields, 'translate', 'de_DE' );
		$this->assertCount( 1, $result['draft'] );
		$this->assertSame( 'Farbe', $result['draft'][0]['label'] );
		$this->assertSame( array( $opt => 'Rot' ), $result['draft'][0]['options'] );
		$this->assertStringContainsString( 'German', $this->requests[0]['body']['systemInstruction']['parts'][0]['text'] );
		$this->assertSame( 'dev3po_no_locale', Ai_Features::labels( $fields, 'translate', '' )->get_error_code() );
	}

	public function test_rest_ai_routes_return_drafts_only() {
		$this->with_key();
		$this->as_role( 'shop_manager' );
		$product         = $this->product( 12 );
		$this->responses = array( $this->json_response( $this->generated() ) );
		$res             = $this->rest( 'POST', '/ai/generate', array( 'product_id' => $product->get_id() ) );
		$this->assertSame( 200, $res->get_status() );
		$this->assertCount( 5, $res->get_data()['fields'] );
		$this->assertSame( '', get_post_meta( $product->get_id(), '_dev3po_fields', true ), 'nothing saved until accepted' );
	}
}
