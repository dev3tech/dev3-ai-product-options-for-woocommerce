<?php
/**
 * Price engine: 100+ shared fixtures (same file as jest), formula parser, conditions, normaliser.
 *
 * @package Dev3\AiProductOptions
 */

use Dev3\AiProductOptions\Engine\Conditions;
use Dev3\AiProductOptions\Engine\Formula;
use Dev3\AiProductOptions\Engine\Pricing;
use Dev3\AiProductOptions\Engine\Values;
use Dev3\AiProductOptions\Fields\Field_Schema;

class EngineTest extends Dev3po_Test_Case {

	public function fixtures() {
		$cases = json_decode( file_get_contents( dirname( __DIR__ ) . '/fixtures/price-cases.json' ), true );
		$out   = array();
		foreach ( $cases as $case ) {
			$out[ $case['name'] ] = array( $case );
		}
		return $out;
	}

	public function test_fixture_count() {
		$this->assertGreaterThanOrEqual( 100, count( $this->fixtures() ) );
	}

	/**
	 * @dataProvider fixtures
	 */
	public function test_price_fixture( $case ) {
		$raw = Pricing::calculate( $case['fields'], $case['values'], $case['base'], $case['qty'] );
		$this->assertEqualsWithDelta( $case['expect']['unit'], $raw['unit'], 1e-6, 'unit' );
		$this->assertEqualsWithDelta( $case['expect']['once'], $raw['once'], 1e-6, 'once' );
		$this->assertSame( (float) $case['expect']['total'], (float) $raw['total'], 'total' );
		// The normaliser must keep every pricing-relevant setting.
		$normalized = Field_Schema::normalize_list( $case['fields'] );
		$again      = Pricing::calculate( $normalized, $case['values'], $case['base'], $case['qty'] );
		$this->assertSame( (float) $case['expect']['total'], (float) $again['total'], 'total after normalize' );
	}

	public function test_formula_rejects_unsafe_input() {
		$this->assertFalse( Formula::compile( 'system("ls")' )['ok'] );
		$this->assertSame( 'func:system', Formula::compile( 'system(1)' )['error'] );
		$this->assertSame( 'char:$', Formula::compile( '$x + 1' )['error'] );
		$this->assertSame( 'end', Formula::compile( '2 *' )['error'] );
		$this->assertSame( 'arity:if', Formula::compile( 'if(1,2)' )['error'] );
		$this->assertSame( 'too_long', Formula::compile( str_repeat( '1+', 300 ) . '1' )['error'] );
		$this->assertSame( 0.0, Formula::evaluate( 'phpinfo()', array() ) );
		$this->assertSame( array( 'len', 'width' ), Formula::compile( 'max(0,{len}-10)*{Width}' )['vars'] );
		$this->assertNotEmpty( Formula::error_message( 'func:x' ) );
	}

	public function test_formula_semantics_match_js() {
		$this->assertSame( 512.0, Formula::evaluate( '2 ^ 3 ^ 2', array() ) );
		$this->assertSame( -4.0, Formula::evaluate( '-2 ^ 2', array() ) );
		$this->assertSame( 1.01, Formula::round_half( 1.005, 2 ) );
		$this->assertSame( -3.0, Formula::evaluate( 'round(-2.5)', array() ) );
		$this->assertSame( 0.0, Formula::evaluate( '1 / 0', array() ) );
	}

	public function test_visibility_with_sections_and_loops() {
		$gift    = $this->field( 'checkbox', array( 'id' => 'f_gift' ) );
		$section = $this->field( 'section', array( 'id' => 'f_sec', 'conditions' => array( 'rules' => array( array( 'field' => 'f_gift', 'op' => 'checked' ) ) ) ) );
		$msg     = $this->field( 'text', array( 'id' => 'f_msg' ) );
		$vis     = Conditions::visibility( array( $gift, $section, $msg ), array( 'f_gift' => false, 'f_msg' => 'x' ) );
		$this->assertFalse( $vis['f_sec'] );
		$this->assertFalse( $vis['f_msg'], 'children of a hidden section are hidden' );
		$vis = Conditions::visibility( array( $gift, $section, $msg ), array( 'f_gift' => true ) );
		$this->assertTrue( $vis['f_msg'] );
	}

