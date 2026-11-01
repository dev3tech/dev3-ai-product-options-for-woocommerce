/**
 * Safe price-formula engine (no eval). Same grammar and results as includes/Engine/Formula.php.
 */

const FUNCTIONS = {
	min: [ 1, 20 ],
	max: [ 1, 20 ],
	round: [ 1, 2 ],
	ceil: [ 1, 1 ],
	floor: [ 1, 1 ],
	abs: [ 1, 1 ],
	sqrt: [ 1, 1 ],
	pow: [ 2, 2 ],
	if: [ 3, 3 ],
	clamp: [ 3, 3 ],
};

export const MAX_LENGTH = 500;
const memo = new Map();

function fail( code ) {
	const e = new Error( code );
	e.formula = true;
	return e;
}

function tokenize( src ) {
	const tokens = [];
	let i = 0;
	const isDigit = ( c ) => c >= '0' && c <= '9';
	const isAlpha = ( c ) => ( c >= 'a' && c <= 'z' ) || ( c >= 'A' && c <= 'Z' );
	while ( i < src.length ) {
		const c = src[ i ];
		if ( /[ \t\n\r\f\v]/.test( c ) ) {
			i++;
			continue;
		}
		if ( isDigit( c ) || ( c === '.' && isDigit( src[ i + 1 ] || '' ) ) ) {
			const re = /\d*\.?\d+(?:[eE][+-]?\d+)?|\d+\.?/y;
			re.lastIndex = i;
			const m = re.exec( src );
			tokens.push( [ 'num', m[ 0 ] ] );
			i += m[ 0 ].length;
			continue;
		}
		if ( c === '{' ) {
			const re = /\{\s*([a-zA-Z_][a-zA-Z0-9_-]*)(?:\.([a-z]+))?\s*\}/y;
			re.lastIndex = i;
			const m = re.exec( src );
			if ( ! m ) {
				throw fail( 'var' );
			}
			tokens.push( [ 'var', m[ 1 ].toLowerCase() + ( m[ 2 ] ? '.' + m[ 2 ] : '' ) ] );
			i += m[ 0 ].length;
			continue;
		}
		if ( isAlpha( c ) ) {
			const re = /[a-zA-Z_][a-zA-Z0-9_]*/y;
			re.lastIndex = i;
			const m = re.exec( src );
			tokens.push( [ 'id', m[ 0 ].toLowerCase() ] );
			i += m[ 0 ].length;
			continue;
		}
		const two = src.substr( i, 2 );
		if ( [ '==', '!=', '>=', '<=', '&&', '||' ].includes( two ) ) {
			tokens.push( [ 'op', two ] );
			i += 2;
			continue;
		}
		if ( '+-*/%^()<>,!'.includes( c ) ) {
			tokens.push( [ 'op', c ] );
			i++;
			continue;
		}
		throw fail( 'char:' + c );
	}
	return tokens;
}

