<?php
/**
 * Logic checker: impossible conditions, loops, broken references, price conflicts.
 *
 * @package Dev3\AiProductOptions
 */

use Dev3\AiProductOptions\Engine\Logic_Checker;
use Dev3\AiProductOptions\Fields\Field_Schema;

class LogicCheckerTest extends Dev3po_Test_Case {

	private function codes( array $fields ) {
		return wp_list_pluck( Logic_Checker::check( Field_Schema::normalize_list( $fields ) ), 'code' );
	}

	private function cond( $field, $op, $value = '', $match = 'all' ) {
		return array( 'match' => $match, 'rules' => array( array( 'field' => $field, 'op' => $op, 'value' => $value ) ) );
	}

	public function test_clean_set_has_no_errors() {
		$issues = Logic_Checker::check(
			Field_Schema::normalize_list(
				array(
					array( 'id' => 'f_gift', 'type' => 'checkbox', 'label' => 'Gift wrap', 'price_type' => 'flat', 'price' => 3 ),
					array( 'id' => 'f_msg', 'type' => 'textarea', 'label' => 'Message', 'conditions' => $this->cond( 'f_gift', 'checked' ) ),
				)
			)
		);
		$this->assertFalse( Logic_Checker::has_errors( $issues ) );
	}

	public function test_detects_loops() {
		$codes = $this->codes(
			array(
				array( 'id' => 'f_a', 'type' => 'checkbox', 'label' => 'A', 'conditions' => $this->cond( 'f_b', 'checked' ) ),
				array( 'id' => 'f_b', 'type' => 'checkbox', 'label' => 'B', 'conditions' => $this->cond( 'f_c', 'checked' ) ),
				array( 'id' => 'f_c', 'type' => 'checkbox', 'label' => 'C', 'conditions' => $this->cond( 'f_a', 'checked' ) ),
			)
		);
		$this->assertSame( 1, count( array_keys( $codes, 'loop', true ) ), 'one loop reported once' );
	}

	public function test_detects_loop_through_a_step() {
		$codes = $this->codes(
			array(
				array( 'id' => 'f_s1', 'type' => 'step', 'label' => 'Design', 'conditions' => $this->cond( 'f_in', 'checked' ) ),
				array( 'id' => 'f_in', 'type' => 'checkbox', 'label' => 'Inside the step' ),
			)
		);
		$this->assertContains( 'loop', $codes );
	}

	public function test_detects_impossible_conditions() {
		$size = array( 'id' => 'f_size', 'type' => 'select', 'label' => 'Size', 'options' => array( array( 'id' => 'o_s', 'label' => 'S' ), array( 'id' => 'o_m', 'label' => 'M' ) ) );
		$width = array( 'id' => 'f_w', 'type' => 'number', 'label' => 'Width', 'min' => 10, 'max' => 100 );
		$codes = $this->codes(
			array(
				$size,
				$width,
				array( 'id' => 'f_x', 'type' => 'text', 'label' => 'X', 'conditions' => array( 'rules' => array( array( 'field' => 'f_size', 'op' => 'is', 'value' => 'o_s' ), array( 'field' => 'f_size', 'op' => 'is', 'value' => 'o_m' ) ) ) ),
				array( 'id' => 'f_y', 'type' => 'text', 'label' => 'Y', 'conditions' => array( 'rules' => array( array( 'field' => 'f_w', 'op' => 'gt', 'value' => '50' ), array( 'field' => 'f_w', 'op' => 'lt', 'value' => '20' ) ) ) ),
				array( 'id' => 'f_z', 'type' => 'text', 'label' => 'Z', 'conditions' => $this->cond( 'f_w', 'gt', '100' ) ),
				array( 'id' => 'f_q', 'type' => 'text', 'label' => 'Q', 'conditions' => $this->cond( 'f_size', 'is', 'XXL' ) ),
				array( 'id' => 'f_r', 'type' => 'text', 'label' => 'R', 'conditions' => $this->cond( 'f_deleted', 'checked' ) ),
			)
		);
		$this->assertGreaterThanOrEqual( 2, count( array_keys( $codes, 'impossible', true ) ) );
		$this->assertContains( 'out_of_range', $codes );
		$this->assertContains( 'unknown_choice', $codes );
		$this->assertContains( 'missing_target', $codes );
	}

