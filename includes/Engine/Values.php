<?php
/**
 * Parses, validates and displays shopper input.
 *
 * @package Dev3\AiProductOptions
 */

namespace Dev3\AiProductOptions\Engine;

use Dev3\AiProductOptions\Fields\Field_Types;

defined( 'ABSPATH' ) || exit;

/**
 * Value helpers. Parsed value shapes by kind:
 * text/date/color/hidden: string · number: float|null · choice: option id|'' · multi: option ids ·
 * bool: bool · file: [ { token, name } ] · repeater: [ [ sub id => value ] ].
 */
class Values {

	/**
	 * Parses raw POST input for a list of fields.
	 *
	 * @param array         $fields Fields.
	 * @param array         $raw    Raw input keyed by field id (already unslashed).
	 * @param callable|null $files  fn( string $token, array $field ): array|null resolving upload tokens.
	 * @return array id => value
	 */
	public static function parse_all( array $fields, array $raw, $files = null ) {
		$out = array();
		foreach ( $fields as $f ) {
			if ( Field_Types::is_input( $f['type'] ) ) {
				$out[ $f['id'] ] = self::parse( $f, $raw[ $f['id'] ] ?? null, $files );
			}
		}
		return $out;
	}

	/**
	 * Parses one value.
	 *
	 * @param array         $f     Field.
	 * @param mixed         $raw   Raw.
	 * @param callable|null $files Upload resolver.
	 * @return mixed
	 */
	public static function parse( array $f, $raw, $files = null ) {
		switch ( Field_Types::kind( $f['type'] ) ) {
			case 'text':
				if ( ! is_scalar( $raw ) ) {
					return '';
				}
				return trim( 'textarea' === $f['type'] ? sanitize_textarea_field( (string) $raw ) : sanitize_text_field( (string) $raw ) );
			case 'number':
				if ( ! is_scalar( $raw ) || '' === trim( (string) $raw ) || ! is_numeric( trim( (string) $raw ) ) ) {
					return null;
				}
				return (float) trim( (string) $raw );
			case 'choice':
				return self::option_id( $f, is_scalar( $raw ) ? (string) $raw : '' );
			case 'multi':
				$ids = array();
				foreach ( (array) $raw as $item ) {
					$id = is_scalar( $item ) ? self::option_id( $f, (string) $item ) : '';
					if ( '' !== $id && ! in_array( $id, $ids, true ) ) {
						$ids[] = $id;
					}
				}
				return $ids;
			case 'bool':
				return is_scalar( $raw ) && '' !== (string) $raw && '0' !== (string) $raw;
			case 'date':
				return is_scalar( $raw ) ? self::date_value( $f['type'], (string) $raw ) : '';
			case 'color':
				return is_scalar( $raw ) ? (string) sanitize_hex_color( trim( (string) $raw ) ) : '';
			case 'hidden':
				return (string) $f['default'];
			case 'file':
				$list = array();
				if ( is_scalar( $raw ) && $files ) {
					foreach ( array_slice( array_filter( array_map( 'trim', explode( ',', (string) $raw ) ) ), 0, 10 ) as $token ) {
						$file = call_user_func( $files, preg_replace( '/[^A-Za-z0-9]/', '', $token ), $f );
						if ( is_array( $file ) ) {
							$list[] = $file;
						}
					}
				}
				return $list;
			case 'repeater':
				$rows = array();
				foreach ( array_slice( is_array( $raw ) ? array_values( $raw ) : array(), 0, (int) $f['max_rows'] ) as $row ) {
					$parsed = array();
					foreach ( $f['subfields'] as $sub ) {
						$parsed[ $sub['id'] ] = self::parse( $sub, is_array( $row ) ? ( $row[ $sub['id'] ] ?? null ) : null, $files );
					}
					$rows[] = $parsed;
				}
				return $rows;
		}
		return null;
	}

	/**
	 * Resolves a posted option id or value to an option id.
	 *
	 * @param array  $f   Field.
	 * @param string $raw Raw.
	 * @return string
	 */
	private static function option_id( array $f, $raw ) {
		foreach ( $f['options'] ?? array() as $opt ) {
			if ( $raw === $opt['id'] || $raw === $opt['value'] ) {
				return $opt['id'];
			}
		}
		return '';
	}

