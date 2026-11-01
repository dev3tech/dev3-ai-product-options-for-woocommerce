/**
 * Storefront behaviour for option forms: conditions, live price, steps, repeaters, uploads and
 * client-side validation. Vanilla JS; the server re-validates and re-prices everything.
 */
import { __, sprintf } from '@wordpress/i18n';
import { calculate, isInput, kind, isEmpty } from '../shared/engine';

const D = 'dev3-ai-product-options-for-woocommerce';

/* ----------------------------------------------------------------- money */

export function formatMoney( amount, c = {} ) {
	const decimals = Number.isInteger( c.decimals ) ? c.decimals : 2;
	const neg = amount < 0;
	const fixed = Math.abs( amount ).toFixed( decimals );
	const [ int, frac ] = fixed.split( '.' );
	const grouped = int.replace( /\B(?=(\d{3})+(?!\d))/g, c.tsep ?? ',' );
	const num = frac ? grouped + ( c.dsep ?? '.' ) + frac : grouped;
	const out = ( c.format || '%1$s%2$s' ).replace( '%1$s', c.symbol ?? '$' ).replace( '%2$s', num ).replace( /&nbsp;/g, ' ' );
	return ( neg ? '−' : '' ) + out;
}

/* ----------------------------------------------------------------- values */

function readValue( root, f, prefix ) {
	const name = prefix || `dev3po[${ f.id }]`;
	const k = kind( f.type );
	const q = ( sel ) => root.querySelector( sel );
	const esc = ( s ) => ( window.CSS && CSS.escape ? CSS.escape( s ) : s.replace( /([[\]])/g, '\\$1' ) );
	switch ( k ) {
		case 'text':
		case 'date':
		case 'color':
		case 'hidden': {
			const el = q( `[name="${ esc( name ) }"]` );
			return el ? String( el.value ).trim() : '';
		}
		case 'number': {
			const el = q( `[name="${ esc( name ) }"]` );
			return el && el.value !== '' && ! isNaN( parseFloat( el.value ) ) ? parseFloat( el.value ) : null;
		}
		case 'choice': {
			const sel = q( `select[name="${ esc( name ) }"]` );
			if ( sel ) {
				return sel.value;
			}
			const checked = q( `input[name="${ esc( name ) }"]:checked` );
			return checked ? checked.value : '';
		}
		case 'multi':
			return Array.from( root.querySelectorAll( `input[name="${ esc( name + '[]' ) }"]:checked` ) ).map( ( i ) => i.value );
		case 'bool': {
			const el = q( `input[name="${ esc( name ) }"]` );
			return !! ( el && el.checked );
		}
		case 'file': {
			const el = q( `input[name="${ esc( name ) }"]` );
			return el && el.value ? el.value.split( ',' ).filter( Boolean ).map( ( token ) => ( { token } ) ) : [];
		}
		case 'repeater': {
			const rows = [];
			root.querySelectorAll( `[data-field="${ esc( f.id ) }"] > .dev3po-repeater .dev3po-repeater__rows > .dev3po-repeater__row` ).forEach( ( row ) => {
				const i = row.getAttribute( 'data-row' );
				const values = {};
				( f.subfields || [] ).forEach( ( sub ) => {
					if ( isInput( sub.type ) ) {
						values[ sub.id ] = readValue( row, sub, `${ name }[${ i }][${ sub.id }]` );
					}
				} );
				rows.push( values );
			} );
			return rows;
		}
	}
	return '';
}

/* ----------------------------------------------------------------- validation */

