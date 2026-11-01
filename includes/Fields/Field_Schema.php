<?php
/**
 * Field definition normaliser (admin + AI input) and structural validator for AI option sets.
 *
 * @package Dev3\AiProductOptions
 */

namespace Dev3\AiProductOptions\Fields;

use Dev3\AiProductOptions\Engine\Formula;

defined( 'ABSPATH' ) || exit;

/**
 * Turns untrusted field arrays into the canonical shape used everywhere.
 */
class Field_Schema {

	const MAX_FIELDS  = 100;
	const MAX_OPTIONS = 60;

	/**
	 * Normalises a list of fields. Unknown types are dropped; ids and keys are made unique; condition
	 * references given as keys or labels are resolved to field ids, option values/labels to option ids.
	 *
	 * @param mixed $fields Raw list.
	 * @param int   $depth  Nesting depth (repeater sub-fields are depth 1).
	 * @return array
	 */
	public static function normalize_list( $fields, $depth = 0 ) {
		if ( ! is_array( $fields ) ) {
			return array();
		}
		$out  = array();
		$ids  = array();
		$keys = array();
		foreach ( array_slice( array_values( $fields ), 0, self::MAX_FIELDS ) as $raw ) {
			$field = self::normalize_field( $raw, $depth );
			if ( ! $field ) {
				continue;
			}
			while ( isset( $ids[ $field['id'] ] ) ) {
				$field['id'] = self::new_id( 'f' );
			}
			$ids[ $field['id'] ] = true;
			$base                = $field['key'];
			$n                   = 2;
			while ( isset( $keys[ $field['key'] ] ) ) {
				$field['key'] = $base . '_' . $n;
				++$n;
			}
			$keys[ $field['key'] ] = true;
			$out[]                 = $field;
		}
		return self::resolve_references( $out );
	}

