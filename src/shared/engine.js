/**
 * Field kinds, values, conditions and pricing. Mirrors includes/Engine/{Values,Conditions,Pricing}.php
 * exactly; both pass tests/fixtures/price-cases.json.
 */
import { evaluate, roundHalf } from './formula';

export const KINDS = {
	text: 'text',
	textarea: 'text',
	email: 'text',
	url: 'text',
	tel: 'text',
	number: 'number',
	range: 'number',
	select: 'choice',
	radio: 'choice',
	checkboxes: 'multi',
	color_swatch: 'choice',
	image_swatch: 'choice',
	image_picker: 'choice',
	checkbox: 'bool',
	toggle: 'bool',
	date: 'date',
	time: 'date',
	datetime: 'date',
	file: 'file',
	color: 'color',
	hidden: 'hidden',
	repeater: 'repeater',
	section: 'layout',
	step: 'layout',
	paragraph: 'layout',
};

export const kind = ( type ) => KINDS[ type ] || 'text';
export const isInput = ( type ) => kind( type ) !== 'layout';
export const hasOptions = ( type ) => [ 'choice', 'multi' ].includes( kind( type ) );

const WS = /[ \t\n\r\f\v]+/g;
const isNumeric = ( v ) => typeof v === 'number' ? Number.isFinite( v ) : typeof v === 'string' && v.trim() !== '' && /^[+-]?(\d+\.?\d*|\.\d+)([eE][+-]?\d+)?$/.test( v.trim() );

export function emptyValue( f ) {
	switch ( kind( f.type ) ) {
		case 'number':
			return null;
		case 'multi':
		case 'file':
		case 'repeater':
			return [];
		case 'bool':
			return false;
	}
	return '';
}

export function isEmpty( f, value ) {
	if ( f.type === 'hidden' ) {
		return String( value ?? '' ) === '';
	}
	if ( Array.isArray( value ) ) {
		return value.length === 0;
	}
	if ( typeof value === 'boolean' ) {
		return ! value;
	}
	return value === null || value === undefined || String( value ) === '';
}

export function numeric( f, value ) {
	switch ( kind( f.type ) ) {
		case 'number':
			return value === null || value === undefined || value === '' ? 0 : Number( value ) || 0;
		case 'bool':
			return value ? 1 : 0;
		case 'multi':
		case 'file':
		case 'repeater':
			return Array.isArray( value ) ? value.length : 0;
		case 'choice': {
			const opt = ( f.options || [] ).find( ( o ) => o.id === value );
			return opt && isNumeric( opt.value ) ? parseFloat( opt.value ) : 0;
		}
		case 'text':
		case 'hidden':
			return isNumeric( String( value ?? '' ) ) ? parseFloat( String( value ).trim() ) : 0;
	}
	return 0;
}

export function chars( f, value ) {
	if ( ! [ 'text', 'hidden' ].includes( kind( f.type ) ) ) {
		return 0;
	}
	let text = String( value ?? '' );
	if ( ! f.count_space ) {
		text = text.replace( WS, '' );
	}
	return Array.from( text ).length;
}

export function words( value ) {
	if ( typeof value !== 'string' ) {
		return 0;
	}
	return value.trim().split( WS ).filter( Boolean ).length;
}

export function lines( value ) {
	if ( typeof value !== 'string' || value.trim() === '' ) {
		return 0;
	}
	return value.replace( /\r/g, '' ).trim().split( '\n' ).length;
}

export function toStringValue( value ) {
	if ( Array.isArray( value ) ) {
		return '';
	}
	if ( typeof value === 'boolean' ) {
		return value ? '1' : '';
	}
	if ( typeof value === 'number' ) {
		return String( Number( value.toFixed( 6 ) ) );
	}
	return value === null || value === undefined ? '' : String( value );
}

/* ---------------------------------------------------------------- conditions */