function validateValue( f, value ) {
	const label = f.label || __( 'This option', D );
	if ( isEmpty( f, value ) ) {
		/* translators: %s: field label */
		return f.required ? sprintf( __( '%s is required.', D ), label ) : '';
	}
	const k = kind( f.type );
	if ( k === 'text' ) {
		const len = Array.from( value ).length;
		if ( f.maxlength && len > f.maxlength ) {
			/* translators: 1: label, 2: number */
			return sprintf( __( '%1$s can be at most %2$d characters.', D ), label, f.maxlength );
		}
		if ( f.minlength && len < f.minlength ) {
			/* translators: 1: label, 2: number */
			return sprintf( __( '%1$s needs at least %2$d characters.', D ), label, f.minlength );
		}
		if ( f.type === 'email' && ! /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test( value ) ) {
			/* translators: %s: label */
			return sprintf( __( '%s must be a valid email address.', D ), label );
		}
		if ( f.type === 'url' && ! /^https?:\/\/\S+$/i.test( value ) ) {
			/* translators: %s: label */
			return sprintf( __( '%s must be a valid URL.', D ), label );
		}
	}
	if ( k === 'number' ) {
		if ( f.min !== null && f.min !== undefined && value < f.min ) {
			/* translators: 1: label, 2: number */
			return sprintf( __( '%1$s must be at least %2$s.', D ), label, f.min );
		}
		if ( f.max !== null && f.max !== undefined && value > f.max ) {
			/* translators: 1: label, 2: number */
			return sprintf( __( '%1$s must be at most %2$s.', D ), label, f.max );
		}
	}
	if ( k === 'multi' && f.max_select && value.length > f.max_select ) {
		/* translators: 1: number, 2: label */
		return sprintf( __( 'Choose at most %1$d for %2$s.', D ), f.max_select, label );
	}
	if ( k === 'repeater' && value.length < ( f.min_rows || 0 ) ) {
		/* translators: 1: number, 2: label */
		return sprintf( __( 'Add at least %1$d entries for %2$s.', D ), f.min_rows, label );
	}
	return '';
}

/* ----------------------------------------------------------------- form */

export class OptionsForm {
	constructor( el ) {
		this.el = el;
		this.config = JSON.parse( el.getAttribute( 'data-dev3po' ) || '{}' );
		this.fields = this.config.fields || [];
		this.base = Number( this.config.base ) || 0;
		this.form = el.closest( 'form' ) || el;
		this.step = 0;
		this.touched = new Set();
		this.priced = this.fields.some( ( f ) => f.price_type !== 'none' || ( f.options || [] ).some( ( o ) => Number( o.price ) !== 0 ) || ( f.subfields || [] ).some( ( s ) => s.price_type !== 'none' || ( s.options || [] ).some( ( o ) => Number( o.price ) !== 0 ) ) );
		this.bind();
		this.initSteps();
		this.update();
		el.classList.add( 'is-ready' );
	}

	values() {
		const out = {};
		this.fields.forEach( ( f ) => {
			if ( isInput( f.type ) ) {
				out[ f.id ] = readValue( this.el, f );
			}
		} );
		return out;
	}

	qty() {
		const q = this.form.querySelector( 'input.qty, input[name="quantity"]' );
		return Math.max( 1, parseInt( q ? q.value : '1', 10 ) || 1 );
	}

	wrapper( id ) {
		return this.el.querySelector( `[data-field="${ id }"]` );
	}

	bind() {
		const onChange = ( e ) => {
			const w = e.target.closest( '[data-field]' );
			if ( w ) {
				this.touched.add( w.getAttribute( 'data-field' ) );
			}
			this.decorate( e.target );
			this.update();
		};
		this.el.addEventListener( 'input', onChange );
		this.el.addEventListener( 'change', onChange );
		this.form.addEventListener( 'input', ( e ) => {
			if ( e.target.matches && e.target.matches( 'input.qty, input[name="quantity"]' ) ) {
				this.update();
			}
		} );
		this.el.addEventListener( 'click', ( e ) => this.onClick( e ) );
		this.form.addEventListener( 'submit', ( e ) => this.onSubmit( e ) );
		this.el.querySelectorAll( 'input[type="file"]' ).forEach( ( input ) => this.bindUpload( input ) );
		this.el.querySelectorAll( '.dev3po-input[maxlength]' ).forEach( ( i ) => this.decorate( i ) );
		// Variable products: track the selected variation's price.
		const $ = window.jQuery;
		if ( $ && this.form.classList.contains( 'variations_form' ) ) {
			$( this.form ).on( 'found_variation', ( ev, variation ) => {
				this.base = Number( variation.display_price ) || this.base;
				this.update();
			} );
			$( this.form ).on( 'reset_data', () => {
				this.base = Number( this.config.base ) || 0;
				this.update();
			} );
		}
	}