	/**
	 * Strict date / time values.
	 *
	 * @param string $type date|time|datetime.
	 * @param string $raw  Raw.
	 * @return string
	 */
	private static function date_value( $type, $raw ) {
		$raw      = trim( $raw );
		$patterns = array(
			'date'     => '/^\d{4}-\d{2}-\d{2}$/',
			'time'     => '/^\d{2}:\d{2}$/',
			'datetime' => '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/',
		);
		if ( ! preg_match( $patterns[ $type ], $raw ) ) {
			return '';
		}
		if ( 'time' !== $type ) {
			$parts = array_map( 'intval', explode( '-', substr( $raw, 0, 10 ) ) );
			if ( ! checkdate( $parts[1], $parts[2], $parts[0] ) ) {
				return '';
			}
		}
		return $raw;
	}

	/**
	 * Empty value of a field's kind.
	 *
	 * @param array $f Field.
	 * @return mixed
	 */
	public static function empty_value( array $f ) {
		switch ( Field_Types::kind( $f['type'] ) ) {
			case 'number':
				return null;
			case 'multi':
			case 'file':
			case 'repeater':
				return array();
			case 'bool':
				return false;
		}
		return '';
	}

	/**
	 * Whether a value is empty.
	 *
	 * @param array $f     Field.
	 * @param mixed $value Value.
	 * @return bool
	 */
	public static function is_empty( array $f, $value ) {
		if ( 'hidden' === $f['type'] ) {
			return '' === (string) $value;
		}
		if ( is_array( $value ) ) {
			return 0 === count( $value );
		}
		if ( is_bool( $value ) ) {
			return ! $value;
		}
		return null === $value || '' === (string) $value;
	}

	/**
	 * Numeric value used by formulas and numeric conditions.
	 *
	 * @param array $f     Field.
	 * @param mixed $value Value.
	 * @return float
	 */
	public static function numeric( array $f, $value ) {
		switch ( Field_Types::kind( $f['type'] ) ) {
			case 'number':
				return null === $value ? 0.0 : (float) $value;
			case 'bool':
				return $value ? 1.0 : 0.0;
			case 'multi':
			case 'file':
			case 'repeater':
				return (float) count( (array) $value );
			case 'choice':
				foreach ( $f['options'] as $opt ) {
					if ( $opt['id'] === $value ) {
						return is_numeric( $opt['value'] ) ? (float) $opt['value'] : 0.0;
					}
				}
				return 0.0;
			case 'text':
			case 'hidden':
				return is_numeric( trim( (string) $value ) ) ? (float) trim( (string) $value ) : 0.0;
		}
		return 0.0;
	}

	/**
	 * Character count used by per-character pricing and {len}.
	 *
	 * @param array $f     Field.
	 * @param mixed $value Value.
	 * @return int
	 */
	public static function chars( array $f, $value ) {
		if ( ! in_array( Field_Types::kind( $f['type'] ), array( 'text', 'hidden' ), true ) ) {
			return 0;
		}
		$text = (string) $value;
		if ( empty( $f['count_space'] ) ) {
			$text = preg_replace( '/[ \t\n\r\f\v]+/', '', $text );
		}
		return mb_strlen( $text );
	}

	/**
	 * Word count.
	 *
	 * @param mixed $value Value.
	 * @return int
	 */
	public static function words( $value ) {
		if ( ! is_string( $value ) ) {
			return 0;
		}
		return count( preg_split( '/[ \t\n\r\f\v]+/', trim( $value ), -1, PREG_SPLIT_NO_EMPTY ) );
	}

	/**
	 * Line count.
	 *
	 * @param mixed $value Value.
	 * @return int
	 */
	public static function lines( $value ) {
		if ( ! is_string( $value ) || '' === trim( $value ) ) {
			return 0;
		}
		return count( explode( "\n", str_replace( "\r", '', trim( $value ) ) ) );
	}

