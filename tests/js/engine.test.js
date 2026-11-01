import cases from '../fixtures/price-cases.json';
import { compile, evaluate, roundHalf } from '../../src/shared/formula';
import { calculate, visibility, testRule, chars } from '../../src/shared/engine';

describe( 'price engine fixtures (shared with PHPUnit)', () => {
	it( 'has at least 100 cases', () => {
		expect( cases.length ).toBeGreaterThanOrEqual( 100 );
	} );
	test.each( cases.map( ( c ) => [ c.name, c ] ) )( '%s', ( name, c ) => {
		const r = calculate( c.fields, c.values, c.base, c.qty );
		expect( r.unit ).toBeCloseTo( c.expect.unit, 6 );
		expect( r.once ).toBeCloseTo( c.expect.once, 6 );
		expect( r.total ).toBe( c.expect.total );
	} );
} );

describe( 'formula parser', () => {
	it( 'rejects unsafe or broken input', () => {
		expect( compile( 'alert(1)' ).ok ).toBe( false );
		expect( compile( 'alert(1)' ).error ).toBe( 'func:alert' );
		expect( compile( '2 +' ).error ).toBe( 'end' );
		expect( compile( '{len' ).error ).toBe( 'var' );
		expect( compile( '2 $ 3' ).error ).toBe( 'char:$' );
		expect( compile( 'min()' ).error ).toBe( 'arity:min' );
		expect( compile( '' ).error ).toBe( 'empty' );
		expect( compile( '1+'.repeat( 300 ) + '1' ).error ).toBe( 'too_long' );
	} );
	it( 'collects variables', () => {
		expect( compile( 'max(0, {len} - 10) * {Width} + {name.len}' ).vars ).toEqual( [ 'len', 'width', 'name.len' ] );
	} );
	it( 'evaluates like PHP', () => {
		expect( evaluate( '2 ^ 3 ^ 2' ) ).toBe( 512 );
		expect( evaluate( '-2 ^ 2' ) ).toBe( -4 );
		expect( evaluate( '7 % 0' ) ).toBe( 0 );
		expect( evaluate( 'if(1 > 2, 5, 6)' ) ).toBe( 6 );
		expect( roundHalf( 1.005, 2 ) ).toBe( 1.01 );
		expect( roundHalf( -2.5 ) ).toBe( -3 );
	} );
} );

describe( 'conditions', () => {
	const box = { id: 'fa', type: 'checkbox', key: 'a', conditions: { enabled: false, rules: [] } };
	const text = { id: 'fb', type: 'text', key: 'b', conditions: { enabled: true, action: 'show', match: 'all', rules: [ { field: 'fa', op: 'checked', value: '' } ] } };
	it( 'shows and hides', () => {
		expect( visibility( [ box, text ], { fa: true } ).fb ).toBe( true );
		expect( visibility( [ box, text ], { fa: false } ).fb ).toBe( false );
	} );
	it( 'compares text case-insensitively and numbers numerically', () => {
		expect( testRule( { type: 'text' }, 'Gold', { op: 'is', value: 'gold' } ) ).toBe( true );
		expect( testRule( { type: 'text' }, '7', { op: 'is', value: '7.0' } ) ).toBe( true );
		expect( testRule( { type: 'number' }, null, { op: 'gt', value: '0' } ) ).toBe( false );
	} );
	it( 'counts characters without spaces by default', () => {
		expect( chars( { type: 'text' }, 'a b  c' ) ).toBe( 3 );
		expect( chars( { type: 'text', count_space: true }, 'a b' ) ).toBe( 3 );
	} );
} );
