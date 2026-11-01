<?php
/**
 * Deterministic logic checker: impossible conditions, loops, broken references and price conflicts.
 *
 * @package Dev3\AiProductOptions
 */

namespace Dev3\AiProductOptions\Engine;

use Dev3\AiProductOptions\Fields\Field_Types;

defined( 'ABSPATH' ) || exit;

/**
 * Returns issues: [ { code, severity (error|warning|info), field, message } ].
 */
class Logic_Checker {

	const BUILTIN_VARS = array( 'price', 'qty', 'value', 'len', 'words', 'lines', 'count' );

	/**
	 * Runs every check.
	 *
	 * @param array $fields Normalised fields.
	 * @return array
	 */
	public static function check( array $fields ) {
		$issues = array();
		$by_id  = array();
		$keys   = array();
		foreach ( $fields as $f ) {
			$by_id[ $f['id'] ] = $f;
			if ( Field_Types::is_input( $f['type'] ) ) {
				$keys[ $f['key'] ] = $f;
			}
		}
		$inputs = array_filter( $fields, static fn( $f ) => Field_Types::is_input( $f['type'] ) );
		if ( ! $inputs ) {
			$issues[] = self::issue( 'no_inputs', 'info', '', __( 'This set has no input fields yet.', 'dev3-ai-product-options-for-woocommerce' ) );
		}
		foreach ( $fields as $index => $f ) {
			$issues = array_merge( $issues, self::check_field( $f, $index, $fields, $by_id, $keys ) );
		}
		$issues = array_merge( $issues, self::check_loops( $fields, $by_id ), self::check_steps( $fields ) );
		return $issues;
	}