	decorate( target ) {
		if ( ! target || ! target.closest ) {
			return;
		}
		if ( target.type === 'range' ) {
			const out = target.parentNode.querySelector( 'output' );
			const field = this.findField( target.closest( '[data-field]' )?.getAttribute( 'data-field' ) );
			if ( out ) {
				out.textContent = target.value + ( field && field.unit ? ' ' + field.unit : '' );
			}
		}
		if ( target.type === 'color' ) {
			const v = target.parentNode.querySelector( '.dev3po-color__value' );
			if ( v ) {
				v.textContent = target.value;
			}
		}
		if ( target.hasAttribute && target.hasAttribute( 'maxlength' ) ) {
			const counter = target.parentNode.querySelector( '.dev3po-counter' );
			if ( counter ) {
				counter.textContent = `${ Array.from( target.value ).length } / ${ target.getAttribute( 'maxlength' ) }`;
			}
		}
		if ( target.type === 'date' && target.dataset.disabledDays && target.value ) {
			const day = new Date( target.value + 'T12:00:00' ).getDay();
			if ( target.dataset.disabledDays.split( ',' ).map( Number ).includes( day ) ) {
				target.value = '';
				this.showError( target.closest( '[data-field]' ), __( 'That day is not available. Please choose another date.', D ) );
			}
		}
		const choices = target.closest( '.dev3po-choices[data-max]' );
		if ( choices ) {
			const max = Number( choices.dataset.max );
			const boxes = Array.from( choices.querySelectorAll( 'input[type="checkbox"]' ) );
			const count = boxes.filter( ( b ) => b.checked ).length;
			boxes.forEach( ( b ) => {
				b.disabled = ! b.checked && count >= max;
			} );
		}
	}

	findField( id ) {
		for ( const f of this.fields ) {
			if ( f.id === id ) {
				return f;
			}
			for ( const s of f.subfields || [] ) {
				if ( s.id === id ) {
					return s;
				}
			}
		}
		return null;
	}

	update() {
		const values = this.values();
		const result = calculate( this.fields, values, this.base, this.qty() );
		this.visible = result.visible;
		this.fields.forEach( ( f ) => {
			const w = this.wrapper( f.id );
			if ( w && f.type !== 'hidden' ) {
				w.hidden = ! result.visible[ f.id ];
				w.classList.toggle( 'is-hidden', ! result.visible[ f.id ] );
			}
		} );
		// Inputs inside hidden fields/sections/steps are disabled so they are not posted.
		this.el.querySelectorAll( 'input, select, textarea' ).forEach( ( i ) => {
			const w = i.closest( '[data-field]' );
			if ( ! w || ( w.classList.contains( 'dev3po-field--hidden' ) && ! i.closest( '.dev3po-repeater' ) ) ) {
				return;
			}
			let node = w;
			let off = false;
			while ( node && node !== this.el ) {
				if ( node.hidden && ! node.classList.contains( 'dev3po-field--hidden' ) ) {
					off = true;
					break;
				}
				node = node.parentElement ? node.parentElement.closest( '[data-field]' ) : null;
			}
			i.disabled = off;
		} );
		this.fields.filter( ( f ) => f.type === 'hidden' ).forEach( ( f ) => {
			const w = this.wrapper( f.id );
			const input = w && w.querySelector( 'input' );
			if ( input ) {
				input.disabled = ! result.visible[ f.id ];
			}
		} );
		this.el.querySelectorAll( '.dev3po-choices[data-max] input' ).forEach( ( i ) => this.decorate( i ) );
		this.renderTotals( result );
		if ( this.steps && this.steps.length ) {
			this.showStep( Math.min( this.step, this.visibleSteps().length - 1 ) );
		}
		this.el.dispatchEvent( new window.CustomEvent( 'dev3po:update', { detail: result, bubbles: true } ) );
	}

	renderTotals( result ) {
		const box = this.el.querySelector( '.dev3po-totals' );
		if ( ! box ) {
			return;
		}
		const c = this.config.currency || {};
		const qty = this.qty();
		box.hidden = ! this.priced;
		const opt = box.querySelector( '[data-total="options"]' );
		const once = box.querySelector( '[data-total="once"]' );
		const grand = box.querySelector( '[data-total="grand"]' );
		if ( opt ) {
			opt.textContent = formatMoney( result.unit * qty, c );
		}
		if ( once ) {
			once.textContent = formatMoney( result.once, c );
			once.closest( '.dev3po-totals__fees' ).hidden = result.once === 0;
		}
		if ( grand ) {
			grand.textContent = formatMoney( result.total, c );
		}
	}

	/* ------------------------------------------------------------- steps */