function parse( tokens ) {
	let pos = 0;
	const peekOp = ( ops ) => {
		const t = tokens[ pos ];
		return t && t[ 0 ] === 'op' && ops.includes( t[ 1 ] ) ? t[ 1 ] : null;
	};
	const expect = ( op ) => {
		const t = tokens[ pos ];
		if ( ! t ) {
			throw fail( 'end' );
		}
		if ( t[ 0 ] !== 'op' || t[ 1 ] !== op ) {
			throw fail( 'unexpected:' + t[ 1 ] );
		}
		pos++;
	};
	const binary = ( ops, next ) => () => {
		let left = next();
		let op = peekOp( ops );
		while ( op !== null ) {
			pos++;
			left = [ 'bin', op, left, next() ];
			op = peekOp( ops );
		}
		return left;
	};
	let parseOr;
	const primary = () => {
		const t = tokens[ pos ];
		if ( ! t ) {
			throw fail( 'end' );
		}
		pos++;
		if ( t[ 0 ] === 'num' ) {
			return [ 'num', parseFloat( t[ 1 ] ) ];
		}
		if ( t[ 0 ] === 'var' ) {
			return [ 'var', t[ 1 ] ];
		}
		if ( t[ 0 ] === 'id' ) {
			if ( ! Object.prototype.hasOwnProperty.call( FUNCTIONS, t[ 1 ] ) ) {
				throw fail( 'func:' + t[ 1 ] );
			}
			expect( '(' );
			const args = [];
			if ( peekOp( [ ')' ] ) === null ) {
				args.push( parseOr() );
				while ( peekOp( [ ',' ] ) !== null ) {
					pos++;
					args.push( parseOr() );
				}
			}
			expect( ')' );
			const [ min, max ] = FUNCTIONS[ t[ 1 ] ];
			if ( args.length < min || args.length > max ) {
				throw fail( 'arity:' + t[ 1 ] );
			}
			return [ 'fn', t[ 1 ], args ];
		}
		if ( t[ 1 ] === '(' ) {
			const inner = parseOr();
			expect( ')' );
			return inner;
		}
		throw fail( 'unexpected:' + t[ 1 ] );
	};
	let unary;
	const power = () => {
		const base = primary();
		if ( peekOp( [ '^' ] ) !== null ) {
			pos++;
			return [ 'bin', '^', base, unary() ];
		}
		return base;
	};
	unary = () => {
		const op = peekOp( [ '-', '+', '!' ] );
		if ( op !== null ) {
			pos++;
			return [ 'un', op, unary() ];
		}
		return power();
	};
	const mul = binary( [ '*', '/', '%' ], unary );
	const add = binary( [ '+', '-' ], mul );
	const cmp = () => {
		let left = add();
		const op = peekOp( [ '==', '!=', '>=', '<=', '>', '<' ] );
		if ( op !== null ) {
			pos++;
			left = [ 'bin', op, left, add() ];
		}
		return left;
	};
	const and = binary( [ '&&' ], cmp );
	parseOr = binary( [ '||' ], and );
	const ast = parseOr();
	if ( pos < tokens.length ) {
		throw fail( 'unexpected:' + tokens[ pos ][ 1 ] );
	}
	return ast;
}

function collectVars( node, out ) {
	switch ( node[ 0 ] ) {
		case 'var':
			out.push( node[ 1 ] );
			break;
		case 'un':
			collectVars( node[ 2 ], out );
			break;
		case 'bin':
			collectVars( node[ 2 ], out );
			collectVars( node[ 3 ], out );
			break;
		case 'fn':
			node[ 2 ].forEach( ( a ) => collectVars( a, out ) );
			break;
	}
	return out;
}

/**
 * Compiles a formula.
 *
 * @param {string} source Formula.
 * @return {Object} { ok, ast, vars } or { ok: false, error }.
 */
export function compile( source ) {
	const src = String( source ?? '' ).trim();
	if ( memo.has( src ) ) {
		return memo.get( src );
	}
	let result;
	if ( src === '' ) {
		result = { ok: false, error: 'empty' };
	} else if ( src.length > MAX_LENGTH ) {
		result = { ok: false, error: 'too_long' };
	} else {
		try {
			const ast = parse( tokenize( src ) );
			result = { ok: true, ast, vars: [ ...new Set( collectVars( ast, [] ) ) ] };
		} catch ( e ) {
			if ( ! e.formula ) {
				throw e;
			}
			result = { ok: false, error: e.message };
		}
	}
	if ( memo.size > 200 ) {
		memo.clear();
	}
	memo.set( src, result );
	return result;
}

/**
 * Half away from zero (PHP_ROUND_HALF_UP), decimal-safe via exponent shifting.
 *
 * @param {number} value    Value.
 * @param {number} decimals Decimals (-6..6).
 * @return {number} Rounded.
 */