	/**
	 * Plain string of a value (for text conditions).
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	public static function to_string( $value ) {
		if ( is_array( $value ) ) {
			return '';
		}
		if ( is_bool( $value ) ) {
			return $value ? '1' : '';
		}
		if ( is_float( $value ) ) {
			return rtrim( rtrim( number_format( $value, 6, '.', '' ), '0' ), '.' );
		}
		return (string) $value;
	}

	/**
	 * Validates visible fields. Returns messages keyed by field id.
	 *
	 * @param array $fields  Fields.
	 * @param array $values  Values.
	 * @param array $visible Visibility map.
	 * @return array
	 */
	public static function validate( array $fields, array $values, array $visible ) {
		$errors = array();
		foreach ( $fields as $f ) {
			if ( ! Field_Types::is_input( $f['type'] ) || empty( $visible[ $f['id'] ] ) ) {
				continue;
			}
			$message = self::validate_one( $f, $values[ $f['id'] ] ?? self::empty_value( $f ) );
			if ( $message ) {
				$errors[ $f['id'] ] = $message;
			}
		}
		return $errors;
	}

	/**
	 * Validates one value.
	 *
	 * @param array $f     Field.
	 * @param mixed $value Value.
	 * @return string Message or ''.
	 */
	public static function validate_one( array $f, $value ) {
		$label = '' !== $f['label'] ? $f['label'] : __( 'This option', 'dev3-ai-product-options-for-woocommerce' );
		if ( self::is_empty( $f, $value ) ) {
			/* translators: %s: field label */
			return ! empty( $f['required'] ) ? sprintf( __( '%s is required.', 'dev3-ai-product-options-for-woocommerce' ), $label ) : '';
		}
		$kind = Field_Types::kind( $f['type'] );
		if ( 'text' === $kind ) {
			$len = mb_strlen( (string) $value );
			if ( ! empty( $f['maxlength'] ) && $len > $f['maxlength'] ) {
				/* translators: 1: field label, 2: number */
				return sprintf( __( '%1$s can be at most %2$d characters.', 'dev3-ai-product-options-for-woocommerce' ), $label, $f['maxlength'] );
			}
			if ( ! empty( $f['minlength'] ) && $len < $f['minlength'] ) {
				/* translators: 1: field label, 2: number */
				return sprintf( __( '%1$s needs at least %2$d characters.', 'dev3-ai-product-options-for-woocommerce' ), $label, $f['minlength'] );
			}
			if ( 'email' === $f['type'] && ! is_email( $value ) ) {
				/* translators: %s: field label */
				return sprintf( __( '%s must be a valid email address.', 'dev3-ai-product-options-for-woocommerce' ), $label );
			}
			if ( 'url' === $f['type'] && ! wp_http_validate_url( $value ) ) {
				/* translators: %s: field label */
				return sprintf( __( '%s must be a valid URL.', 'dev3-ai-product-options-for-woocommerce' ), $label );
			}
			if ( 'tel' === $f['type'] && ! preg_match( '/^[0-9 +().\-]{4,30}$/', (string) $value ) ) {
				/* translators: %s: field label */
				return sprintf( __( '%s must be a valid phone number.', 'dev3-ai-product-options-for-woocommerce' ), $label );
			}
		}
		if ( 'number' === $kind ) {
			if ( null !== ( $f['min'] ?? null ) && $value < $f['min'] - 1e-9 ) {
				/* translators: 1: field label, 2: number */
				return sprintf( __( '%1$s must be at least %2$s.', 'dev3-ai-product-options-for-woocommerce' ), $label, self::to_string( (float) $f['min'] ) );
			}
			if ( null !== ( $f['max'] ?? null ) && $value > $f['max'] + 1e-9 ) {
				/* translators: 1: field label, 2: number */
				return sprintf( __( '%1$s must be at most %2$s.', 'dev3-ai-product-options-for-woocommerce' ), $label, self::to_string( (float) $f['max'] ) );
			}
			if ( ! empty( $f['step'] ) && $f['step'] > 0 ) {
				$steps = ( $value - (float) ( $f['min'] ?? 0 ) ) / $f['step'];
				if ( abs( $steps - round( $steps ) ) > 1e-6 ) {
					/* translators: 1: field label, 2: number */
					return sprintf( __( '%1$s must be in steps of %2$s.', 'dev3-ai-product-options-for-woocommerce' ), $label, self::to_string( (float) $f['step'] ) );
				}
			}
		}
		if ( 'multi' === $kind && ! empty( $f['max_select'] ) && count( $value ) > $f['max_select'] ) {
			/* translators: 1: number, 2: field label */
			return sprintf( __( 'Choose at most %1$d for %2$s.', 'dev3-ai-product-options-for-woocommerce' ), $f['max_select'], $label );
		}
		if ( 'date' === $kind && 'time' !== $f['type'] ) {
			$day   = substr( (string) $value, 0, 10 );
			$today = current_time( 'Y-m-d' );
			if ( null !== ( $f['date_min'] ?? null ) && $day < gmdate( 'Y-m-d', strtotime( $today . ' ' . (int) $f['date_min'] . ' days' ) ) ) {
				/* translators: %s: field label */
				return sprintf( __( '%s is too early.', 'dev3-ai-product-options-for-woocommerce' ), $label );
			}
			if ( null !== ( $f['date_max'] ?? null ) && $day > gmdate( 'Y-m-d', strtotime( $today . ' ' . (int) $f['date_max'] . ' days' ) ) ) {
				/* translators: %s: field label */
				return sprintf( __( '%s is too late.', 'dev3-ai-product-options-for-woocommerce' ), $label );
			}
			if ( ! empty( $f['disabled_days'] ) && in_array( (int) gmdate( 'w', strtotime( $day ) ), $f['disabled_days'], true ) ) {
				/* translators: %s: field label */
				return sprintf( __( '%s is not available on that day.', 'dev3-ai-product-options-for-woocommerce' ), $label );
			}
		}
		if ( 'file' === $kind && count( $value ) > (int) $f['max_files'] ) {
			/* translators: 1: number, 2: field label */
			return sprintf( __( 'Upload at most %1$d files for %2$s.', 'dev3-ai-product-options-for-woocommerce' ), $f['max_files'], $label );
		}
		if ( 'repeater' === $kind ) {
			if ( count( $value ) < (int) $f['min_rows'] ) {
				/* translators: 1: number, 2: field label */
				return sprintf( __( 'Add at least %1$d entries for %2$s.', 'dev3-ai-product-options-for-woocommerce' ), $f['min_rows'], $label );
			}
			foreach ( $value as $row ) {
				foreach ( $f['subfields'] as $sub ) {
					if ( Field_Types::is_input( $sub['type'] ) ) {
						$message = self::validate_one( $sub, $row[ $sub['id'] ] ?? self::empty_value( $sub ) );
						if ( $message ) {
							return $message;
						}
					}
				}
			}
		}
		return '';
	}