	initSteps() {
		this.steps = Array.from( this.el.querySelectorAll( '.dev3po-step' ) );
		if ( ! this.steps.length ) {
			return;
		}
		this.controls = this.el.querySelector( '.dev3po-step-controls' );
		this.controls.hidden = false;
		this.form.classList.add( 'dev3po-has-steps' );
		this.showStep( 0 );
	}

	visibleSteps() {
		return this.steps.filter( ( s ) => ! this.visible || this.visible[ s.getAttribute( 'data-field' ) ] !== false );
	}

	showStep( index ) {
		const steps = this.visibleSteps();
		if ( ! steps.length ) {
			return;
		}
		this.step = Math.max( 0, Math.min( index, steps.length - 1 ) );
		this.steps.forEach( ( s ) => {
			s.classList.toggle( 'is-current', s === steps[ this.step ] );
		} );
		const last = this.step === steps.length - 1;
		this.form.classList.toggle( 'dev3po-steps-incomplete', ! last );
		this.controls.querySelector( '.dev3po-step-prev' ).hidden = this.step === 0;
		this.controls.querySelector( '.dev3po-step-next' ).hidden = last;
		/* translators: 1: current step, 2: total steps */
		this.controls.querySelector( '.dev3po-step-count' ).textContent = sprintf( __( 'Step %1$d of %2$d', D ), this.step + 1, steps.length );
	}

	fieldsInStep( stepEl ) {
		return this.fields.filter( ( f ) => {
			const w = this.wrapper( f.id );
			return w && stepEl.contains( w );
		} );
	}

	/* ------------------------------------------------------------- clicks */

	onClick( e ) {
		const t = e.target;
		if ( t.closest( '.dev3po-step-next' ) ) {
			const current = this.visibleSteps()[ this.step ];
			if ( this.validate( current ? this.fieldsInStep( current ) : this.fields ) ) {
				this.showStep( this.step + 1 );
				this.focusStep();
			}
			return;
		}
		if ( t.closest( '.dev3po-step-prev' ) ) {
			this.showStep( this.step - 1 );
			this.focusStep();
			return;
		}
		if ( t.closest( '.dev3po-repeater__add' ) ) {
			this.addRow( t.closest( '.dev3po-repeater' ) );
			return;
		}
		const remove = t.closest( '.dev3po-repeater__remove' );
		if ( remove ) {
			const rep = remove.closest( '.dev3po-repeater' );
			if ( rep.querySelectorAll( '.dev3po-repeater__rows > .dev3po-repeater__row' ).length > Number( rep.dataset.min || 0 ) ) {
				remove.closest( '.dev3po-repeater__row' ).remove();
				this.renumber( rep );
				this.update();
			}
			return;
		}
		const del = t.closest( '.dev3po-upload__remove' );
		if ( del ) {
			const item = del.closest( '.dev3po-upload__item' );
			const box = del.closest( '.dev3po-upload' );
			this.setTokens( box, this.tokens( box ).filter( ( x ) => x !== item.dataset.token ) );
			item.remove();
			this.update();
		}
	}

	focusStep() {
		const current = this.visibleSteps()[ this.step ];
		const title = current && current.querySelector( '.dev3po-step__title' );
		if ( title ) {
			title.setAttribute( 'tabindex', '-1' );
			title.focus();
		}
	}

	addRow( rep ) {
		const rows = rep.querySelector( '.dev3po-repeater__rows' );
		const count = rows.children.length;
		if ( count >= Number( rep.dataset.max || 50 ) ) {
			return;
		}
		const tpl = rep.querySelector( 'template' );
		const next = 1 + Math.max( -1, ...Array.from( rows.children ).map( ( r ) => Number( r.dataset.row ) ) );
		const html = tpl.innerHTML.replace( /__ROW__/g, String( next ) ).replace( /__NUM__/g, String( count + 1 ) );
		rows.insertAdjacentHTML( 'beforeend', html );
		const row = rows.lastElementChild;
		row.querySelectorAll( 'input[type="file"]' ).forEach( ( input ) => this.bindUpload( input ) );
		this.renumber( rep );
		const first = row.querySelector( 'input, select, textarea' );
		if ( first ) {
			first.focus();
		}
		this.update();
	}