	/**
	 * Normalises one field.
	 *
	 * @param mixed $raw   Raw field.
	 * @param int   $depth Depth.
	 * @return array|null
	 */
	public static function normalize_field( $raw, $depth = 0 ) {
		if ( ! is_array( $raw ) ) {
			return null;
		}
		$type = isset( $raw['type'] ) ? sanitize_key( (string) $raw['type'] ) : '';
		if ( ! Field_Types::exists( $type ) || ( $depth > 0 && in_array( $type, array( 'repeater', 'step', 'section' ), true ) ) ) {
			return null;
		}
		$kind  = Field_Types::kind( $type );
		$label = self::text( $raw['label'] ?? '', 120 );
		$id    = isset( $raw['id'] ) && preg_match( '/^[a-z][a-z0-9_]{1,30}$/', (string) $raw['id'] ) ? (string) $raw['id'] : self::new_id( 'f' );
		$key   = isset( $raw['key'] ) ? sanitize_key( (string) $raw['key'] ) : '';
		if ( '' === $key ) {
			$key = sanitize_key( str_replace( '-', '_', sanitize_title( $label ) ) );
		}
		$key = substr( str_replace( '-', '_', $key ), 0, 40 );
		if ( '' === $key || ! preg_match( '/^[a-z_]/', $key ) ) {
			$key = 'field_' . $key;
		}
		if ( in_array( $key, array( 'price', 'qty', 'value', 'len', 'words', 'lines', 'count' ), true ) ) {
			$key .= '_field';
		}

		$price_types = Field_Types::price_types( $type );
		$price_type  = isset( $raw['price_type'] ) ? sanitize_key( (string) $raw['price_type'] ) : 'none';
		if ( ! in_array( $price_type, $price_types, true ) ) {
			$price_type = ( isset( $raw['price'] ) && (float) $raw['price'] && in_array( 'flat', $price_types, true ) ) ? 'flat' : 'none';
		}

		$field = array(
			'id'          => $id,
			'type'        => $type,
			'key'         => $key,
			'label'       => $label,
			'help'        => self::text( $raw['help'] ?? '', 300, true ),
			'tooltip'     => self::text( $raw['tooltip'] ?? '', 300, true ),
			'placeholder' => self::text( $raw['placeholder'] ?? '', 120 ),
			'required'    => ! empty( $raw['required'] ) && 'layout' !== $kind && 'hidden' !== $kind,
			'default'     => self::text( is_scalar( $raw['default'] ?? '' ) ? ( $raw['default'] ?? '' ) : '', 200 ),
			'width'       => isset( $raw['width'] ) && 'half' === $raw['width'] ? 'half' : 'full',
			'class'       => implode( ' ', array_filter( array_map( 'sanitize_html_class', explode( ' ', (string) ( $raw['class'] ?? '' ) ) ) ) ),
			'price_type'  => $price_type,
			'price'       => self::money( $raw['price'] ?? 0 ),
			'formula'     => self::formula( $raw['formula'] ?? '' ),
			'tiers'       => self::tiers( $raw['tiers'] ?? array() ),
			'charge'      => isset( $raw['charge'] ) && 'once' === $raw['charge'] ? 'once' : 'item',
			'free_chars'  => max( 0, min( 10000, (int) ( $raw['free_chars'] ?? 0 ) ) ),
			'count_space' => ! empty( $raw['count_space'] ),
			'conditions'  => self::conditions( $raw['conditions'] ?? ( $raw['condition'] ?? array() ) ),
			'i18n'        => self::i18n( $raw['i18n'] ?? array() ),
		);

		if ( 'formula' !== $price_type ) {
			$field['formula'] = '';
		}

		if ( Field_Types::has_options( $type ) ) {
			$field['options']    = self::options( $raw['options'] ?? array() );
			$field['max_select'] = max( 0, min( self::MAX_OPTIONS, (int) ( $raw['max_select'] ?? 0 ) ) );
		}
		if ( 'number' === $kind || in_array( $type, array( 'text', 'textarea' ), true ) ) {
			$field['min']  = self::num_or_null( $raw['min'] ?? null );
			$field['max']  = self::num_or_null( $raw['max'] ?? null );
			$field['step'] = self::num_or_null( $raw['step'] ?? null );
		}
		if ( 'range' === $type ) {
			$field['min']  = null === $field['min'] ? 0.0 : $field['min'];
			$field['max']  = null === $field['max'] ? 100.0 : $field['max'];
			$field['unit'] = self::text( $raw['unit'] ?? '', 12 );
		}
		if ( 'number' === $type ) {
			$field['unit'] = self::text( $raw['unit'] ?? '', 12 );
		}
		if ( 'text' === $kind ) {
			$field['maxlength'] = max( 0, min( 5000, (int) ( $raw['maxlength'] ?? 0 ) ) );
			$field['minlength'] = max( 0, min( 5000, (int) ( $raw['minlength'] ?? 0 ) ) );
		}
		if ( 'date' === $kind ) {
			$field['date_min']      = self::int_or_null( $raw['date_min'] ?? null );
			$field['date_max']      = self::int_or_null( $raw['date_max'] ?? null );
			$field['disabled_days'] = array_values( array_unique( array_filter( array_map( 'intval', (array) ( $raw['disabled_days'] ?? array() ) ), static fn( $d ) => $d >= 0 && $d <= 6 ) ) );
		}
		if ( 'file' === $type ) {
			$accept = strtolower( (string) ( $raw['accept'] ?? 'jpg,jpeg,png,webp' ) );
			$exts   = array_values( array_intersect( array_map( 'trim', explode( ',', $accept ) ), Upload_Rules::allowed_extensions() ) );

			$field['accept']      = implode( ',', $exts ? $exts : array( 'jpg', 'jpeg', 'png' ) );
			$field['max_files']   = max( 1, min( 10, (int) ( $raw['max_files'] ?? 1 ) ) );
			$field['max_size']    = max( 0.1, min( 64, round( (float) ( $raw['max_size'] ?? 5 ), 1 ) ) );
			$field['min_width']   = max( 0, min( 20000, (int) ( $raw['min_width'] ?? 0 ) ) );
			$field['min_height']  = max( 0, min( 20000, (int) ( $raw['min_height'] ?? 0 ) ) );
			$field['vision']      = ! empty( $raw['vision'] );
			$field['vision_rule'] = self::text( $raw['vision_rule'] ?? '', 300, true );
		}
		if ( 'repeater' === $type ) {
			$field['subfields']    = self::normalize_list( $raw['subfields'] ?? array(), 1 );
			$field['min_rows']     = max( 0, min( 50, (int) ( $raw['min_rows'] ?? 1 ) ) );
			$field['max_rows']     = max( 1, min( 50, (int) ( $raw['max_rows'] ?? 5 ) ) );
			$field['button_label'] = self::text( $raw['button_label'] ?? '', 60 );
			if ( $field['max_rows'] < $field['min_rows'] ) {
				$field['max_rows'] = $field['min_rows'];
			}
		}
		if ( in_array( $type, array( 'section', 'paragraph', 'step' ), true ) ) {
			$field['description'] = wp_kses_post( mb_substr( (string) ( $raw['description'] ?? '' ), 0, 2000 ) );
			$field['collapsible'] = 'section' === $type && ! empty( $raw['collapsible'] );
		}
		if ( 'hidden' === $type || 'color' === $type ) {
			$field['default'] = 'color' === $type ? (string) sanitize_hex_color( $field['default'] ) : $field['default'];
		}
		return $field;
	}

