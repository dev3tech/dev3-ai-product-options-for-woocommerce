<?php
/**
 * Safe price-formula engine (no eval). The same grammar is implemented in src/shared/formula.js.
 *
 * Grammar:
 *   expr    := or
 *   or      := and ( "||" and )*
 *   and     := cmp ( "&&" cmp )*
 *   cmp     := add ( ( "==" | "!=" | ">=" | "<=" | ">" | "<" ) add )?
 *   add     := mul ( ( "+" | "-" ) mul )*
 *   mul     := unary ( ( "*" | "/" | "%" ) unary )*
 *   unary   := ( "-" | "+" | "!" ) unary | power
 *   power   := primary ( "^" unary )?
 *   primary := number | "{" name ( "." prop )? "}" | func "(" args ")" | "(" expr ")"
 *
 * Variables: {price} (product unit price), {qty}, {value}, {len}, {words}, {lines}, {count}, and
 * {<field key>} / {<field key>.len} / {<field key>.count} for other fields. Division by zero gives 0.
 *
 * @package Dev3\AiProductOptions
 */

namespace Dev3\AiProductOptions\Engine;

defined( 'ABSPATH' ) || exit;

/**
 * Tokenizer, parser and evaluator.
 */
class Formula {

	const FUNCTIONS = array(
		'min'   => array( 1, 20 ),
		'max'   => array( 1, 20 ),
		'round' => array( 1, 2 ),
		'ceil'  => array( 1, 1 ),
		'floor' => array( 1, 1 ),
		'abs'   => array( 1, 1 ),
		'sqrt'  => array( 1, 1 ),
		'pow'   => array( 2, 2 ),
		'if'    => array( 3, 3 ),
		'clamp' => array( 3, 3 ),
	);

	const MAX_LENGTH = 500;

	/**
	 * Compiled ASTs by source.
	 *
	 * @var array
	 */
	private static $memo = array();

	/**
	 * Tokens of the expression being parsed.
	 *
	 * @var array
	 */
	private $tokens = array();

	/**
	 * Cursor.
	 *
	 * @var int
	 */
	private $pos = 0;