	/**
	 * Whether there are blocking errors.
	 *
	 * @param array $issues Issues.
	 * @return bool
	 */
	public static function has_errors( array $issues ) {
		foreach ( $issues as $issue ) {
			if ( 'error' === $issue['severity'] ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Checks one field.
	 *
	 * @param array $f      Field.
	 * @param int   $index  Position.
	 * @param array $fields All fields.
	 * @param array $by_id  By id.
	 * @param array $keys   By key.
	 * @return array
	 */
	private static function check_field( array $f, $index, array $fields, array $by_id, array $keys ) {
		$issues = array();
		$name   = '' !== $f['label'] ? $f['label'] : $f['key'];
		$kind   = Field_Types::kind( $f['type'] );

		if ( Field_Types::is_input( $f['type'] ) && '' === trim( $f['label'] ) && 'hidden' !== $f['type'] ) {
			$issues[] = self::issue( 'no_label', 'warning', $f['id'], __( 'A field has no label, so shoppers will not know what it is for.', 'dev3-ai-product-options-for-woocommerce' ) );
		}
		if ( Field_Types::has_options( $f['type'] ) ) {
			if ( empty( $f['options'] ) ) {
				/* translators: %s: field label */
				$issues[] = self::issue( 'no_options', 'error', $f['id'], sprintf( __( '"%s" has no choices.', 'dev3-ai-product-options-for-woocommerce' ), $name ) );
			}
			$defaults = array_filter( $f['options'] ?? array(), static fn( $o ) => ! empty( $o['default'] ) );
			if ( 'choice' === $kind && count( $defaults ) > 1 ) {
				/* translators: %s: field label */
				$issues[] = self::issue( 'many_defaults', 'warning', $f['id'], sprintf( __( '"%s" allows one choice but has several defaults.', 'dev3-ai-product-options-for-woocommerce' ), $name ) );
			}
			if ( in_array( $f['type'], array( 'image_swatch', 'image_picker' ), true ) ) {
				foreach ( $f['options'] as $opt ) {
					if ( '' === $opt['image'] ) {
						/* translators: 1: option label, 2: field label */
						$issues[] = self::issue( 'no_image', 'warning', $f['id'], sprintf( __( 'Choice "%1$s" in "%2$s" has no image.', 'dev3-ai-product-options-for-woocommerce' ), $opt['label'], $name ) );
						break;
					}
				}
			}
			if ( 'multi' === $kind && ! empty( $f['max_select'] ) && $f['required'] && $f['max_select'] < 1 ) {
				/* translators: %s: field label */
				$issues[] = self::issue( 'max_select', 'error', $f['id'], sprintf( __( '"%s" is required but no choice can be selected.', 'dev3-ai-product-options-for-woocommerce' ), $name ) );
			}
		}
		if ( 'number' === $kind && null !== ( $f['min'] ?? null ) && null !== ( $f['max'] ?? null ) && $f['min'] > $f['max'] ) {
			/* translators: %s: field label */
			$issues[] = self::issue( 'min_max', 'error', $f['id'], sprintf( __( '"%s": the minimum is larger than the maximum, so no value is possible.', 'dev3-ai-product-options-for-woocommerce' ), $name ) );
		}
		if ( 'text' === $kind && ! empty( $f['maxlength'] ) && ! empty( $f['minlength'] ) && $f['minlength'] > $f['maxlength'] ) {
			/* translators: %s: field label */
			$issues[] = self::issue( 'min_max_length', 'error', $f['id'], sprintf( __( '"%s": the minimum length is larger than the maximum length.', 'dev3-ai-product-options-for-woocommerce' ), $name ) );
		}
		if ( 'date' === $kind && null !== ( $f['date_min'] ?? null ) && null !== ( $f['date_max'] ?? null ) && $f['date_min'] > $f['date_max'] ) {
			/* translators: %s: field label */
			$issues[] = self::issue( 'date_range', 'error', $f['id'], sprintf( __( '"%s": the earliest date is after the latest date.', 'dev3-ai-product-options-for-woocommerce' ), $name ) );
		}
		if ( 'date' === $kind && count( $f['disabled_days'] ?? array() ) >= 7 ) {
			/* translators: %s: field label */
			$issues[] = self::issue( 'all_days', 'error', $f['id'], sprintf( __( '"%s": every weekday is disabled.', 'dev3-ai-product-options-for-woocommerce' ), $name ) );
		}
		if ( 'repeater' === $f['type'] && empty( $f['subfields'] ) ) {
			/* translators: %s: field label */
			$issues[] = self::issue( 'empty_repeater', 'error', $f['id'], sprintf( __( 'Repeater "%s" has no sub-fields.', 'dev3-ai-product-options-for-woocommerce' ), $name ) );
		}

		$issues = array_merge( $issues, self::check_price( $f, $name, $keys ) );
		foreach ( $f['subfields'] ?? array() as $sub ) {
			$issues = array_merge( $issues, self::check_price( $sub, $name . ' › ' . ( '' !== $sub['label'] ? $sub['label'] : $sub['key'] ), $keys, $f['id'] ) );
		}

		if ( ! empty( $f['conditions']['enabled'] ) ) {
			$issues = array_merge( $issues, self::check_conditions( $f, $name, $index, $fields, $by_id ) );
		}
		return $issues;
	}

	/**
	 * Price checks.
	 *
	 * @param array  $f      Field.
	 * @param string $name   Display name.
	 * @param array  $keys   Fields by key.
	 * @param string $report Field id to report against.
	 * @return array
	 */
	private static function check_price( array $f, $name, array $keys, $report = '' ) {
		$issues = array();
		$id     = '' !== $report ? $report : $f['id'];
		if ( 'formula' === $f['price_type'] ) {
			$compiled = Formula::compile( $f['formula'] );
			if ( ! $compiled['ok'] ) {
				/* translators: 1: field label, 2: error */
				$issues[] = self::issue( 'formula_error', 'error', $id, sprintf( __( '"%1$s": formula error. %2$s', 'dev3-ai-product-options-for-woocommerce' ), $name, Formula::error_message( $compiled['error'] ) ) );
			} else {
				foreach ( $compiled['vars'] as $var ) {
					$base = explode( '.', $var )[0];
					if ( ! in_array( $var, self::BUILTIN_VARS, true ) && ! isset( $keys[ $base ] ) ) {
						/* translators: 1: field label, 2: variable */
						$issues[] = self::issue( 'formula_var', 'error', $id, sprintf( __( '"%1$s": the formula uses {%2$s}, but no field has that key.', 'dev3-ai-product-options-for-woocommerce' ), $name, $var ) );
					}
					if ( isset( $keys[ $base ] ) && $keys[ $base ]['id'] === $f['id'] ) {
						/* translators: %s: field label */
						$issues[] = self::issue( 'formula_self', 'warning', $id, sprintf( __( '"%s": use {value} or {len} to refer to the field itself.', 'dev3-ai-product-options-for-woocommerce' ), $name ) );
					}
				}
				if ( preg_match( '#/\s*0(?![.\d])#', $f['formula'] ) ) {
					/* translators: %s: field label */
					$issues[] = self::issue( 'formula_div0', 'warning', $id, sprintf( __( '"%s": the formula divides by zero (the result will be 0).', 'dev3-ai-product-options-for-woocommerce' ), $name ) );
				}
			}
		}
		if ( 'per_char' === $f['price_type'] && ! empty( $f['maxlength'] ) && $f['free_chars'] >= $f['maxlength'] ) {
			/* translators: %s: field label */
			$issues[] = self::issue( 'per_char_free', 'warning', $id, sprintf( __( '"%s": the free characters cover the whole maximum length, so this price never applies.', 'dev3-ai-product-options-for-woocommerce' ), $name ) );
		}
		if ( 'qty_tiers' === $f['price_type'] && empty( $f['tiers'] ) ) {
			/* translators: %s: field label */
			$issues[] = self::issue( 'no_tiers', 'error', $id, sprintf( __( '"%s" uses quantity tiers but has none.', 'dev3-ai-product-options-for-woocommerce' ), $name ) );
		}
		if ( 'qty_tiers' === $f['price_type'] ) {
			$seen = array();
			foreach ( $f['tiers'] as $tier ) {
				if ( isset( $seen[ $tier['min'] ] ) && $seen[ $tier['min'] ] !== $tier['price'] ) {
					/* translators: 1: field label, 2: quantity */
					$issues[] = self::issue( 'tier_conflict', 'error', $id, sprintf( __( '"%1$s": two tiers start at quantity %2$d with different prices.', 'dev3-ai-product-options-for-woocommerce' ), $name, $tier['min'] ) );
				}
				$seen[ $tier['min'] ] = $tier['price'];
			}
		}
		if ( 'percent' === $f['price_type'] && abs( $f['price'] ) > 100 ) {
			/* translators: %s: field label */
			$issues[] = self::issue( 'percent_high', 'warning', $id, sprintf( __( '"%s" adds more than 100%% of the product price. Is that intended?', 'dev3-ai-product-options-for-woocommerce' ), $name ) );
		}
		if ( in_array( $f['price_type'], array( 'flat', 'percent', 'per_char', 'per_unit' ), true ) && 0.0 === (float) $f['price'] ) {
			/* translators: %s: field label */
			$issues[] = self::issue( 'zero_price', 'info', $id, sprintf( __( '"%s" has a price type but a price of 0.', 'dev3-ai-product-options-for-woocommerce' ), $name ) );
		}
		$negative = $f['price'] < 0 && 'none' !== $f['price_type'];
		foreach ( $f['options'] ?? array() as $opt ) {
			$negative = $negative || $opt['price'] < 0;
			if ( 'percent' === $opt['price_type'] && $opt['price'] <= -100 ) {
				/* translators: 1: option, 2: field */
				$issues[] = self::issue( 'option_free', 'error', $id, sprintf( __( 'Choice "%1$s" in "%2$s" takes 100%% or more off, so the product becomes free.', 'dev3-ai-product-options-for-woocommerce' ), $opt['label'], $name ) );
			}
		}
		if ( $negative ) {
			/* translators: %s: field label */
			$issues[] = self::issue( 'negative_price', 'warning', $id, sprintf( __( '"%s" has a negative price (a discount). The unit price never goes below 0.', 'dev3-ai-product-options-for-woocommerce' ), $name ) );
		}
		$values = array();
		foreach ( $f['options'] ?? array() as $opt ) {
			$label = mb_strtolower( $opt['label'] );
			if ( isset( $values[ $label ] ) && $values[ $label ] !== $opt['price'] ) {
				/* translators: 1: option, 2: field */
				$issues[] = self::issue( 'option_conflict', 'error', $id, sprintf( __( 'Choice "%1$s" appears twice in "%2$s" with different prices.', 'dev3-ai-product-options-for-woocommerce' ), $opt['label'], $name ) );
			}
			$values[ $label ] = $opt['price'];
		}
		return $issues;
	}

	/**
	 * Condition checks.
	 *
	 * @param array  $f      Field.
	 * @param string $name   Name.
	 * @param int    $index  Position.
	 * @param array  $fields Fields.
	 * @param array  $by_id  By id.
	 * @return array
	 */
	private static function check_conditions( array $f, $name, $index, array $fields, array $by_id ) {
		$issues    = array();
		$rules     = $f['conditions']['rules'];
		$by_target = array();
		foreach ( $rules as $rule ) {
			$target = $by_id[ $rule['field'] ] ?? null;
			if ( ! $target ) {
				/* translators: %s: field label */
				$issues[] = self::issue( 'missing_target', 'error', $f['id'], sprintf( __( '"%s" has a rule that points at a field that no longer exists.', 'dev3-ai-product-options-for-woocommerce' ), $name ) );
				continue;
			}
			if ( $target['id'] === $f['id'] ) {
				/* translators: %s: field label */
				$issues[] = self::issue( 'self_condition', 'error', $f['id'], sprintf( __( '"%s" depends on itself.', 'dev3-ai-product-options-for-woocommerce' ), $name ) );
				continue;
			}
			if ( ! Field_Types::is_input( $target['type'] ) ) {
				/* translators: %s: field label */
				$issues[] = self::issue( 'layout_target', 'error', $f['id'], sprintf( __( '"%s" has a rule on a layout element, which has no value.', 'dev3-ai-product-options-for-woocommerce' ), $name ) );
				continue;
			}
			$tkind = Field_Types::kind( $target['type'] );
			$tname = '' !== $target['label'] ? $target['label'] : $target['key'];
			if ( in_array( $rule['op'], array( 'is', 'is_not', 'contains', 'not_contains' ), true ) && in_array( $tkind, array( 'choice', 'multi' ), true ) ) {
				$ids = wp_list_pluck( $target['options'], 'id' );
				if ( ! in_array( $rule['value'], $ids, true ) ) {
					/* translators: 1: field label, 2: target label, 3: value */
					$issues[] = self::issue( 'unknown_choice', 'error', $f['id'], sprintf( __( '"%1$s" waits for "%2$s" to be "%3$s", which is not one of its choices.', 'dev3-ai-product-options-for-woocommerce' ), $name, $tname, $rule['value'] ) );
				}
			}
			if ( in_array( $rule['op'], array( 'gt', 'lt', 'gte', 'lte' ), true ) ) {
				if ( ! is_numeric( $rule['value'] ) ) {
					/* translators: %s: field label */
					$issues[] = self::issue( 'not_numeric', 'error', $f['id'], sprintf( __( '"%s" compares with a value that is not a number.', 'dev3-ai-product-options-for-woocommerce' ), $name ) );
				} elseif ( 'number' === $tkind ) {
					$v = (float) $rule['value'];
					if ( ( in_array( $rule['op'], array( 'gt', 'gte' ), true ) && null !== $target['max'] && ( $v > $target['max'] || ( 'gt' === $rule['op'] && $v >= $target['max'] ) ) )
						|| ( in_array( $rule['op'], array( 'lt', 'lte' ), true ) && null !== $target['min'] && ( $v < $target['min'] || ( 'lt' === $rule['op'] && $v <= $target['min'] ) ) ) ) {
						/* translators: 1: field label, 2: target label */
						$issues[] = self::issue( 'out_of_range', 'error', $f['id'], sprintf( __( '"%1$s" can never match: "%2$s" cannot reach that value.', 'dev3-ai-product-options-for-woocommerce' ), $name, $tname ) );
					}
				}
			}
			if ( in_array( $rule['op'], array( 'checked', 'unchecked' ), true ) && ! in_array( $tkind, array( 'bool', 'multi', 'file' ), true ) ) {
				/* translators: %s: field label */
				$issues[] = self::issue( 'checked_on_text', 'warning', $f['id'], sprintf( __( '"%s" uses "is checked" on a field that is not a checkbox; it behaves like "is not empty".', 'dev3-ai-product-options-for-woocommerce' ), $name ) );
			}
			if ( 'hidden' === $target['type'] && in_array( $rule['op'], array( 'is', 'is_not' ), true ) ) {
				$match = Conditions::test( $target, $target['default'], $rule );
				/* translators: %s: field label */
				$issues[] = self::issue( $match ? 'always_true' : 'never_true', 'warning', $f['id'], sprintf( $match ? __( '"%s" depends on a hidden value, so the rule is always true.', 'dev3-ai-product-options-for-woocommerce' ) : __( '"%s" depends on a hidden value, so the rule is never true.', 'dev3-ai-product-options-for-woocommerce' ), $name ) );
			}
			$by_target[ $target['id'] ][] = $rule;
		}

		// Contradictions inside an "all" group (e.g. Size is S and Size is M; Width > 10 and < 5).
		if ( 'all' === $f['conditions']['match'] ) {
			foreach ( $by_target as $tid => $trules ) {
				$target = $by_id[ $tid ];
				$tkind  = Field_Types::kind( $target['type'] );
				$is     = array_unique( array_map( static fn( $r ) => $r['value'], array_filter( $trules, static fn( $r ) => 'is' === $r['op'] ) ) );
				$is_not = array_map( static fn( $r ) => $r['value'], array_filter( $trules, static fn( $r ) => 'is_not' === $r['op'] ) );
				$ops    = wp_list_pluck( $trules, 'op' );
				$contra = ( 'multi' !== $tkind && count( $is ) > 1 )
					|| array_intersect( $is, $is_not )
					|| ( in_array( 'empty', $ops, true ) && array_intersect( $ops, array( 'not_empty', 'is', 'checked', 'gt', 'gte', 'lt', 'lte', 'contains' ) ) )
					|| ( in_array( 'checked', $ops, true ) && in_array( 'unchecked', $ops, true ) );
				$low    = -INF;
				$high   = INF;
				foreach ( $trules as $r ) {
					if ( ! is_numeric( $r['value'] ) ) {
						continue;
					}
					$v = (float) $r['value'];
					if ( 'gt' === $r['op'] ) {
						$low = max( $low, $v + 1e-9 );
					} elseif ( 'gte' === $r['op'] ) {
						$low = max( $low, $v );
					} elseif ( 'lt' === $r['op'] ) {
						$high = min( $high, $v - 1e-9 );
					} elseif ( 'lte' === $r['op'] ) {
						$high = min( $high, $v );
					}
				}
				if ( $contra || $low > $high ) {
					/* translators: 1: field label, 2: target label */
					$issues[] = self::issue( 'impossible', 'error', $f['id'], sprintf( __( '"%1$s" can never be shown: its rules on "%2$s" contradict each other.', 'dev3-ai-product-options-for-woocommerce' ), $name, '' !== $target['label'] ? $target['label'] : $target['key'] ) );
				}
			}
		}

		// Required + "hide" on a field whose target is required too is fine; required fields hidden by default are worth a note.
		if ( ! empty( $f['required'] ) && 'show' === $f['conditions']['action'] ) {
			$issues[] = self::issue( 'conditional_required', 'info', $f['id'], sprintf( /* translators: %s: field label */ __( '"%s" is only required while it is shown.', 'dev3-ai-product-options-for-woocommerce' ), $name ) );
		}
		unset( $index, $fields );
		return $issues;
	}

	/**
	 * Detects circular conditions (A depends on B depends on A), including through sections/steps.
	 *
	 * @param array $fields Fields.
	 * @param array $by_id  By id.
	 * @return array
	 */
	private static function check_loops( array $fields, array $by_id ) {
		$parents = Conditions::parents( $fields );
		$edges   = array();
		foreach ( $fields as $f ) {
			$edges[ $f['id'] ] = $parents[ $f['id'] ] ?? array();
			if ( ! empty( $f['conditions']['enabled'] ) ) {
				foreach ( $f['conditions']['rules'] as $rule ) {
					if ( isset( $by_id[ $rule['field'] ] ) && $rule['field'] !== $f['id'] ) {
						$edges[ $f['id'] ][] = $rule['field'];
					}
				}
			}
		}
		$issues   = array();
		$state    = array();
		$reported = array();
		foreach ( array_keys( $edges ) as $start ) {
			$cycle = self::find_cycle( $start, $edges, $state, array() );
			if ( $cycle ) {
				$sorted = $cycle;
				sort( $sorted );
				$sig = implode( ',', $sorted );
				if ( ! isset( $reported[ $sig ] ) ) {
					$reported[ $sig ] = true;
					$names            = array_map( static fn( $id ) => '' !== $by_id[ $id ]['label'] ? $by_id[ $id ]['label'] : $by_id[ $id ]['key'], $cycle );
					/* translators: %s: chain of field labels */
					$issues[] = self::issue( 'loop', 'error', $cycle[0], sprintf( __( 'Circular conditions: %s. These fields wait for each other, so they may never show.', 'dev3-ai-product-options-for-woocommerce' ), implode( ' → ', array_merge( $names, array( $names[0] ) ) ) ) );
				}
			}
		}
		return $issues;
	}

	/**
	 * DFS cycle finder.
	 *
	 * @param string $node  Node.
	 * @param array  $edges Edges.
	 * @param array  $state Visit state (1 = in stack, 2 = done).
	 * @param array  $stack Current path.
	 * @return array|null Cycle node ids.
	 */
	private static function find_cycle( $node, array $edges, array &$state, array $stack ) {
		if ( isset( $state[ $node ] ) && 2 === $state[ $node ] ) {
			return null;
		}
		if ( isset( $state[ $node ] ) && 1 === $state[ $node ] ) {
			$pos = array_search( $node, $stack, true );
			return false === $pos ? null : array_slice( $stack, $pos );
		}
		$state[ $node ] = 1;
		$stack[]        = $node;
		foreach ( $edges[ $node ] ?? array() as $next ) {
			$cycle = self::find_cycle( $next, $edges, $state, $stack );
			if ( $cycle ) {
				$state[ $node ] = 2;
				return $cycle;
			}
		}
		$state[ $node ] = 2;
		return null;
	}

	/**
	 * Steps: empty steps and a first field that is not a step when steps are used.
	 *
	 * @param array $fields Fields.
	 * @return array
	 */
	private static function check_steps( array $fields ) {
		$issues = array();
		$count  = 0;
		foreach ( $fields as $i => $f ) {
			if ( 'step' !== $f['type'] ) {
				continue;
			}
			++$count;
			$next = $fields[ $i + 1 ] ?? null;
			if ( ! $next || 'step' === $next['type'] ) {
				/* translators: %s: step label */
				$issues[] = self::issue( 'empty_step', 'warning', $f['id'], sprintf( __( 'Step "%s" has no fields.', 'dev3-ai-product-options-for-woocommerce' ), $f['label'] ) );
			}
		}
		if ( $count && 'step' !== $fields[0]['type'] ) {
			$issues[] = self::issue( 'step_first', 'warning', $fields[0]['id'], __( 'When you use steps, start the set with a step so every field belongs to one.', 'dev3-ai-product-options-for-woocommerce' ) );
		}
		return $issues;
	}

	/**
	 * Issue array.
	 *
	 * @param string $code     Code.
	 * @param string $severity Severity.
	 * @param string $field    Field id.
	 * @param string $message  Message.
	 * @return array
	 */
	private static function issue( $code, $severity, $field, $message ) {
		return array(
			'code'     => $code,
			'severity' => $severity,
			'field'    => $field,
			'message'  => $message,
			'source'   => 'checker',
		);
	}
}