	renumber( rep ) {
		const rows = Array.from( rep.querySelectorAll( '.dev3po-repeater__rows > .dev3po-repeater__row' ) );
		rows.forEach( ( r, i ) => {
			const n = r.querySelector( '.dev3po-repeater__num' );
			if ( n ) {
				n.textContent = '#' + ( i + 1 );
			}
			const btn = r.querySelector( '.dev3po-repeater__remove' );
			if ( btn ) {
				btn.disabled = rows.length <= Number( rep.dataset.min || 0 );
			}
		} );
		const add = rep.querySelector( '.dev3po-repeater__add' );
		if ( add ) {
			add.disabled = rows.length >= Number( rep.dataset.max || 50 );
		}
	}

	/* ------------------------------------------------------------- validation */

	showError( w, message ) {
		if ( ! w ) {
			return;
		}
		const err = w.querySelector( ':scope > .dev3po-error' );
		w.classList.toggle( 'has-error', !! message );
		if ( err ) {
			err.textContent = message;
			err.hidden = ! message;
		}
		w.querySelectorAll( 'input:not([type="hidden"]), select, textarea, fieldset' ).forEach( ( i ) => {
			if ( message ) {
				i.setAttribute( 'aria-invalid', 'true' );
			} else {
				i.removeAttribute( 'aria-invalid' );
			}
		} );
	}

	validate( list ) {
		const values = this.values();
		let first = null;
		list.forEach( ( f ) => {
			if ( ! isInput( f.type ) || f.type === 'hidden' ) {
				return;
			}
			const w = this.wrapper( f.id );
			const visible = this.visible ? this.visible[ f.id ] : true;
			let message = visible ? validateValue( f, values[ f.id ] ) : '';
			if ( ! message && visible && f.type === 'repeater' ) {
				( values[ f.id ] || [] ).some( ( row ) => ( f.subfields || [] ).some( ( sub ) => {
					message = isInput( sub.type ) ? validateValue( sub, row[ sub.id ] ) : '';
					return !! message;
				} ) );
			}
			if ( w && w.querySelector( '.dev3po-upload__item.is-busy' ) ) {
				message = __( 'Please wait until the upload has finished.', D );
			}
			this.showError( w, message );
			if ( message && ! first ) {
				first = w;
			}
		} );
		if ( first ) {
			const focusable = first.querySelector( 'input:not([type="hidden"]):not([disabled]), select, textarea' );
			if ( focusable ) {
				focusable.focus();
			}
			if ( first.scrollIntoView ) {
				first.scrollIntoView( { block: 'center', behavior: 'smooth' } );
			}
		}
		return ! first;
	}

	onSubmit( e ) {
		if ( ! this.validate( this.fields ) ) {
			e.preventDefault();
			e.stopImmediatePropagation();
			if ( this.steps && this.steps.length ) {
				const bad = this.el.querySelector( '.dev3po-field.has-error' );
				const step = bad && bad.closest( '.dev3po-step' );
				if ( step ) {
					this.showStep( this.visibleSteps().indexOf( step ) );
				}
			}
		}
	}

	/* ------------------------------------------------------------- uploads */

	tokens( box ) {
		const hidden = box.querySelector( '.dev3po-upload__tokens' );
		return hidden && hidden.value ? hidden.value.split( ',' ).filter( Boolean ) : [];
	}

	setTokens( box, list ) {
		const hidden = box.querySelector( '.dev3po-upload__tokens' );
		hidden.value = list.join( ',' );
	}

	bindUpload( input ) {
		const box = input.closest( '.dev3po-upload' );
		if ( ! box || box.dataset.bound ) {
			return;
		}
		box.dataset.bound = '1';
		input.addEventListener( 'change', () => {
			Array.from( input.files || [] ).forEach( ( file ) => this.upload( box, input, file ) );
			input.value = '';
		} );
		const drop = box.querySelector( '.dev3po-upload__drop' );
		[ 'dragenter', 'dragover' ].forEach( ( ev ) =>
			drop.addEventListener( ev, ( e ) => {
				e.preventDefault();
				box.classList.add( 'is-dragging' );
			} )
		);
		[ 'dragleave', 'drop' ].forEach( ( ev ) =>
			drop.addEventListener( ev, ( e ) => {
				e.preventDefault();
				box.classList.remove( 'is-dragging' );
			} )
		);
		drop.addEventListener( 'drop', ( e ) => {
			Array.from( ( e.dataTransfer && e.dataTransfer.files ) || [] ).forEach( ( file ) => this.upload( box, input, file ) );
		} );
	}

