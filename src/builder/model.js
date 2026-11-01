/**
 * Field model helpers for the builder (shape matches Fields\Field_Schema::normalize_field).
 */
import { __ } from '@wordpress/i18n';
import { kind, hasOptions, isInput } from '../shared/engine';

const D = 'dev3-ai-product-options-for-woocommerce';

export const TYPES = [
	{ type: 'text', label: __( 'Text', D ), group: 'text', glyph: 'Aa', hint: __( 'Names, engraving, short notes', D ) },
	{ type: 'textarea', label: __( 'Textarea', D ), group: 'text', glyph: '¶', hint: __( 'Longer messages', D ) },
	{ type: 'email', label: __( 'Email', D ), group: 'text', glyph: '@', hint: __( 'Validated email address', D ) },
	{ type: 'url', label: __( 'URL', D ), group: 'text', glyph: '://', hint: __( 'A link, e.g. to artwork', D ) },
	{ type: 'tel', label: __( 'Phone', D ), group: 'text', glyph: '+1', hint: __( 'Phone number', D ) },
	{ type: 'number', label: __( 'Number', D ), group: 'number', glyph: '123', hint: __( 'Width, height, amount', D ) },
	{ type: 'range', label: __( 'Range slider', D ), group: 'number', glyph: '⇄', hint: __( 'Pick a value on a slider', D ) },
	{ type: 'select', label: __( 'Dropdown', D ), group: 'choice', glyph: '▾', hint: __( 'One choice from a list', D ) },
	{ type: 'radio', label: __( 'Radio buttons', D ), group: 'choice', glyph: '◉', hint: __( 'One visible choice', D ) },
	{ type: 'checkboxes', label: __( 'Checkbox group', D ), group: 'choice', glyph: '☑', hint: __( 'Several choices', D ) },
	{ type: 'color_swatch', label: __( 'Colour swatches', D ), group: 'choice', glyph: '●', hint: __( 'Round colour chips', D ) },
	{ type: 'image_swatch', label: __( 'Image swatches', D ), group: 'choice', glyph: '◐', hint: __( 'Round image chips (materials)', D ) },
	{ type: 'image_picker', label: __( 'Image picker', D ), group: 'choice', glyph: '▦', hint: __( 'Large image cards', D ) },
	{ type: 'checkbox', label: __( 'Single checkbox', D ), group: 'choice', glyph: '✓', hint: __( 'Yes/no extra, e.g. gift wrap', D ) },
	{ type: 'toggle', label: __( 'Toggle switch', D ), group: 'choice', glyph: '⊙', hint: __( 'Yes/no as a switch', D ) },
	{ type: 'date', label: __( 'Date', D ), group: 'date', glyph: '31', hint: __( 'Delivery or event date', D ) },
	{ type: 'time', label: __( 'Time', D ), group: 'date', glyph: '◷', hint: __( 'Pick-up time', D ) },
	{ type: 'datetime', label: __( 'Date & time', D ), group: 'date', glyph: '31◷', hint: __( 'Appointment', D ) },
	{ type: 'file', label: __( 'File upload', D ), group: 'media', glyph: '⇪', hint: __( 'Photos, logos, PDFs (with DEV3 AI check)', D ) },
	{ type: 'color', label: __( 'Colour picker', D ), group: 'media', glyph: '◍', hint: __( 'Any custom colour', D ) },
	{ type: 'hidden', label: __( 'Hidden value', D ), group: 'advanced', glyph: '∅', hint: __( 'Fixed fee or formula helper', D ) },
	{ type: 'repeater', label: __( 'Repeater', D ), group: 'layout', glyph: '⧉', hint: __( 'Repeat a group, e.g. one per name', D ) },
	{ type: 'section', label: __( 'Section', D ), group: 'layout', glyph: '▭', hint: __( 'Group fields under a heading', D ) },
	{ type: 'step', label: __( 'Step', D ), group: 'layout', glyph: '→', hint: __( 'Split into a multi-step wizard', D ) },
	{ type: 'paragraph', label: __( 'Paragraph', D ), group: 'layout', glyph: 'i', hint: __( 'Explanatory text', D ) },
];

export const GROUPS = [
	{ id: 'text', label: __( 'Text', D ) },
	{ id: 'number', label: __( 'Numbers', D ) },
	{ id: 'choice', label: __( 'Choices', D ) },
	{ id: 'date', label: __( 'Date & time', D ) },
	{ id: 'media', label: __( 'Files & colour', D ) },
	{ id: 'advanced', label: __( 'Advanced', D ) },
	{ id: 'layout', label: __( 'Layout', D ) },
];