	/**
	 * Options list.
	 *
	 * @param mixed $raw Raw options.
	 * @return array
	 */
	private static function options( $raw ) {
		$out    = array();
		$ids    = array();
		$values = array();
		foreach ( array_slice( is_array( $raw ) ? array_values( $raw ) : array(), 0, self::MAX_OPTIONS ) as $opt ) {
			if ( is_string( $opt ) ) {
				$opt = array( 'label' => $opt );
			}
			if ( ! is_array( $opt ) ) {
				continue;
			}
			$label = self::text( $opt['label'] ?? '', 120 );
			if ( '' === $label ) {
				continue;
			}
			$id = isset( $opt['id'] ) && preg_match( '/^[a-z][a-z0-9_]{1,30}$/', (string) $opt['id'] ) && ! isset( $ids[ $opt['id'] ] ) ? (string) $opt['id'] : self::new_id( 'o' );
			while ( isset( $ids[ $id ] ) ) {
				$id = self::new_id( 'o' );
			}
			$ids[ $id ] = true;
			$value      = isset( $opt['value'] ) && '' !== (string) $opt['value'] ? self::text( $opt['value'], 80 ) : sanitize_title( $label );
			if ( '' === $value ) {
				$value = $id;
			}
			$base = $value;
			$n    = 2;
			while ( isset( $values[ $value ] ) ) {
				$value = $base . '-' . $n;
				++$n;
			}
			$values[ $value ] = true;
			$ptype            = isset( $opt['price_type'] ) && in_array( $opt['price_type'], Field_Types::OPTION_PRICE_TYPES, true ) ? $opt['price_type'] : 'flat';
			$out[]            = array(
				'id'         => $id,
				'label'      => $label,
				'value'      => $value,
				'price'      => self::money( $opt['price'] ?? 0 ),
				'price_type' => $ptype,
				'image'      => isset( $opt['image'] ) ? esc_url_raw( (string) $opt['image'] ) : '',
				'color'      => isset( $opt['color'] ) ? (string) sanitize_hex_color( (string) $opt['color'] ) : '',
				'default'    => ! empty( $opt['default'] ),
			);
		}
		return $out;
	}

	/**
	 * Quantity tiers ( product quantity => price per item ), sorted by min.
	 *
	 * @param mixed $raw Raw tiers.
	 * @return array
	 */
	private static function tiers( $raw ) {
		$out = array();
		foreach ( array_slice( is_array( $raw ) ? array_values( $raw ) : array(), 0, 20 ) as $tier ) {
			if ( ! is_array( $tier ) ) {
				continue;
			}
			$out[] = array(
				'min'   => max( 1, (int) ( $tier['min'] ?? 1 ) ),
				'price' => self::money( $tier['price'] ?? 0 ),
			);
		}
		usort( $out, static fn( $a, $b ) => $a['min'] <=> $b['min'] );
		return $out;
	}

	/**
	 * Conditions block.
	 *
	 * @param mixed $raw Raw.
	 * @return array
	 */
	private static function conditions( $raw ) {
		$raw   = is_array( $raw ) ? $raw : array();
		$rules = array();
		foreach ( array_slice( isset( $raw['rules'] ) && is_array( $raw['rules'] ) ? $raw['rules'] : array(), 0, 20 ) as $rule ) {
			if ( ! is_array( $rule ) || empty( $rule['field'] ) ) {
				continue;
			}
			$op      = isset( $rule['op'] ) ? (string) $rule['op'] : (string) ( $rule['operator'] ?? 'is' );
			$rules[] = array(
				'field' => self::text( $rule['field'], 120 ),
				'op'    => in_array( $op, Field_Types::OPERATORS, true ) ? $op : 'is',
				'value' => self::text( is_scalar( $rule['value'] ?? '' ) ? ( $rule['value'] ?? '' ) : '', 120 ),
			);
		}
		return array(
			'enabled' => ! empty( $rules ) && ( ! isset( $raw['enabled'] ) || ! empty( $raw['enabled'] ) ),
			'action'  => isset( $raw['action'] ) && 'hide' === $raw['action'] ? 'hide' : 'show',
			'match'   => isset( $raw['match'] ) && 'any' === $raw['match'] ? 'any' : 'all',
			'rules'   => $rules,
		);
	}