	upload( box, input, file ) {
		const w = box.closest( '[data-field]' );
		const fieldId = w.getAttribute( 'data-field' );
		const max = Number( box.dataset.maxFiles || 1 );
		const list = box.querySelector( '.dev3po-upload__list' );
		if ( list.children.length >= max ) {
			/* translators: %d: number of files */
			this.showError( w, sprintf( __( 'You can upload up to %d files.', D ), max ) );
			return;
		}
		const accept = ( input.getAttribute( 'accept' ) || '' ).split( ',' ).map( ( a ) => a.trim().toLowerCase() ).filter( Boolean );
		const ext = '.' + ( file.name.split( '.' ).pop() || '' ).toLowerCase();
		if ( accept.length && ! accept.includes( ext ) ) {
			/* translators: %s: allowed extensions */
			this.showError( w, sprintf( __( 'This file type is not allowed. Use: %s.', D ), accept.join( ', ' ).toUpperCase() ) );
			return;
		}
		const maxMb = Number( box.dataset.maxSize || 5 );
		if ( file.size > maxMb * 1048576 ) {
			/* translators: %s: size in MB */
			this.showError( w, sprintf( __( 'The file is too large. The limit is %s MB.', D ), maxMb ) );
			return;
		}
		this.showError( w, '' );
		const item = document.createElement( 'li' );
		item.className = 'dev3po-upload__item is-busy';
		const isImage = /^image\//.test( file.type );
		const thumb = isImage && window.URL ? `<img class="dev3po-upload__thumb" src="${ URL.createObjectURL( file ) }" alt="" width="48" height="48">` : '<span class="dev3po-upload__thumb dev3po-upload__thumb--file" aria-hidden="true"></span>';
		item.innerHTML = `${ thumb }<span class="dev3po-upload__meta"><span class="dev3po-upload__name"></span><span class="dev3po-upload__status" role="status">${ __( 'Uploading…', D ) }</span><span class="dev3po-upload__bar"><span></span></span></span><button type="button" class="dev3po-upload__remove" aria-label="${ __( 'Remove file', D ) }">&times;</button>`;
		item.querySelector( '.dev3po-upload__name' ).textContent = file.name;
		list.appendChild( item );
		const status = item.querySelector( '.dev3po-upload__status' );
		const bar = item.querySelector( '.dev3po-upload__bar span' );
		const data = new window.FormData();
		data.append( 'file', file );
		data.append( 'product_id', String( this.config.product ) );
		data.append( 'field_id', fieldId );
		const xhr = new window.XMLHttpRequest();
		xhr.open( 'POST', this.config.upload );
		xhr.withCredentials = true;
		xhr.upload.onprogress = ( ev ) => {
			if ( ev.lengthComputable ) {
				bar.style.width = Math.round( ( ev.loaded / ev.total ) * 100 ) + '%';
			}
		};
		xhr.onload = () => {
			let body = {};
			try {
				body = JSON.parse( xhr.responseText || '{}' );
			} catch ( err ) {
				body = {};
			}
			item.classList.remove( 'is-busy' );
			if ( xhr.status >= 200 && xhr.status < 300 && body.token ) {
				item.dataset.token = body.token;
				item.classList.add( 'is-done' );
				const check = body.check || {};
				item.classList.toggle( 'is-warn', check.status === 'warn' );
				status.textContent = check.message || ( body.width ? `${ body.width }×${ body.height } px` : __( 'Uploaded', D ) );
				this.setTokens( box, [ ...this.tokens( box ), body.token ] );
			} else {
				item.classList.add( 'is-error' );
				status.textContent = body.message || __( 'The file could not be uploaded. Please try again.', D );
				status.setAttribute( 'role', 'alert' );
			}
			this.update();
		};
		xhr.onerror = () => {
			item.classList.remove( 'is-busy' );
			item.classList.add( 'is-error' );
			status.textContent = __( 'The file could not be uploaded. Please try again.', D );
		};
		xhr.send( data );
	}
}

export function init( root = document ) {
	root.querySelectorAll( '.dev3po-form:not(.is-ready)' ).forEach( ( el ) => {
		el.dev3po = new OptionsForm( el );
	} );
}

if ( typeof document !== 'undefined' ) {
	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', () => init() );
	} else {
		init();
	}
	// Quick-view plugins and the admin preview inject forms later.
	document.addEventListener( 'dev3po:init', () => init() );
}