	public function test_values_parse_and_validate() {
		$email = $this->field( 'email', array( 'id' => 'f_mail', 'required' => true ) );
		$num   = $this->field( 'number', array( 'id' => 'f_num', 'min' => 1, 'max' => 10, 'step' => 0.5 ) );
		$sel   = $this->field( 'select', array( 'id' => 'f_sel', 'options' => array( 'Small', 'Large' ) ) );
		$fields = array( $email, $num, $sel );
		$values = Values::parse_all( $fields, array( 'f_mail' => 'nope', 'f_num' => '11', 'f_sel' => 'large' ) );
		$this->assertSame( 11.0, $values['f_num'] );
		$this->assertSame( $sel['options'][1]['id'], $values['f_sel'], 'posted value maps to option id' );
		$errors = Values::validate( $fields, $values, Conditions::visibility( $fields, $values ) );
		$this->assertArrayHasKey( 'f_mail', $errors );
		$this->assertArrayHasKey( 'f_num', $errors );
		$values = Values::parse_all( $fields, array( 'f_mail' => 'a@b.co', 'f_num' => '2.25' ) );
		$errors = Values::validate( $fields, $values, Conditions::visibility( $fields, $values ) );
		$this->assertStringContainsString( 'steps', $errors['f_num'] );
		$this->assertSame( 'Large', Values::display( $sel, $sel['options'][1]['id'] ) );
	}

	public function test_hidden_required_field_is_not_required() {
		$gift = $this->field( 'checkbox', array( 'id' => 'f_gift' ) );
		$msg  = $this->field( 'text', array( 'id' => 'f_msg', 'required' => true, 'conditions' => array( 'rules' => array( array( 'field' => 'f_gift', 'op' => 'checked' ) ) ) ) );
		$values = array( 'f_gift' => false, 'f_msg' => '' );
		$this->assertSame( array(), Values::validate( array( $gift, $msg ), $values, Conditions::visibility( array( $gift, $msg ), $values ) ) );
		$values['f_gift'] = true;
		$this->assertArrayHasKey( 'f_msg', Values::validate( array( $gift, $msg ), $values, Conditions::visibility( array( $gift, $msg ), $values ) ) );
	}

	public function test_normalizer_sanitizes_and_resolves_references() {
		$fields = Field_Schema::normalize_list(
			array(
				array( 'type' => 'evil', 'label' => 'x' ),
				array( 'type' => 'radio', 'label' => 'Size <script>alert(1)</script>', 'key' => 'size', 'options' => array( array( 'label' => 'Small', 'price' => '2.5' ), 'Large' ) ),
				array( 'type' => 'text', 'label' => 'Size', 'key' => 'size', 'price_type' => 'per_unit', 'price' => 3 ),
				array( 'type' => 'text', 'label' => 'Note', 'conditions' => array( 'rules' => array( array( 'field' => 'size', 'operator' => 'is', 'value' => 'large' ) ) ) ),
			)
		);
		$this->assertCount( 3, $fields, 'unknown type dropped' );
		$this->assertStringNotContainsString( '<script', $fields[0]['label'] );
		$this->assertSame( 2.5, $fields[0]['options'][0]['price'] );
		$this->assertSame( 'size_2', $fields[1]['key'], 'duplicate keys are suffixed' );
		$this->assertSame( 'flat', $fields[1]['price_type'], 'per_unit is not valid for text: coerced' );
		$rule = $fields[2]['conditions']['rules'][0];
		$this->assertSame( $fields[0]['id'], $rule['field'], 'key resolved to id' );
		$this->assertSame( $fields[0]['options'][1]['id'], $rule['value'], 'option label resolved to option id' );
		$this->assertTrue( $fields[2]['conditions']['enabled'] );
	}
}