export const typeMeta = ( type ) => TYPES.find( ( t ) => t.type === type ) || TYPES[ 0 ];

export const PRICE_TYPES = {
	none: __( 'No price', D ),
	flat: __( 'Flat fee', D ),
	percent: __( 'Percent of product price', D ),
	per_char: __( 'Per character', D ),
	per_unit: __( 'Per unit (× value)', D ),
	qty_tiers: __( 'Quantity tiers', D ),
	formula: __( 'Formula', D ),
};

const KIND_PRICES = {
	text: [ 'none', 'flat', 'percent', 'per_char', 'qty_tiers', 'formula' ],
	number: [ 'none', 'flat', 'percent', 'per_unit', 'qty_tiers', 'formula' ],
	choice: [ 'none', 'formula' ],
	multi: [ 'none', 'formula' ],
	bool: [ 'none', 'flat', 'percent', 'qty_tiers', 'formula' ],
	date: [ 'none', 'flat', 'percent', 'formula' ],
	file: [ 'none', 'flat', 'percent', 'per_unit', 'formula' ],
	color: [ 'none', 'flat', 'percent', 'formula' ],
	hidden: [ 'none', 'flat', 'percent', 'formula' ],
	repeater: [ 'none', 'flat', 'formula' ],
	layout: [ 'none' ],
};

export const priceTypesFor = ( type ) => KIND_PRICES[ kind( type ) ];

export const OPERATORS = {
	is: __( 'is', D ),
	is_not: __( 'is not', D ),
	contains: __( 'contains', D ),
	not_contains: __( 'does not contain', D ),
	empty: __( 'is empty', D ),
	not_empty: __( 'is not empty', D ),
	gt: __( 'is greater than', D ),
	lt: __( 'is less than', D ),
	gte: __( 'is at least', D ),
	lte: __( 'is at most', D ),
	checked: __( 'is checked', D ),
	unchecked: __( 'is not checked', D ),
};

export function operatorsFor( type ) {
	switch ( kind( type ) ) {
		case 'bool':
			return [ 'checked', 'unchecked' ];
		case 'choice':
			return [ 'is', 'is_not', 'empty', 'not_empty' ];
		case 'multi':
			return [ 'contains', 'not_contains', 'empty', 'not_empty', 'gte', 'lte' ];
		case 'number':
			return [ 'is', 'is_not', 'gt', 'lt', 'gte', 'lte', 'empty', 'not_empty' ];
		case 'file':
		case 'repeater':
			return [ 'not_empty', 'empty', 'gte', 'lte' ];
		default:
			return [ 'is', 'is_not', 'contains', 'not_contains', 'empty', 'not_empty' ];
	}
}

export const needsValue = ( op ) => ! [ 'empty', 'not_empty', 'checked', 'unchecked' ].includes( op );

export function uid( prefix = 'f' ) {
	const chars = 'abcdefghijklmnopqrstuvwxyz0123456789';
	let out = '';
	for ( let i = 0; i < 8; i++ ) {
		out += chars[ Math.floor( Math.random() * chars.length ) ];
	}
	return `${ prefix }_${ out }`;
}

export const slugKey = ( label ) =>
	String( label || '' )
		.toLowerCase()
		.normalize( 'NFKD' )
		.replace( /[̀-ͯ]/g, '' )
		.replace( /[^a-z0-9]+/g, '_' )
		.replace( /^_+|_+$/g, '' )
		.slice( 0, 40 ) || 'field';

export function uniqueKey( base, fields, exceptId ) {
	const taken = new Set( fields.filter( ( f ) => f.id !== exceptId ).map( ( f ) => f.key ) );
	let key = /^[a-z_]/.test( base ) ? base : `field_${ base }`;
	const root = key;
	let n = 2;
	while ( taken.has( key ) ) {
		key = `${ root }_${ n++ }`;
	}
	return key;
}

export function newOption( label = '', price = 0 ) {
	return { id: uid( 'o' ), label, value: slugKey( label ).replace( /_/g, '-' ), price, price_type: 'flat', image: '', color: '', default: false };
}