	/**
	 * Compiles a formula.
	 *
	 * @param string $source Formula.
	 * @return array { ok: bool, ast?: array, error?: string, vars?: string[] }
	 * @throws \InvalidArgumentException Never: parse errors are caught and returned as `error`.
	 */
	public static function compile( $source ) {
		$source = trim( (string) $source );
		if ( isset( self::$memo[ $source ] ) ) {
			return self::$memo[ $source ];
		}
		if ( '' === $source ) {
			return array(
				'ok'    => false,
				'error' => 'empty',
			);
		}
		if ( strlen( $source ) > self::MAX_LENGTH ) {
			return array(
				'ok'    => false,
				'error' => 'too_long',
			);
		}
		$parser = new self();
		try {
			$parser->tokens = self::tokenize( $source );
			$parser->pos    = 0;
			$ast            = $parser->parse_or();
			if ( $parser->pos < count( $parser->tokens ) ) {
				throw new \InvalidArgumentException( 'unexpected:' . $parser->tokens[ $parser->pos ][1] ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal parse error code, never output.
			}
			$vars   = array();
			$result = array(
				'ok'   => true,
				'ast'  => $ast,
				'vars' => array_values( array_unique( self::collect_vars( $ast, $vars ) ) ),
			);
		} catch ( \InvalidArgumentException $e ) {
			$result = array(
				'ok'    => false,
				'error' => $e->getMessage(),
			);
		}
		if ( count( self::$memo ) > 200 ) {
			self::$memo = array();
		}
		self::$memo[ $source ] = $result;
		return $result;
	}

	/**
	 * Evaluates a formula with variables. Invalid formulas evaluate to 0.
	 *
	 * @param string $source Formula.
	 * @param array  $vars   name => number.
	 * @return float
	 */
	public static function evaluate( $source, array $vars ) {
		$compiled = self::compile( $source );
		if ( ! $compiled['ok'] ) {
			return 0.0;
		}
		$value = self::run( $compiled['ast'], $vars );
		return is_finite( $value ) ? (float) $value : 0.0;
	}

	/**
	 * Human-readable message for a compile error code.
	 *
	 * @param string $error Code.
	 * @return string
	 */
	public static function error_message( $error ) {
		$parts = explode( ':', (string) $error, 2 );
		switch ( $parts[0] ) {
			case 'empty':
				return __( 'The formula is empty.', 'dev3-ai-product-options-for-woocommerce' );
			case 'too_long':
				return __( 'The formula is too long (500 characters max).', 'dev3-ai-product-options-for-woocommerce' );
			case 'char':
				/* translators: %s: character */
				return sprintf( __( 'Unexpected character "%s".', 'dev3-ai-product-options-for-woocommerce' ), $parts[1] ?? '' );
			case 'func':
				/* translators: %s: function name */
				return sprintf( __( 'Unknown function "%s".', 'dev3-ai-product-options-for-woocommerce' ), $parts[1] ?? '' );
			case 'arity':
				/* translators: %s: function name */
				return sprintf( __( 'Wrong number of arguments for "%s".', 'dev3-ai-product-options-for-woocommerce' ), $parts[1] ?? '' );
			case 'var':
				return __( 'Variables are written in braces, e.g. {len}.', 'dev3-ai-product-options-for-woocommerce' );
			case 'end':
				return __( 'The formula ends too early.', 'dev3-ai-product-options-for-woocommerce' );
			default:
				/* translators: %s: token */
				return sprintf( __( 'Unexpected "%s".', 'dev3-ai-product-options-for-woocommerce' ), $parts[1] ?? $error );
		}
	}

	/**
	 * Splits source into tokens: [ type, text ].
	 *
	 * @param string $src Source.
	 * @return array
	 * @throws \InvalidArgumentException On bad input.
	 */
	private static function tokenize( $src ) {
		$tokens = array();
		$len    = strlen( $src );
		$i      = 0;
		while ( $i < $len ) {
			$c = $src[ $i ];
			if ( ctype_space( $c ) ) {
				++$i;
				continue;
			}
			if ( ctype_digit( $c ) || ( '.' === $c && $i + 1 < $len && ctype_digit( $src[ $i + 1 ] ) ) ) {
				preg_match( '/\G\d*\.?\d+(?:[eE][+-]?\d+)?|\G\d+\.?/', $src, $m, 0, $i );
				$tokens[] = array( 'num', $m[0] );
				$i       += strlen( $m[0] );
				continue;
			}
			if ( '{' === $c ) {
				if ( ! preg_match( '/\G\{\s*([a-zA-Z_][a-zA-Z0-9_-]*)(?:\.([a-z]+))?\s*\}/', $src, $m, 0, $i ) ) {
					throw new \InvalidArgumentException( 'var' );
				}
				$tokens[] = array( 'var', strtolower( $m[1] ) . ( isset( $m[2] ) && '' !== $m[2] ? '.' . $m[2] : '' ) );
				$i       += strlen( $m[0] );
				continue;
			}
			if ( ctype_alpha( $c ) ) {
				preg_match( '/\G[a-zA-Z_][a-zA-Z0-9_]*/', $src, $m, 0, $i );
				$tokens[] = array( 'id', strtolower( $m[0] ) );
				$i       += strlen( $m[0] );
				continue;
			}
			$two = substr( $src, $i, 2 );
			if ( in_array( $two, array( '==', '!=', '>=', '<=', '&&', '||' ), true ) ) {
				$tokens[] = array( 'op', $two );
				$i       += 2;
				continue;
			}
			if ( false !== strpos( '+-*/%^()<>,!', $c ) ) {
				$tokens[] = array( 'op', $c );
				++$i;
				continue;
			}
			throw new \InvalidArgumentException( 'char:' . $c ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal parse error code, never output.
		}
		return $tokens;
	}

	/**
	 * Peeks the current operator.
	 *
	 * @param array $ops Accepted ops.
	 * @return string|null
	 */
	private function peek_op( array $ops ) {
		$t = $this->tokens[ $this->pos ] ?? null;
		return ( $t && 'op' === $t[0] && in_array( $t[1], $ops, true ) ) ? $t[1] : null;
	}

	/**
	 * Expects an operator.
	 *
	 * @param string $op Op.
	 * @throws \InvalidArgumentException When missing.
	 */
	private function expect( $op ) {
		$t = $this->tokens[ $this->pos ] ?? null;
		if ( ! $t ) {
			throw new \InvalidArgumentException( 'end' );
		}
		if ( 'op' !== $t[0] || $t[1] !== $op ) {
			throw new \InvalidArgumentException( 'unexpected:' . $t[1] ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal parse error code, never output.
		}
		++$this->pos;
	}

	/**
	 * Left-associative binary level.
	 *
	 * @param array  $ops  Operators.
	 * @param string $next Next level method.
	 * @return array
	 */
	private function binary( array $ops, $next ) {
		$left = $this->$next();
		$op   = $this->peek_op( $ops );
		while ( null !== $op ) {
			++$this->pos;
			$left = array( 'bin', $op, $left, $this->$next() );
			$op   = $this->peek_op( $ops );
		}
		return $left;
	}

	/**
	 * Or level.
	 *
	 * @return array
	 */
	private function parse_or() {
		return $this->binary( array( '||' ), 'parse_and' );
	}

	/**
	 * And level.
	 *
	 * @return array
	 */
	private function parse_and() {
		return $this->binary( array( '&&' ), 'parse_cmp' );
	}

	/**
	 * Comparison (non-associative).
	 *
	 * @return array
	 */
	private function parse_cmp() {
		$left = $this->parse_add();
		$op   = $this->peek_op( array( '==', '!=', '>=', '<=', '>', '<' ) );
		if ( null !== $op ) {
			++$this->pos;
			$left = array( 'bin', $op, $left, $this->parse_add() );
		}
		return $left;
	}

	/**
	 * Additive level.
	 *
	 * @return array
	 */
	private function parse_add() {
		return $this->binary( array( '+', '-' ), 'parse_mul' );
	}

	/**
	 * Multiplicative level.
	 *
	 * @return array
	 */
	private function parse_mul() {
		return $this->binary( array( '*', '/', '%' ), 'parse_unary' );
	}

	/**
	 * Unary level.
	 *
	 * @return array
	 */
	private function parse_unary() {
		$op = $this->peek_op( array( '-', '+', '!' ) );
		if ( null !== $op ) {
			++$this->pos;
			return array( 'un', $op, $this->parse_unary() );
		}
		return $this->parse_power();
	}

	/**
	 * Power (right-associative; binds tighter than unary minus on its left).
	 *
	 * @return array
	 */
	private function parse_power() {
		$base = $this->parse_primary();
		if ( null !== $this->peek_op( array( '^' ) ) ) {
			++$this->pos;
			return array( 'bin', '^', $base, $this->parse_unary() );
		}
		return $base;
	}

	/**
	 * Primary.
	 *
	 * @return array
	 * @throws \InvalidArgumentException On syntax errors.
	 */
	private function parse_primary() {
		$t = $this->tokens[ $this->pos ] ?? null;
		if ( ! $t ) {
			throw new \InvalidArgumentException( 'end' );
		}
		++$this->pos;
		if ( 'num' === $t[0] ) {
			return array( 'num', (float) $t[1] );
		}
		if ( 'var' === $t[0] ) {
			return array( 'var', $t[1] );
		}
		if ( 'id' === $t[0] ) {
			if ( ! isset( self::FUNCTIONS[ $t[1] ] ) ) {
				throw new \InvalidArgumentException( 'func:' . $t[1] ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal parse error code, never output.
			}
			$this->expect( '(' );
			$args = array();
			if ( null === $this->peek_op( array( ')' ) ) ) {
				$args[] = $this->parse_or();
				while ( null !== $this->peek_op( array( ',' ) ) ) {
					++$this->pos;
					$args[] = $this->parse_or();
				}
			}
			$this->expect( ')' );
			list( $min, $max ) = self::FUNCTIONS[ $t[1] ];
			if ( count( $args ) < $min || count( $args ) > $max ) {
				throw new \InvalidArgumentException( 'arity:' . $t[1] ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal parse error code, never output.
			}
			return array( 'fn', $t[1], $args );
		}
		if ( '(' === $t[1] ) {
			$inner = $this->parse_or();
			$this->expect( ')' );
			return $inner;
		}
		throw new \InvalidArgumentException( 'unexpected:' . $t[1] ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal parse error code, never output.
	}

	/**
	 * Collects variable names.
	 *
	 * @param array $node AST.
	 * @param array $out  Accumulator.
	 * @return array
	 */
	private static function collect_vars( array $node, array &$out ) {
		switch ( $node[0] ) {
			case 'var':
				$out[] = $node[1];
				break;
			case 'un':
				self::collect_vars( $node[2], $out );
				break;
			case 'bin':
				self::collect_vars( $node[2], $out );
				self::collect_vars( $node[3], $out );
				break;
			case 'fn':
				foreach ( $node[2] as $arg ) {
					self::collect_vars( $arg, $out );
				}
				break;
		}
		return $out;
	}

	/**
	 * Rounds half away from zero (matches the JS implementation).
	 *
	 * @param float $value    Value.
	 * @param int   $decimals Decimals.
	 * @return float
	 */
	public static function round_half( $value, $decimals = 0 ) {
		return round( (float) $value, max( -6, min( 6, (int) $decimals ) ), PHP_ROUND_HALF_UP );
	}

	/**
	 * Evaluates an AST node.
	 *
	 * @param array $node AST.
	 * @param array $vars Variables.
	 * @return float
	 */
	private static function run( array $node, array $vars ) {
		switch ( $node[0] ) {
			case 'num':
				return $node[1];
			case 'var':
				return isset( $vars[ $node[1] ] ) && is_numeric( $vars[ $node[1] ] ) ? (float) $vars[ $node[1] ] : 0.0;
			case 'un':
				$v = self::run( $node[2], $vars );
				if ( '-' === $node[1] ) {
					return -$v;
				}
				return '!' === $node[1] ? ( 0.0 === (float) $v ? 1.0 : 0.0 ) : $v;
			case 'bin':
				$op = $node[1];
				if ( '&&' === $op ) {
					return ( 0.0 !== self::run( $node[2], $vars ) && 0.0 !== self::run( $node[3], $vars ) ) ? 1.0 : 0.0;
				}
				if ( '||' === $op ) {
					return ( 0.0 !== self::run( $node[2], $vars ) || 0.0 !== self::run( $node[3], $vars ) ) ? 1.0 : 0.0;
				}
				$a = self::run( $node[2], $vars );
				$b = self::run( $node[3], $vars );
				return self::binary_op( $op, $a, $b );
			case 'fn':
				return self::call( $node[1], $node[2], $vars );
		}
		return 0.0;
	}

	/**
	 * Applies a binary operator.
	 *
	 * @param string $op Operator.
	 * @param float  $a  Left.
	 * @param float  $b  Right.
	 * @return float
	 */
	private static function binary_op( $op, $a, $b ) {
		switch ( $op ) {
			case '+':
				return $a + $b;
			case '-':
				return $a - $b;
			case '*':
				return $a * $b;
			case '/':
				return 0.0 === (float) $b ? 0.0 : $a / $b;
			case '%':
				return 0.0 === (float) $b ? 0.0 : fmod( $a, $b );
			case '^':
				$r = pow( $a, $b );
				return is_finite( $r ) ? (float) $r : 0.0;
			case '==':
				return abs( $a - $b ) < 1e-9 ? 1.0 : 0.0;
			case '!=':
				return abs( $a - $b ) < 1e-9 ? 0.0 : 1.0;
			case '>':
				return $a > $b ? 1.0 : 0.0;
			case '<':
				return $a < $b ? 1.0 : 0.0;
			case '>=':
				return $a >= $b ? 1.0 : 0.0;
			case '<=':
				return $a <= $b ? 1.0 : 0.0;
		}
		return 0.0;
	}

	/**
	 * Calls a whitelisted function.
	 *
	 * @param string $name Function.
	 * @param array  $args Argument ASTs.
	 * @param array  $vars Variables.
	 * @return float
	 */
	private static function call( $name, array $args, array $vars ) {
		if ( 'if' === $name ) {
			return 0.0 !== self::run( $args[0], $vars ) ? self::run( $args[1], $vars ) : self::run( $args[2], $vars );
		}
		$v = array();
		foreach ( $args as $arg ) {
			$v[] = self::run( $arg, $vars );
		}
		switch ( $name ) {
			case 'min':
				return (float) min( $v );
			case 'max':
				return (float) max( $v );
			case 'round':
				return self::round_half( $v[0], isset( $v[1] ) ? (int) $v[1] : 0 );
			case 'ceil':
				return (float) ceil( $v[0] );
			case 'floor':
				return (float) floor( $v[0] );
			case 'abs':
				return (float) abs( $v[0] );
			case 'sqrt':
				return $v[0] > 0 ? sqrt( $v[0] ) : 0.0;
			case 'pow':
				$r = pow( $v[0], $v[1] );
				return is_finite( $r ) ? (float) $r : 0.0;
			case 'clamp':
				return (float) max( $v[1], min( $v[2], $v[0] ) );
		}
		return 0.0;
	}
}