	public function test_any_match_is_not_flagged_as_contradiction() {
		$codes = $this->codes(
			array(
				array( 'id' => 'f_size', 'type' => 'radio', 'label' => 'Size', 'options' => array( array( 'id' => 'o_s', 'label' => 'S' ), array( 'id' => 'o_m', 'label' => 'M' ) ) ),
				array( 'id' => 'f_x', 'type' => 'text', 'label' => 'X', 'conditions' => array( 'match' => 'any', 'rules' => array( array( 'field' => 'f_size', 'op' => 'is', 'value' => 'o_s' ), array( 'field' => 'f_size', 'op' => 'is', 'value' => 'o_m' ) ) ) ),
			)
		);
		$this->assertNotContains( 'impossible', $codes );
	}

	public function test_price_conflicts() {
		$codes = $this->codes(
			array(
				array( 'id' => 'f_a', 'type' => 'text', 'label' => 'Engraving', 'price_type' => 'formula', 'formula' => 'max(0, {len} - 10' ),
				array( 'id' => 'f_b', 'type' => 'text', 'label' => 'B', 'price_type' => 'formula', 'formula' => '{height} * 2' ),
				array( 'id' => 'f_c', 'type' => 'checkbox', 'label' => 'C', 'price_type' => 'qty_tiers', 'tiers' => array( array( 'min' => 5, 'price' => 1 ), array( 'min' => 5, 'price' => 2 ) ) ),
				array( 'id' => 'f_d', 'type' => 'select', 'label' => 'D', 'options' => array( array( 'label' => 'Gold', 'price' => 5 ), array( 'label' => 'gold', 'price' => 7 ) ) ),
				array( 'id' => 'f_e', 'type' => 'text', 'label' => 'E', 'price_type' => 'per_char', 'price' => 1, 'free_chars' => 20, 'maxlength' => 10 ),
				array( 'id' => 'f_f', 'type' => 'radio', 'label' => 'F', 'options' => array( array( 'label' => 'Free', 'price' => -100, 'price_type' => 'percent' ) ) ),
				array( 'id' => 'f_g', 'type' => 'text', 'label' => 'G', 'price_type' => 'formula', 'formula' => '{len} / 0' ),
			)
		);
		foreach ( array( 'formula_error', 'formula_var', 'tier_conflict', 'option_conflict', 'per_char_free', 'option_free', 'negative_price', 'formula_div0' ) as $code ) {
			$this->assertContains( $code, $codes, $code );
		}
	}

	public function test_structural_errors() {
		$codes = $this->codes(
			array(
				array( 'id' => 'f_s', 'type' => 'step', 'label' => 'Step 1' ),
				array( 'id' => 'f_t', 'type' => 'step', 'label' => 'Step 2' ),
				array( 'id' => 'f_sel', 'type' => 'select', 'label' => 'Empty' ),
				array( 'id' => 'f_n', 'type' => 'number', 'label' => 'N', 'min' => 10, 'max' => 5 ),
				array( 'id' => 'f_d', 'type' => 'date', 'label' => 'D', 'disabled_days' => array( 0, 1, 2, 3, 4, 5, 6 ) ),
				array( 'id' => 'f_r', 'type' => 'repeater', 'label' => 'R', 'subfields' => array() ),
			)
		);
		foreach ( array( 'empty_step', 'no_options', 'min_max', 'all_days', 'empty_repeater' ) as $code ) {
			$this->assertContains( $code, $codes, $code );
		}
	}
}