export function newField( type, fields = [] ) {
	const meta = typeMeta( type );
	const label = meta.label;
	const f = {
		id: uid( 'f' ),
		type,
		key: uniqueKey( slugKey( label ), fields ),
		label,
		help: '',
		tooltip: '',
		placeholder: '',
		required: false,
		default: '',
		width: 'full',
		class: '',
		price_type: 'none',
		price: 0,
		formula: '',
		tiers: [],
		charge: 'item',
		free_chars: 0,
		count_space: false,
		conditions: { enabled: false, action: 'show', match: 'all', rules: [] },
		i18n: {},
	};
	if ( hasOptions( type ) ) {
		f.options = type === 'color_swatch'
			? [ { ...newOption( __( 'Black', D ) ), color: '#1e1e1e' }, { ...newOption( __( 'White', D ) ), color: '#ffffff' } ]
			: [ newOption( __( 'Option 1', D ) ), newOption( __( 'Option 2', D ) ) ];
		f.max_select = 0;
	}
	if ( kind( type ) === 'number' ) {
		f.min = type === 'range' ? 0 : null;
		f.max = type === 'range' ? 100 : null;
		f.step = null;
		f.unit = '';
	}
	if ( kind( type ) === 'text' ) {
		f.maxlength = 0;
		f.minlength = 0;
		if ( type === 'text' || type === 'textarea' ) {
			f.min = null;
			f.max = null;
			f.step = null;
		}
	}
	if ( kind( type ) === 'date' ) {
		f.date_min = null;
		f.date_max = null;
		f.disabled_days = [];
	}
	if ( type === 'file' ) {
		Object.assign( f, { accept: 'jpg,jpeg,png,webp', max_files: 1, max_size: 5, min_width: 0, min_height: 0, vision: false, vision_rule: '' } );
	}
	if ( type === 'repeater' ) {
		Object.assign( f, { subfields: [ newField( 'text' ) ], min_rows: 1, max_rows: 5, button_label: '' } );
	}
	if ( [ 'section', 'step', 'paragraph' ].includes( type ) ) {
		f.description = '';
		f.collapsible = false;
	}
	return f;
}

export function cloneField( f, fields ) {
	const copy = JSON.parse( JSON.stringify( f ) );
	copy.id = uid( 'f' );
	copy.key = uniqueKey( f.key, fields );
	copy.label = f.label ? `${ f.label } (copy)` : '';
	( copy.options || [] ).forEach( ( o ) => ( o.id = uid( 'o' ) ) );
	( copy.subfields || [] ).forEach( ( s ) => ( s.id = uid( 'f' ) ) );
	return copy;
}

/**
 * Short price summary for list rows.
 *
 * @param {Object}   f     Field.
 * @param {Function} money Money formatter.
 * @return {string} Summary.
 */
export function priceSummary( f, money ) {
	const priced = ( f.options || [] ).filter( ( o ) => Number( o.price ) !== 0 );
	if ( priced.length ) {
		const values = priced.map( ( o ) => ( o.price_type === 'percent' ? `${ o.price }%` : money( o.price ) ) );
		return values.length > 3 ? `${ values.slice( 0, 3 ).join( ', ' ) }…` : values.join( ', ' );
	}
	switch ( f.price_type ) {
		case 'flat':
			return money( f.price ) + ( f.charge === 'once' ? ` · ${ __( 'once', D ) }` : '' );
		case 'percent':
			return `${ f.price }%`;
		case 'per_char':
			/* translators: %s: price */
			return `${ money( f.price ) } / ${ __( 'char', D ) }`;
		case 'per_unit':
			return `${ money( f.price ) } × ${ __( 'value', D ) }`;
		case 'qty_tiers':
			return __( 'Tiers', D );
		case 'formula':
			return 'ƒ ' + f.formula;
	}
	return '';
}

/**
 * Indent level of each field in the flat list (steps and sections open groups).
 *
 * @param {Array} fields Fields.
 * @return {Object} id => depth.
 */
export function depths( fields ) {
	const out = {};
	let inStep = false;
	let inSection = false;
	fields.forEach( ( f ) => {
		if ( f.type === 'step' ) {
			inStep = true;
			inSection = false;
			out[ f.id ] = 0;
			return;
		}
		if ( f.type === 'section' ) {
			out[ f.id ] = inStep ? 1 : 0;
			inSection = true;
			return;
		}
		out[ f.id ] = ( inStep ? 1 : 0 ) + ( inSection ? 1 : 0 );
	} );
	return out;
}

export { kind, hasOptions, isInput };