export function parents( fields ) {
	const out = {};
	let section = null;
	let step = null;
	fields.forEach( ( f ) => {
		if ( f.type === 'step' ) {
			step = f.id;
			section = null;
			out[ f.id ] = [];
			return;
		}
		if ( f.type === 'section' ) {
			section = f.id;
			out[ f.id ] = step ? [ step ] : [];
			return;
		}
		out[ f.id ] = [ step, section ].filter( Boolean );
	} );
	return out;
}

function equals( k, value, want ) {
	if ( k === 'choice' || k === 'multi' ) {
		return ( Array.isArray( value ) ? value : [ value ] ).includes( want );
	}
	if ( k === 'bool' ) {
		const t = [ '1', 'yes', 'true', 'on', 'checked' ].includes( want.toLowerCase() );
		return !! value === t;
	}
	const have = toStringValue( value );
	if ( isNumeric( have ) && isNumeric( want ) ) {
		return Math.abs( parseFloat( have ) - parseFloat( want ) ) < 1e-9;
	}
	return have.trim().toLowerCase() === want.trim().toLowerCase();
}

export function testRule( target, value, rule ) {
	const k = kind( target.type );
	const want = String( rule.value ?? '' );
	switch ( rule.op ) {
		case 'empty':
			return isEmpty( target, value );
		case 'not_empty':
			return ! isEmpty( target, value );
		case 'checked':
			return k === 'bool' ? !! value : ! isEmpty( target, value );
		case 'unchecked':
			return k === 'bool' ? ! value : isEmpty( target, value );
		case 'is':
		case 'is_not': {
			const m = equals( k, value, want );
			return rule.op === 'is' ? m : ! m;
		}
		case 'contains':
		case 'not_contains': {
			let m;
			if ( Array.isArray( value ) || k === 'choice' ) {
				m = ( Array.isArray( value ) ? value : [ value ] ).includes( want );
			} else {
				m = want !== '' && toStringValue( value ).toLowerCase().includes( want.toLowerCase() );
			}
			return rule.op === 'contains' ? m : ! m;
		}
		case 'gt':
		case 'lt':
		case 'gte':
		case 'lte': {
			if ( ! isNumeric( want ) || isEmpty( target, value ) ) {
				return false;
			}
			const n = numeric( target, value );
			const w = parseFloat( want );
			if ( rule.op === 'gt' ) {
				return n > w;
			}
			if ( rule.op === 'lt' ) {
				return n < w;
			}
			return rule.op === 'gte' ? n >= w : n <= w;
		}
	}
	return false;
}

function ownRule( f, byId, values, visible ) {
	const c = f.conditions;
	if ( ! c || ! c.enabled || ! c.rules || ! c.rules.length ) {
		return true;
	}
	const results = c.rules.map( ( rule ) => {
		const target = byId[ rule.field ];
		if ( ! target ) {
			return false;
		}
		const value = visible[ target.id ] && Object.prototype.hasOwnProperty.call( values, target.id ) ? values[ target.id ] : emptyValue( target );
		return testRule( target, value, rule );
	} );
	const pass = c.match === 'any' ? results.includes( true ) : ! results.includes( false );
	return c.action === 'hide' ? ! pass : pass;
}

export function visibility( fields, values ) {
	const byId = {};
	const visible = {};
	const par = parents( fields );
	fields.forEach( ( f ) => {
		byId[ f.id ] = f;
		visible[ f.id ] = true;
	} );
	const passes = fields.length + 2;
	for ( let p = 0; p < passes; p++ ) {
		let changed = false;
		for ( const f of fields ) {
			let v = ownRule( f, byId, values, visible );
			for ( const parent of par[ f.id ] || [] ) {
				if ( ! visible[ parent ] ) {
					v = false;
				}
			}
			if ( v !== visible[ f.id ] ) {
				visible[ f.id ] = v;
				changed = true;
			}
		}
		if ( ! changed ) {
			break;
		}
	}
	return visible;
}

/* ---------------------------------------------------------------- pricing */

export function tierPrice( tiers, qty ) {
	let price = 0;
	( tiers || [] ).forEach( ( t ) => {
		if ( qty >= Number( t.min ) ) {
			price = Number( t.price );
		}
	} );
	return price;
}