	/**
	 * Display string for cart, order and email.
	 *
	 * @param array $f     Field.
	 * @param mixed $value Value.
	 * @return string
	 */
	public static function display( array $f, $value ) {
		switch ( Field_Types::kind( $f['type'] ) ) {
			case 'number':
				return null === $value ? '' : trim( self::to_string( (float) $value ) . ' ' . ( $f['unit'] ?? '' ) );
			case 'choice':
				foreach ( $f['options'] as $opt ) {
					if ( $opt['id'] === $value ) {
						return $opt['label'];
					}
				}
				return '';
			case 'multi':
				$labels = array();
				foreach ( $f['options'] as $opt ) {
					if ( in_array( $opt['id'], (array) $value, true ) ) {
						$labels[] = $opt['label'];
					}
				}
				return implode( ', ', $labels );
			case 'bool':
				return $value ? __( 'Yes', 'dev3-ai-product-options-for-woocommerce' ) : '';
			case 'date':
				if ( '' === $value ) {
					return '';
				}
				if ( 'time' === $f['type'] ) {
					return (string) $value;
				}
				$ts = strtotime( str_replace( 'T', ' ', (string) $value ) );
				return 'datetime' === $f['type'] ? date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $ts ) : date_i18n( get_option( 'date_format' ), $ts );
			case 'file':
				return implode( ', ', wp_list_pluck( (array) $value, 'name' ) );
			case 'repeater':
				$rows = array();
				foreach ( (array) $value as $i => $row ) {
					$parts = array();
					foreach ( $f['subfields'] as $sub ) {
						$shown = self::display( $sub, $row[ $sub['id'] ] ?? self::empty_value( $sub ) );
						if ( '' !== $shown ) {
							$parts[] = ( '' !== $sub['label'] ? $sub['label'] . ': ' : '' ) . $shown;
						}
					}
					/* translators: 1: row number, 2: values */
					$rows[] = sprintf( __( '#%1$d %2$s', 'dev3-ai-product-options-for-woocommerce' ), $i + 1, implode( ', ', $parts ) );
				}
				return implode( '; ', $rows );
		}
		return self::to_string( $value );
	}
}
