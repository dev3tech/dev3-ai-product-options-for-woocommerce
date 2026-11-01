import apiFetch from '@wordpress/api-fetch';
import { useState } from '@wordpress/element';
import Builder from '../../src/builder/Builder';
import { GeneratePanel, LabelsPanel } from '../../src/builder/panels';
import { newField, priceSummary, depths, operatorsFor, uniqueKey } from '../../src/builder/model';
import { ToastProvider } from '../../src/core3/ui';
import { change, click, render } from './render';

afterEach( () => {
	document.body.innerHTML = '';
	apiFetch.mockReset();
	apiFetch.mockImplementation( () => new Promise( () => {} ) );
} );

function Harness( { initial = [], spy } ) {
	const [ fields, setFields ] = useState( initial );
	spy( fields );
	return (
		<ToastProvider>
			<Builder fields={ fields } onChange={ setFields } />
		</ToastProvider>
	);
}

describe( 'model', () => {
	it( 'creates fields with unique keys and sensible defaults', () => {
		const a = newField( 'text' );
		const b = newField( 'text', [ a ] );
		expect( b.key ).not.toBe( a.key );
		expect( newField( 'select' ).options ).toHaveLength( 2 );
		expect( newField( 'repeater' ).subfields ).toHaveLength( 1 );
		expect( newField( 'range' ).max ).toBe( 100 );
		expect( uniqueKey( 'name', [ { id: 'x', key: 'name' } ] ) ).toBe( 'name_2' );
		expect( operatorsFor( 'checkbox' ) ).toEqual( [ 'checked', 'unchecked' ] );
	} );
	it( 'summarises prices and indents grouped fields', () => {
		const money = ( n ) => `$${ Number( n ).toFixed( 2 ) }`;
		expect( priceSummary( { price_type: 'per_char', price: 0.5 }, money ) ).toContain( '$0.50' );
		expect( priceSummary( { price_type: 'flat', price: 3, charge: 'once' }, money ) ).toContain( 'once' );
		expect( priceSummary( { options: [ { price: 2, price_type: 'percent' } ] }, money ) ).toBe( '2%' );
		const d = depths( [ { id: 's', type: 'step' }, { id: 'a', type: 'text' }, { id: 'sec', type: 'section' }, { id: 'b', type: 'text' } ] );
		expect( d ).toEqual( { s: 0, a: 1, sec: 1, b: 2 } );
	} );
} );

describe( 'Builder', () => {
	it( 'adds a field from the palette and opens the editor drawer', async () => {
		let latest = [];
		const view = await render( <Harness spy={ ( f ) => ( latest = f ) } /> );
		await click( view.getByText( 'Add field' ).closest( 'button' ) );
		expect( view.$( '[role="dialog"]' ) ).not.toBeNull();
		await click( view.getByText( 'Dropdown' ).closest( 'button' ) );
		expect( latest ).toHaveLength( 1 );
		expect( latest[ 0 ].type ).toBe( 'select' );
		// Drawer is open on the new field: rename it.
		const label = view.$$( '.dev3po-ui-drawer input' )[ 0 ];
		await change( label, 'Board size' );
		expect( latest[ 0 ].label ).toBe( 'Board size' );
		view.unmount();
	} );

	it( 'reorders, duplicates and deletes with buttons', async () => {
		let latest = [];
		const a = { ...newField( 'text' ), label: 'First' };
		const b = { ...newField( 'checkbox', [ a ] ), label: 'Second' };
		const view = await render( <Harness initial={ [ a, b ] } spy={ ( f ) => ( latest = f ) } /> );
		await click( view.$$( 'button[aria-label="Move down"]' )[ 0 ] );
		expect( latest.map( ( f ) => f.label ) ).toEqual( [ 'Second', 'First' ] );
		await click( view.$$( 'button[aria-label="Duplicate"]' )[ 0 ] );
		expect( latest ).toHaveLength( 3 );
		expect( latest[ 1 ].label ).toBe( 'Second (copy)' );
		expect( latest[ 1 ].id ).not.toBe( latest[ 0 ].id );
		await click( view.$$( 'button[aria-label="Delete"]' )[ 2 ] );
		expect( latest.map( ( f ) => f.label ) ).toEqual( [ 'Second', 'Second (copy)' ] );
		view.unmount();
	} );

	it( 'edits conditional logic in the drawer', async () => {
		let latest = [];
		const gift = { ...newField( 'checkbox' ), label: 'Gift' };
		const msg = { ...newField( 'textarea', [ gift ] ), label: 'Message' };
		const view = await render( <Harness initial={ [ gift, msg ] } spy={ ( f ) => ( latest = f ) } /> );
		await click( view.$$( '.dev3po-b-row__main' )[ 1 ] );
		await click( view.getByText( 'Logic' ).closest( 'button' ) );
		const toggle = view.$( '.dev3po-ui-drawer [role="switch"]' );
		await click( toggle );
		expect( latest[ 1 ].conditions.enabled ).toBe( true );
		expect( latest[ 1 ].conditions.rules[ 0 ] ).toEqual( { field: gift.id, op: 'checked', value: '' } );
		view.unmount();
	} );
} );