export function roundHalf( value, decimals = 0 ) {
	const d = Math.max( -6, Math.min( 6, Math.trunc( decimals ) ) );
	const sign = value < 0 ? -1 : 1;
	const abs = Math.abs( value );
	const shifted = Number( Math.round( Number( abs + 'e' + d ) ) + 'e' + -d );
	return Number.isFinite( shifted ) ? sign * shifted : 0;
}

const truthy = ( v ) => v !== 0;

function run( node, vars ) {
	switch ( node[ 0 ] ) {
		case 'num':
			return node[ 1 ];
		case 'var': {
			const v = vars[ node[ 1 ] ];
			return typeof v === 'number' && Number.isFinite( v ) ? v : Number( v ) || 0;
		}
		case 'un': {
			const v = run( node[ 2 ], vars );
			if ( node[ 1 ] === '-' ) {
				return -v;
			}
			return node[ 1 ] === '!' ? ( v === 0 ? 1 : 0 ) : v;
		}
		case 'bin': {
			const op = node[ 1 ];
			if ( op === '&&' ) {
				return truthy( run( node[ 2 ], vars ) ) && truthy( run( node[ 3 ], vars ) ) ? 1 : 0;
			}
			if ( op === '||' ) {
				return truthy( run( node[ 2 ], vars ) ) || truthy( run( node[ 3 ], vars ) ) ? 1 : 0;
			}
			const a = run( node[ 2 ], vars );
			const b = run( node[ 3 ], vars );
			switch ( op ) {
				case '+':
					return a + b;
				case '-':
					return a - b;
				case '*':
					return a * b;
				case '/':
					return b === 0 ? 0 : a / b;
				case '%':
					return b === 0 ? 0 : a % b;
				case '^': {
					const r = Math.pow( a, b );
					return Number.isFinite( r ) ? r : 0;
				}
				case '==':
					return Math.abs( a - b ) < 1e-9 ? 1 : 0;
				case '!=':
					return Math.abs( a - b ) < 1e-9 ? 0 : 1;
				case '>':
					return a > b ? 1 : 0;
				case '<':
					return a < b ? 1 : 0;
				case '>=':
					return a >= b ? 1 : 0;
				case '<=':
					return a <= b ? 1 : 0;
			}
			return 0;
		}
		case 'fn': {
			const name = node[ 1 ];
			const args = node[ 2 ];
			if ( name === 'if' ) {
				return truthy( run( args[ 0 ], vars ) ) ? run( args[ 1 ], vars ) : run( args[ 2 ], vars );
			}
			const v = args.map( ( a ) => run( a, vars ) );
			switch ( name ) {
				case 'min':
					return Math.min( ...v );
				case 'max':
					return Math.max( ...v );
				case 'round':
					return roundHalf( v[ 0 ], v.length > 1 ? Math.trunc( v[ 1 ] ) : 0 );
				case 'ceil':
					return Math.ceil( v[ 0 ] );
				case 'floor':
					return Math.floor( v[ 0 ] );
				case 'abs':
					return Math.abs( v[ 0 ] );
				case 'sqrt':
					return v[ 0 ] > 0 ? Math.sqrt( v[ 0 ] ) : 0;
				case 'pow': {
					const r = Math.pow( v[ 0 ], v[ 1 ] );
					return Number.isFinite( r ) ? r : 0;
				}
				case 'clamp':
					return Math.max( v[ 1 ], Math.min( v[ 2 ], v[ 0 ] ) );
			}
			return 0;
		}
	}
	return 0;
}

/**
 * Evaluates a formula; invalid formulas give 0.
 *
 * @param {string} source Formula.
 * @param {Object} vars   name => number.
 * @return {number} Result.
 */
export function evaluate( source, vars = {} ) {
	const c = compile( source );
	if ( ! c.ok ) {
		return 0;
	}
	const v = run( c.ast, vars );
	return Number.isFinite( v ) ? v : 0;
}
