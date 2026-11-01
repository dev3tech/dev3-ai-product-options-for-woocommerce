<?php
/**
 * Price engine (mirrored in src/shared/pricing.js; both pass tests/fixtures/price-cases.json).
 *
 * @package Dev3\AiProductOptions
 */

namespace Dev3\AiProductOptions\Engine;

use Dev3\AiProductOptions\Fields\Field_Types;

defined( 'ABSPATH' ) || exit;

/**
 * Computes add-on prices.
 *
 * Price types: flat, percent (of the product's unit price), per_char (price × characters over
 * `free_chars`), per_unit (price × numeric value, file count), qty_tiers (price per item from the
 * product-quantity tier), formula. Choice fields price their selected options (flat or percent).
 * `charge` = item (added to the unit price) or once (one fee per cart line).
 */
class Pricing {

	/**
	 * Calculates add-on totals.
	 *
	 * @param array $fields Normalised fields.
	 * @param array $values id => parsed value.
	 * @param float $base   Product unit price.
	 * @param int   $qty    Quantity.
	 * @return array { unit, once, total, lines: [ { id, label, amount, charge } ], visible }
	 */
	public static function calculate( array $fields, array $values, $base, $qty = 1 ) {
		$base    = (float) $base;
		$qty     = max( 1, (int) $qty );
		$visible = Conditions::visibility( $fields, $values );
		$vars    = self::field_vars( $fields, $values, $visible, $base, $qty );
		$unit    = 0.0;
		$once    = 0.0;
		$lines   = array();
		foreach ( $fields as $f ) {
			if ( ! Field_Types::is_input( $f['type'] ) || empty( $visible[ $f['id'] ] ) ) {
				continue;
			}
			$value  = array_key_exists( $f['id'], $values ) ? $values[ $f['id'] ] : Values::empty_value( $f );
			$amount = self::field_amount( $f, $value, $base, $qty, $vars );
			if ( 0.0 === (float) $amount ) {
				continue;
			}
			if ( 'once' === $f['charge'] ) {
				$once += $amount;
			} else {
				$unit += $amount;
			}
			$lines[] = array(
				'id'     => $f['id'],
				'label'  => $f['label'],
				'amount' => $amount,
				'charge' => $f['charge'],
			);
		}
		$unit_price = max( 0.0, $base + $unit );
		return array(
			'unit'    => $unit,
			'once'    => $once,
			'total'   => self::round_money( $unit_price * $qty + $once ),
			'lines'   => $lines,
			'visible' => $visible,
		);
	}

	/**
	 * Money rounding (2 decimals by default, half away from zero).
	 *
	 * @param float $value Value.
	 * @param int   $dp    Decimals.
	 * @return float
	 */
	public static function round_money( $value, $dp = 2 ) {
		return Formula::round_half( $value + ( $value >= 0 ? 1e-9 : -1e-9 ), $dp );
	}

	/**
	 * Formula variables shared by every field: {key}, {key.len}, {key.count}, {price}, {qty}.
	 *
	 * @param array $fields  Fields.
	 * @param array $values  Values.
	 * @param array $visible Visibility.
	 * @param float $base    Base.
	 * @param int   $qty     Qty.
	 * @return array
	 */
	private static function field_vars( array $fields, array $values, array $visible, $base, $qty ) {
		$vars = array(
			'price' => $base,
			'qty'   => $qty,
		);
		foreach ( $fields as $f ) {
			if ( ! Field_Types::is_input( $f['type'] ) ) {
				continue;
			}
			$value                        = ! empty( $visible[ $f['id'] ] ) && array_key_exists( $f['id'], $values ) ? $values[ $f['id'] ] : Values::empty_value( $f );
			$vars[ $f['key'] ]            = Values::numeric( $f, $value );
			$vars[ $f['key'] . '.len' ]   = Values::chars( $f, $value );
			$vars[ $f['key'] . '.count' ] = is_array( $value ) ? count( $value ) : ( Values::is_empty( $f, $value ) ? 0 : 1 );
		}
		return $vars;
	}

	/**
	 * Amount of one field (per item or once, depending on its charge).
	 *
	 * @param array $f     Field.
	 * @param mixed $value Value.
	 * @param float $base  Base.
	 * @param int   $qty   Qty.
	 * @param array $vars  Shared formula vars.
	 * @return float
	 */
	public static function field_amount( array $f, $value, $base, $qty, array $vars = array() ) {
		if ( Values::is_empty( $f, $value ) ) {
			return 0.0;
		}
		$kind   = Field_Types::kind( $f['type'] );
		$amount = 0.0;
		if ( 'choice' === $kind || 'multi' === $kind ) {
			foreach ( $f['options'] as $opt ) {
				if ( in_array( $opt['id'], (array) $value, true ) ) {
					$amount += 'percent' === $opt['price_type'] ? $base * $opt['price'] / 100 : $opt['price'];
				}
			}
		}
		if ( 'repeater' === $kind ) {
			foreach ( (array) $value as $row ) {
				foreach ( $f['subfields'] as $sub ) {
					if ( Field_Types::is_input( $sub['type'] ) ) {
						$sub_value = $row[ $sub['id'] ] ?? Values::empty_value( $sub );
						$amount   += self::field_amount( $sub, $sub_value, $base, $qty, self::own_vars( $sub, $sub_value, $vars ) );
					}
				}
			}
		}
		$price = (float) $f['price'];
		switch ( $f['price_type'] ) {
			case 'flat':
				$amount += 'repeater' === $kind ? $price * count( (array) $value ) : $price;
				break;
			case 'percent':
				$amount += $base * $price / 100;
				break;
			case 'per_char':
				$amount += $price * max( 0, Values::chars( $f, $value ) - (int) $f['free_chars'] );
				break;
			case 'per_unit':
				$amount += $price * Values::numeric( $f, $value );
				break;
			case 'qty_tiers':
				$amount += self::tier_price( $f['tiers'], $qty );
				break;
			case 'formula':
				$amount += Formula::evaluate( $f['formula'], self::own_vars( $f, $value, $vars ) );
				break;
		}
		return $amount;
	}

	/**
	 * Adds the field's own {value}, {len}, {words}, {lines}, {count}.
	 *
	 * @param array $f     Field.
	 * @param mixed $value Value.
	 * @param array $vars  Shared vars.
	 * @return array
	 */
	private static function own_vars( array $f, $value, array $vars ) {
		return array_merge(
			$vars,
			array(
				'value' => Values::numeric( $f, $value ),
				'len'   => Values::chars( $f, $value ),
				'words' => Values::words( $value ),
				'lines' => Values::lines( $value ),
				'count' => is_array( $value ) ? count( $value ) : ( Values::is_empty( $f, $value ) ? 0 : 1 ),
			)
		);
	}

	/**
	 * Price per item from quantity tiers (the highest tier whose min ≤ qty).
	 *
	 * @param array $tiers Tiers sorted by min.
	 * @param int   $qty   Qty.
	 * @return float
	 */
	public static function tier_price( array $tiers, $qty ) {
		$price = 0.0;
		foreach ( $tiers as $tier ) {
			if ( $qty >= (int) $tier['min'] ) {
				$price = (float) $tier['price'];
			}
		}
		return $price;
	}
}
