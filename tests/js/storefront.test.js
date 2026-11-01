import { init, formatMoney } from '../../src/storefront';

const fields = [
	{ id: 'f_gift', type: 'checkbox', key: 'gift', label: 'Gift', required: false, price_type: 'flat', price: 4, charge: 'item', conditions: { enabled: false, rules: [] } },
	{ id: 'f_msg', type: 'text', key: 'msg', label: 'Message', required: true, price_type: 'per_char', price: 0.5, free_chars: 0, charge: 'item', maxlength: 10, conditions: { enabled: true, action: 'show', match: 'all', rules: [ { field: 'f_gift', op: 'checked', value: '' } ] } },
	{ id: 'f_setup', type: 'hidden', key: 'setup', label: 'Setup', default: 'x', price_type: 'flat', price: 10, charge: 'once', conditions: { enabled: false, rules: [] } },
];

function mount() {
	const config = { product: 7, base: 20, fields, currency: { symbol: '€', format: '%2$s %1$s', decimals: 2, dsep: ',', tsep: '.' }, upload: '/u' };
	document.body.innerHTML = `
	<form class="cart">
		<div class="dev3po-form" data-dev3po='${ JSON.stringify( config ) }'>
			<div class="dev3po-fields">
				<div class="dev3po-field" data-field="f_gift" data-type="checkbox"><label><input type="checkbox" name="dev3po[f_gift]" value="1"> Gift</label><p class="dev3po-error" hidden></p></div>
				<div class="dev3po-field" data-field="f_msg" data-type="text"><input class="dev3po-input" maxlength="10" name="dev3po[f_msg]"><span class="dev3po-counter"></span><p class="dev3po-error" hidden></p></div>
				<div class="dev3po-field dev3po-field--hidden" data-field="f_setup" hidden><input type="hidden" name="dev3po[f_setup]" value="x"></div>
			</div>
			<div class="dev3po-totals" hidden>
				<div class="dev3po-totals__row"><span data-total="options" class="v"></span></div>
				<div class="dev3po-totals__row dev3po-totals__fees" hidden><span data-total="once"></span></div>
				<div class="dev3po-totals__row"><span data-total="grand"></span></div>
			</div>
		</div>
		<input class="qty" name="quantity" value="2">
		<button type="submit">Add</button>
	</form>`;
	init();
	return document.querySelector( '.dev3po-form' );
}

const fire = ( el, type ) => el.dispatchEvent( new window.Event( type, { bubbles: true } ) );

describe( 'storefront form', () => {
	it( 'formats money with the store format', () => {
		expect( formatMoney( 1234.5, { symbol: '€', format: '%2$s %1$s', decimals: 2, dsep: ',', tsep: '.' } ) ).toBe( '1.234,50 €' );
		expect( formatMoney( -3, { symbol: '$', format: '%1$s%2$s', decimals: 2 } ) ).toBe( '−$3.00' );
	} );

	it( 'applies conditions, disables hidden inputs and prices live', () => {
		const form = mount();
		const msgWrap = form.querySelector( '[data-field="f_msg"]' );
		const msg = msgWrap.querySelector( 'input' );
		expect( msgWrap.hidden ).toBe( true );
		expect( msg.disabled ).toBe( true );
		expect( form.querySelector( '.dev3po-totals' ).hidden ).toBe( false );
		expect( form.querySelector( '[data-total="grand"]' ).textContent ).toBe( '50,00 €' ); // 20×2 + 10 once
		const gift = form.querySelector( 'input[name="dev3po[f_gift]"]' );
		gift.checked = true;
		fire( gift, 'change' );
		expect( msgWrap.hidden ).toBe( false );
		expect( msg.disabled ).toBe( false );
		msg.value = 'Love';
		fire( msg, 'input' );
		// (20 + 4 + 4×0.5) × 2 + 10 = 62
		expect( form.querySelector( '[data-total="grand"]' ).textContent ).toBe( '62,00 €' );
		expect( form.querySelector( '[data-total="options"]' ).textContent ).toBe( '12,00 €' );
		expect( msgWrap.querySelector( '.dev3po-counter' ).textContent ).toBe( '4 / 10' );
	} );

	it( 'blocks submit while a visible required field is empty', () => {
		const form = mount();
		const gift = form.querySelector( 'input[name="dev3po[f_gift]"]' );
		gift.checked = true;
		fire( gift, 'change' );
		const event = new window.Event( 'submit', { bubbles: true, cancelable: true } );
		document.querySelector( 'form' ).dispatchEvent( event );
		expect( event.defaultPrevented ).toBe( true );
		const err = form.querySelector( '[data-field="f_msg"] .dev3po-error' );
		expect( err.hidden ).toBe( false );
		expect( err.textContent ).toBe( 'Message is required.' );
		expect( form.querySelector( '[data-field="f_msg"] input' ).getAttribute( 'aria-invalid' ) ).toBe( 'true' );
	} );

	it( 'lets a form submit when the required field is hidden', () => {
		mount();
		const event = new window.Event( 'submit', { bubbles: true, cancelable: true } );
		document.querySelector( 'form' ).dispatchEvent( event );
		expect( event.defaultPrevented ).toBe( false );
	} );
} );