	/**
	 * Translations: locale => { label, help, tooltip, placeholder, options{ id: label } }.
	 *
	 * @param mixed $raw Raw.
	 * @return array
	 */
	private static function i18n( $raw ) {
		$out = array();
		if ( ! is_array( $raw ) ) {
			return $out;
		}
		foreach ( array_slice( $raw, 0, 30, true ) as $locale => $t ) {
			$locale = preg_replace( '/[^A-Za-z_]/', '', (string) $locale );
			if ( '' === $locale || ! is_array( $t ) ) {
				continue;
			}
			$entry = array();
			foreach ( array(
				'label'       => 120,
				'help'        => 300,
				'tooltip'     => 300,
				'placeholder' => 120,
			) as $k => $max ) {
				if ( isset( $t[ $k ] ) && '' !== trim( (string) $t[ $k ] ) ) {
					$entry[ $k ] = self::text( $t[ $k ], $max, 'help' === $k || 'tooltip' === $k );
				}
			}
			if ( isset( $t['options'] ) && is_array( $t['options'] ) ) {
				foreach ( array_slice( $t['options'], 0, self::MAX_OPTIONS, true ) as $oid => $olabel ) {
					if ( preg_match( '/^[a-z][a-z0-9_]{1,30}$/', (string) $oid ) && is_scalar( $olabel ) && '' !== trim( (string) $olabel ) ) {
						$entry['options'][ $oid ] = self::text( $olabel, 120 );
					}
				}
			}
			if ( $entry ) {
				$out[ $locale ] = $entry;
			}
		}
		return $out;
	}

	/**
	 * Resolves condition field refs (id, key or label) to ids and option refs to option ids.
	 *
	 * @param array $fields Fields.
	 * @return array
	 */
	private static function resolve_references( array $fields ) {
		$by_ref = array();
		foreach ( $fields as $f ) {
			$by_ref[ $f['id'] ]                             = $f;
			$by_ref[ 'key:' . $f['key'] ]                   = $f;
			$by_ref[ 'label:' . strtolower( $f['label'] ) ] = $f;
		}
		foreach ( $fields as &$field ) {
			foreach ( $field['conditions']['rules'] as &$rule ) {
				$ref    = $rule['field'];
				$target = $by_ref[ $ref ] ?? ( $by_ref[ 'key:' . sanitize_key( $ref ) ] ?? ( $by_ref[ 'label:' . strtolower( $ref ) ] ?? null ) );
				if ( $target ) {
					$rule['field'] = $target['id'];
					if ( ! empty( $target['options'] ) && '' !== $rule['value'] ) {
						foreach ( $target['options'] as $opt ) {
							if ( in_array( strtolower( $rule['value'] ), array( $opt['id'], strtolower( $opt['value'] ), strtolower( $opt['label'] ) ), true ) ) {
								$rule['value'] = $opt['id'];
								break;
							}
						}
					}
				}
			}
			unset( $rule );
		}
		unset( $field );
		return $fields;
	}