function ownVars( f, value, vars ) {
	return {
		...vars,
		value: numeric( f, value ),
		len: chars( f, value ),
		words: words( value ),
		lines: lines( value ),
		count: Array.isArray( value ) ? value.length : isEmpty( f, value ) ? 0 : 1,
	};
}

export function fieldAmount( f, value, base, qty, vars = {} ) {
	if ( isEmpty( f, value ) ) {
		return 0;
	}
	const k = kind( f.type );
	let amount = 0;
	if ( k === 'choice' || k === 'multi' ) {
		const selected = Array.isArray( value ) ? value : [ value ];
		( f.options || [] ).forEach( ( o ) => {
			if ( selected.includes( o.id ) ) {
				amount += o.price_type === 'percent' ? ( base * o.price ) / 100 : Number( o.price );
			}
		} );
	}
	if ( k === 'repeater' ) {
		( value || [] ).forEach( ( row ) => {
			( f.subfields || [] ).forEach( ( sub ) => {
				if ( isInput( sub.type ) ) {
					const sv = row && Object.prototype.hasOwnProperty.call( row, sub.id ) ? row[ sub.id ] : emptyValue( sub );
					amount += fieldAmount( sub, sv, base, qty, ownVars( sub, sv, vars ) );
				}
			} );
		} );
	}
	const price = Number( f.price ) || 0;
	switch ( f.price_type ) {
		case 'flat':
			amount += k === 'repeater' ? price * value.length : price;
			break;
		case 'percent':
			amount += ( base * price ) / 100;
			break;
		case 'per_char':
			amount += price * Math.max( 0, chars( f, value ) - ( Number( f.free_chars ) || 0 ) );
			break;
		case 'per_unit':
			amount += price * numeric( f, value );
			break;
		case 'qty_tiers':
			amount += tierPrice( f.tiers, qty );
			break;
		case 'formula':
			amount += evaluate( f.formula, ownVars( f, value, vars ) );
			break;
	}
	return amount;
}

export const roundMoney = ( value, dp = 2 ) => roundHalf( value + ( value >= 0 ? 1e-9 : -1e-9 ), dp );

/**
 * Calculates add-on totals (same shape as Pricing::calculate).
 *
 * @param {Array}  fields Fields.
 * @param {Object} values id => value.
 * @param {number} base   Unit price.
 * @param {number} qty    Quantity.
 * @return {Object} { unit, once, total, lines, visible }
 */
export function calculate( fields, values, base, qty = 1 ) {
	base = Number( base ) || 0;
	qty = Math.max( 1, Math.trunc( Number( qty ) || 1 ) );
	const visible = visibility( fields, values );
	const vars = { price: base, qty };
	fields.forEach( ( f ) => {
		if ( ! isInput( f.type ) ) {
			return;
		}
		const v = visible[ f.id ] && Object.prototype.hasOwnProperty.call( values, f.id ) ? values[ f.id ] : emptyValue( f );
		vars[ f.key ] = numeric( f, v );
		vars[ f.key + '.len' ] = chars( f, v );
		vars[ f.key + '.count' ] = Array.isArray( v ) ? v.length : isEmpty( f, v ) ? 0 : 1;
	} );
	let unit = 0;
	let once = 0;
	const lineItems = [];
	fields.forEach( ( f ) => {
		if ( ! isInput( f.type ) || ! visible[ f.id ] ) {
			return;
		}
		const v = Object.prototype.hasOwnProperty.call( values, f.id ) ? values[ f.id ] : emptyValue( f );
		const amount = fieldAmount( f, v, base, qty, vars );
		if ( amount === 0 ) {
			return;
		}
		if ( f.charge === 'once' ) {
			once += amount;
		} else {
			unit += amount;
		}
		lineItems.push( { id: f.id, label: f.label, amount, charge: f.charge } );
	} );
	const unitPrice = Math.max( 0, base + unit );
	return { unit, once, total: roundMoney( unitPrice * qty + once ), lines: lineItems, visible };
}
