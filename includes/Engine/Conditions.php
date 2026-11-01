<?php
/**
 * Conditional logic (mirrored in src/shared/conditions.js).
 *
 * @package Dev3\AiProductOptions
 */

namespace Dev3\AiProductOptions\Engine;

use Dev3\AiProductOptions\Fields\Field_Types;

defined( 'ABSPATH' ) || exit;

/**
 * Evaluates show/hide rules to a visibility map.
 */
class Conditions {

	/**
	 * Visibility of each top-level field: id => bool.
	 *
	 * A field is hidden when its own rules say so, when its section or step is hidden, and a rule that
	 * points at a hidden field sees an empty value. Evaluation repeats until nothing changes (bounded,
	 * so circular rules settle deterministically; the logic checker reports them).
	 *
	 * @param array $fields Normalised fields.
	 * @param array $values id => parsed value.
	 * @return array
	 */
	public static function visibility( array $fields, array $values ) {
		$by_id   = array();
		$parents = self::parents( $fields );
		$visible = array();
		foreach ( $fields as $f ) {
			$by_id[ $f['id'] ]   = $f;
			$visible[ $f['id'] ] = true;
		}
		$passes = count( $fields ) + 2;
		for ( $pass = 0; $pass < $passes; $pass++ ) {
			$changed = false;
			foreach ( $fields as $f ) {
				$v = self::own_rule( $f, $by_id, $values, $visible );
				foreach ( $parents[ $f['id'] ] as $parent ) {
					if ( empty( $visible[ $parent ] ) ) {
						$v = false;
					}
				}
				if ( $v !== $visible[ $f['id'] ] ) {
					$visible[ $f['id'] ] = $v;
					$changed             = true;
				}
			}
			if ( ! $changed ) {
				break;
			}
		}
		return $visible;
	}

	/**
	 * Section and step that contain each field: id => [ parent ids ].
	 *
	 * @param array $fields Fields.
	 * @return array
	 */
	public static function parents( array $fields ) {
		$out     = array();
		$section = null;
		$step    = null;
		foreach ( $fields as $f ) {
			if ( 'step' === $f['type'] ) {
				$step            = $f['id'];
				$section         = null;
				$out[ $f['id'] ] = array();
				continue;
			}
			if ( 'section' === $f['type'] ) {
				$section         = $f['id'];
				$out[ $f['id'] ] = $step ? array( $step ) : array();
				continue;
			}
			$out[ $f['id'] ] = array_values( array_filter( array( $step, $section ) ) );
		}
		return $out;
	}

	/**
	 * Result of a field's own rules.
	 *
	 * @param array $f       Field.
	 * @param array $by_id   Fields by id.
	 * @param array $values  Values.
	 * @param array $visible Current visibility.
	 * @return bool
	 */
	private static function own_rule( array $f, array $by_id, array $values, array $visible ) {
		if ( empty( $f['conditions']['enabled'] ) || empty( $f['conditions']['rules'] ) ) {
			return true;
		}
		$results = array();
		foreach ( $f['conditions']['rules'] as $rule ) {
			$target = $by_id[ $rule['field'] ] ?? null;
			if ( ! $target ) {
				$results[] = false;
				continue;
			}
			$value     = ! empty( $visible[ $target['id'] ] ) && array_key_exists( $target['id'], $values ) ? $values[ $target['id'] ] : Values::empty_value( $target );
			$results[] = self::test( $target, $value, $rule );
		}
		$pass = 'any' === $f['conditions']['match'] ? in_array( true, $results, true ) : ! in_array( false, $results, true );
		return 'hide' === $f['conditions']['action'] ? ! $pass : $pass;
	}

	/**
	 * Tests one rule.
	 *
	 * @param array $target Target field.
	 * @param mixed $value  Target value.
	 * @param array $rule   Rule.
	 * @return bool
	 */
	public static function test( array $target, $value, array $rule ) {
		$kind = Field_Types::kind( $target['type'] );
		$want = (string) $rule['value'];
		switch ( $rule['op'] ) {
			case 'empty':
				return Values::is_empty( $target, $value );
			case 'not_empty':
				return ! Values::is_empty( $target, $value );
			case 'checked':
				return 'bool' === $kind ? (bool) $value : ! Values::is_empty( $target, $value );
			case 'unchecked':
				return 'bool' === $kind ? ! $value : Values::is_empty( $target, $value );
			case 'is':
			case 'is_not':
				$match = self::equals( $kind, $value, $want );
				return 'is' === $rule['op'] ? $match : ! $match;
			case 'contains':
			case 'not_contains':
				if ( is_array( $value ) || 'choice' === $kind ) {
					$match = in_array( $want, (array) $value, true );
				} else {
					$match = '' !== $want && false !== mb_stripos( Values::to_string( $value ), $want );
				}
				return 'contains' === $rule['op'] ? $match : ! $match;
			case 'gt':
			case 'lt':
			case 'gte':
			case 'lte':
				if ( ! is_numeric( $want ) || Values::is_empty( $target, $value ) ) {
					return false;
				}
				$n = Values::numeric( $target, $value );
				$w = (float) $want;
				if ( 'gt' === $rule['op'] ) {
					return $n > $w;
				}
				if ( 'lt' === $rule['op'] ) {
					return $n < $w;
				}
				return 'gte' === $rule['op'] ? $n >= $w : $n <= $w;
		}
		return false;
	}

	/**
	 * Equality by kind.
	 *
	 * @param string $kind  Kind.
	 * @param mixed  $value Value.
	 * @param string $want  Expected.
	 * @return bool
	 */
	private static function equals( $kind, $value, $want ) {
		if ( 'choice' === $kind || 'multi' === $kind ) {
			return in_array( $want, (array) $value, true );
		}
		if ( 'bool' === $kind ) {
			$truthy = in_array( strtolower( $want ), array( '1', 'yes', 'true', 'on', 'checked' ), true );
			return (bool) $value === $truthy;
		}
		$have = Values::to_string( $value );
		if ( is_numeric( $have ) && is_numeric( $want ) ) {
			return abs( (float) $have - (float) $want ) < 1e-9;
		}
		return mb_strtolower( trim( $have ) ) === mb_strtolower( trim( $want ) );
	}
}