	/**
	 * Validates a raw AI option set against the generation schema (structure, enums, lengths).
	 *
	 * @param mixed $data Decoded AI JSON.
	 * @return string[] Problems (empty = valid).
	 */
	public static function validate_generated( $data ) {
		$errors = array();
		if ( ! is_array( $data ) || ! isset( $data['fields'] ) || ! is_array( $data['fields'] ) ) {
			return array( 'fields: missing array' );
		}
		if ( count( $data['fields'] ) > 40 ) {
			$errors[] = 'fields: more than 40 items';
		}
		$keys = array();
		foreach ( $data['fields'] as $i => $f ) {
			$at = "fields[{$i}]";
			if ( ! is_array( $f ) ) {
				$errors[] = "{$at}: not an object";
				continue;
			}
			foreach ( array( 'key', 'type', 'label' ) as $req ) {
				if ( ! isset( $f[ $req ] ) || ! is_string( $f[ $req ] ) || '' === trim( $f[ $req ] ) ) {
					$errors[] = "{$at}.{$req}: required string";
				}
			}
			if ( isset( $f['type'] ) && ! in_array( $f['type'], \Dev3\AiProductOptions\Ai\Prompt_Library::GENERATED_TYPES, true ) ) {
				$errors[] = "{$at}.type: not allowed ({$f['type']})";
			}
			if ( isset( $f['price_type'] ) && ! in_array( $f['price_type'], array( 'none', 'flat', 'percent', 'per_char', 'per_unit', 'formula' ), true ) ) {
				$errors[] = "{$at}.price_type: not allowed";
			}
			if ( isset( $f['label'] ) && is_string( $f['label'] ) && mb_strlen( $f['label'] ) > 80 ) {
				$errors[] = "{$at}.label: longer than 80";
			}
			if ( isset( $f['price'] ) && ! is_numeric( $f['price'] ) ) {
				$errors[] = "{$at}.price: not a number";
			}
			if ( isset( $f['key'] ) && is_string( $f['key'] ) ) {
				if ( isset( $keys[ $f['key'] ] ) ) {
					$errors[] = "{$at}.key: duplicate";
				}
				$keys[ $f['key'] ] = true;
			}
			if ( isset( $f['type'] ) && Field_Types::exists( (string) $f['type'] ) && Field_Types::has_options( (string) $f['type'] ) && empty( $f['options'] ) ) {
				$errors[] = "{$at}.options: required for {$f['type']}";
			}
			foreach ( isset( $f['options'] ) && is_array( $f['options'] ) ? $f['options'] : array() as $j => $o ) {
				if ( ! is_array( $o ) || ! isset( $o['label'] ) || ! is_string( $o['label'] ) || '' === $o['label'] ) {
					$errors[] = "{$at}.options[{$j}].label: required string";
				} elseif ( isset( $o['price'] ) && ! is_numeric( $o['price'] ) ) {
					$errors[] = "{$at}.options[{$j}].price: not a number";
				}
			}
			if ( isset( $f['condition'] ) && is_array( $f['condition'] ) ) {
				foreach ( isset( $f['condition']['rules'] ) && is_array( $f['condition']['rules'] ) ? $f['condition']['rules'] : array() as $j => $r ) {
					if ( ! isset( $r['operator'] ) || ! in_array( $r['operator'], Field_Types::OPERATORS, true ) ) {
						$errors[] = "{$at}.condition.rules[{$j}].operator: not allowed";
					}
				}
			}
		}
		return $errors;
	}

	/**
	 * Public (storefront) copy of fields: drops admin-only keys.
	 *
	 * @param array $fields Fields.
	 * @return array
	 */
	public static function public_fields( array $fields ) {
		return array_map(
			static function ( $f ) {
				unset( $f['i18n'], $f['vision_rule'], $f['class'] );
				if ( ! empty( $f['subfields'] ) ) {
					$f['subfields'] = self::public_fields( $f['subfields'] );
				}
				return $f;
			},
			$fields
		);
	}

	/**
	 * Random id like f_3k9x2m1a.
	 *
	 * @param string $prefix f|o.
	 * @return string
	 */
	public static function new_id( $prefix ) {
		return $prefix . '_' . strtolower( wp_generate_password( 8, false, false ) );
	}

	/**
	 * Plain text with max length.
	 *
	 * @param mixed $value     Value.
	 * @param int   $max       Max chars.
	 * @param bool  $multiline Keep newlines.
	 * @return string
	 */
	private static function text( $value, $max, $multiline = false ) {
		$value = is_scalar( $value ) ? (string) $value : '';
		$value = $multiline ? sanitize_textarea_field( $value ) : sanitize_text_field( $value );
		return mb_substr( $value, 0, $max );
	}

	/**
	 * Formula text: only the characters the formula grammar uses (keeps < and >, which text sanitising would strip).
	 *
	 * @param mixed $value Raw.
	 * @return string
	 */
	private static function formula( $value ) {
		$value = is_scalar( $value ) ? (string) $value : '';
		return substr( trim( (string) preg_replace( '/[^A-Za-z0-9_{}.\s+\-*\/%^()<>=!&|,]/', '', $value ) ), 0, Formula::MAX_LENGTH );
	}

	/**
	 * Money (can be negative for discounts), 4 decimals.
	 *
	 * @param mixed $value Value.
	 * @return float
	 */
	private static function money( $value ) {
		return is_numeric( $value ) ? round( max( -1000000, min( 1000000, (float) $value ) ), 4 ) : 0.0;
	}

	/**
	 * Number or null.
	 *
	 * @param mixed $value Value.
	 * @return float|null
	 */
	private static function num_or_null( $value ) {
		return ( null === $value || '' === $value || ! is_numeric( $value ) ) ? null : (float) $value;
	}

	/**
	 * Int or null.
	 *
	 * @param mixed $value Value.
	 * @return int|null
	 */
	private static function int_or_null( $value ) {
		return ( null === $value || '' === $value || ! is_numeric( $value ) ) ? null : (int) $value;
	}
}