describe( 'DEV3 AI panels', () => {
	it( 'generates options as a draft and applies them only on accept', async () => {
		const applied = jest.fn();
		const draft = { fields: [ { ...newField( 'text' ), label: 'Engraving' } ], issues: [], schema_errors: [], notes: '', model: 'gemini-2.5-flash', tokens: 900 };
		apiFetch.mockImplementation( ( { url } ) => ( url.includes( 'ai/generate' ) ? Promise.resolve( draft ) : new Promise( () => {} ) ) );
		const view = await render(
			<ToastProvider>
				<GeneratePanel context={ { title: 'Walnut board', description: 'Engraved' } } fields={ [] } onApply={ applied } />
			</ToastProvider>
		);
		await click( view.getByText( 'Generate options with DEV3 AI', true ).closest( 'button' ) || view.$$( '.dev3po-ui-btn.is-ai' )[ 0 ] );
		await view.flush();
		expect( view.queryByText( 'Not saved yet' ) ).not.toBeNull();
		expect( applied ).not.toHaveBeenCalled();
		await click( view.getByText( 'Use these fields' ).closest( 'button' ) );
		expect( applied ).toHaveBeenCalledWith( draft.fields, 'replace' );
		view.unmount();
	} );

	it( 'shows the server error envelope when AI is unavailable', async () => {
		apiFetch.mockImplementation( () => Promise.reject( { code: 'dev3po_no_api_key', message: 'No Gemini API key is configured.', hint: 'Add it in API Config.' } ) );
		const view = await render(
			<ToastProvider>
				<GeneratePanel context={ { title: 'Mug' } } fields={ [] } onApply={ () => {} } />
			</ToastProvider>
		);
		await click( view.$$( '.dev3po-ui-btn.is-ai' )[ 0 ] );
		await view.flush();
		expect( view.queryByText( 'No Gemini API key is configured.' ) ).not.toBeNull();
		view.unmount();
	} );

	it( 'applies translations into i18n without touching base labels', async () => {
		const field = { ...newField( 'radio' ), label: 'Colour' };
		const opt = field.options[ 0 ].id;
		apiFetch.mockImplementation( () => Promise.resolve( { mode: 'translate', locale: 'de_DE', draft: [ { id: field.id, label: 'Farbe', help: '', tooltip: '', placeholder: '', options: { [ opt ]: 'Rot' } } ], model: 'm', tokens: 10 } ) );
		const applied = jest.fn();
		const view = await render(
			<ToastProvider>
				<LabelsPanel fields={ [ field ] } product="Mug" onApply={ applied } />
			</ToastProvider>
		);
		await click( view.getByText( 'Translate' ).closest( 'button' ) );
		await click( view.$$( '.dev3po-ui-btn.is-ai' )[ 0 ] );
		await view.flush();
		await click( view.getByText( 'Apply texts' ).closest( 'button' ) );
		const out = applied.mock.calls[ 0 ][ 0 ][ 0 ];
		expect( out.label ).toBe( 'Colour' );
		expect( out.i18n.de_DE.label ).toBe( 'Farbe' );
		expect( out.i18n.de_DE.options[ opt ] ).toBe( 'Rot' );
		view.unmount();
	} );
} );
