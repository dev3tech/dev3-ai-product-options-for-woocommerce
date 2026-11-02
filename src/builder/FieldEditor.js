/**
 * Drawer that edits one field: General · Choices · Pricing · Logic · Advanced.
 */
import { useMemo, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { request } from '../core3/api';
import { AiButton, AiDraftReview, Badge, Button, Checkbox, Drawer, ErrorNotice, FormGrid, IconButton, Inline, Notice, NumberField, Select, Stack, Tabs, Textarea, TextField, Toggle } from '../core3/ui';
import { compile, evaluate } from '../shared/formula';
import { useBuilder } from './context';
import { hasOptions, isInput, kind, needsValue, newOption, OPERATORS, operatorsFor, PRICE_TYPES, priceTypesFor, slugKey, typeMeta, uniqueKey } from './model';

const D = 'dev3-ai-product-options-for-woocommerce';
const num = ( v ) => ( v === '' || v === null || v === undefined ? null : Number( v ) );

/* ------------------------------------------------------------------ choices */

function OptionsEditor( { field, onChange } ) {
	const { money } = useBuilder();
	const [ bulk, setBulk ] = useState( '' );
	const [ showBulk, setShowBulk ] = useState( false );
	const options = field.options || [];
	const set = ( i, patch ) => onChange( { options: options.map( ( o, j ) => ( j === i ? { ...o, ...patch } : patch.default && field.type !== 'checkboxes' ? { ...o, default: false } : o ) ) } );
	const move = ( i, d ) => {
		const next = [ ...options ];
		const [ item ] = next.splice( i, 1 );
		next.splice( i + d, 0, item );
		onChange( { options: next } );
	};
	const media = ( i ) => {
		if ( ! window.wp || ! window.wp.media ) {
			return;
		}
		const frame = window.wp.media( { title: __( 'Choose image', D ), multiple: false, library: { type: 'image' } } );
		frame.on( 'select', () => {
			const att = frame.state().get( 'selection' ).first().toJSON();
			set( i, { image: ( att.sizes && att.sizes.medium ? att.sizes.medium.url : att.url ) || '' } );
		} );
		frame.open();
	};
	const addBulk = () => {
		const added = bulk
			.split( '\n' )
			.map( ( line ) => line.trim() )
			.filter( Boolean )
			.map( ( line ) => {
				const [ label, price ] = line.split( '|' ).map( ( s ) => s.trim() );
				return newOption( label, Number( price ) || 0 );
			} );
		onChange( { options: [ ...options, ...added ] } );
		setBulk( '' );
		setShowBulk( false );
	};
	const swatch = field.type === 'color_swatch';
	const images = [ 'image_swatch', 'image_picker' ].includes( field.type );

	return (
		<Stack gap={ 12 }>
			<ul className="dev3po-b-options" aria-label={ __( 'Choices', D ) }>
				{ options.map( ( o, i ) => (
					<li key={ o.id } className="dev3po-b-option">
						<div className="dev3po-b-option__main">
							{ swatch && <span className="dev3po-b-chip" style={ { background: o.color || '#ccc' } } aria-hidden="true" /> }
							{ images && ( o.image ? <img className="dev3po-b-thumb" src={ o.image } alt="" /> : <span className="dev3po-b-thumb is-empty" aria-hidden="true" /> ) }
							<TextField label={ __( 'Label', D ) } value={ o.label } onChange={ ( v ) => set( i, { label: v } ) } />
							<NumberField label={ __( 'Price', D ) } value={ o.price } step={ 0.01 } onChange={ ( v ) => set( i, { price: v === '' ? 0 : v } ) } />
							<Select
								label={ __( 'Price type', D ) }
								value={ o.price_type }
								onChange={ ( v ) => set( i, { price_type: v } ) }
								options={ [
									{ value: 'flat', label: __( 'Flat', D ) },
									{ value: 'percent', label: '%' },
								] }
							/>
						</div>
						<div className="dev3po-b-option__extra">
							<TextField label={ __( 'Value', D ) } value={ o.value } onChange={ ( v ) => set( i, { value: v } ) } help={ __( 'Used in formulas when numeric.', D ) } />
							{ swatch && <TextField label={ __( 'Colour', D ) } type="color" value={ o.color || '#cccccc' } onChange={ ( v ) => set( i, { color: v } ) } /> }
							{ images && (
								<div className="dev3po-b-option__media">
									<TextField label={ __( 'Image URL', D ) } value={ o.image } onChange={ ( v ) => set( i, { image: v } ) } />
									{ window.wp && window.wp.media && (
										<Button size="sm" onClick={ () => media( i ) }>
											{ __( 'Media library', D ) }
										</Button>
									) }
								</div>
							) }
							<Checkbox label={ __( 'Selected by default', D ) } checked={ !! o.default } onChange={ ( v ) => set( i, { default: v } ) } />
							<Inline gap={ 4 } className="dev3po-b-option__actions">
								<IconButton icon="chevronLeft" label={ __( 'Move up', D ) } onClick={ () => move( i, -1 ) } disabled={ i === 0 } className="dev3po-b-rot" />
								<IconButton icon="chevronRight" label={ __( 'Move down', D ) } onClick={ () => move( i, 1 ) } disabled={ i === options.length - 1 } className="dev3po-b-rot" />
								<IconButton icon="trash" label={ sprintf( /* translators: %s: option */ __( 'Remove %s', D ), o.label || __( 'choice', D ) ) } onClick={ () => onChange( { options: options.filter( ( _, j ) => j !== i ) } ) } />
							</Inline>
						</div>
						{ o.price !== 0 && <p className="dev3po-b-option__hint">{ o.price_type === 'percent' ? `+${ o.price }%` : `+${ money( o.price ) }` }</p> }
					</li>
				) ) }
			</ul>
			<Inline gap={ 8 }>
				<Button icon="plus" onClick={ () => onChange( { options: [ ...options, newOption( sprintf( /* translators: %d: number */ __( 'Option %d', D ), options.length + 1 ) ) ] } ) }>
					{ __( 'Add choice', D ) }
				</Button>
				<Button variant="ghost" icon="list" onClick={ () => setShowBulk( ! showBulk ) } aria-expanded={ showBulk }>
					{ __( 'Bulk add', D ) }
				</Button>
			</Inline>
			{ showBulk && (
				<Stack gap={ 8 }>
					<Textarea label={ __( 'One choice per line: Label | price', D ) } value={ bulk } onChange={ setBulk } rows={ 4 } placeholder={ 'Small | 0\nMedium | 3\nLarge | 6' } />
					<div>
						<Button variant="primary" onClick={ addBulk } disabled={ ! bulk.trim() }>
							{ __( 'Add choices', D ) }
						</Button>
					</div>
				</Stack>
			) }
			{ field.type === 'checkboxes' && <NumberField label={ __( 'Maximum selections (0 = no limit)', D ) } value={ field.max_select || 0 } min={ 0 } onChange={ ( v ) => onChange( { max_select: v === '' ? 0 : v } ) } /> }
		</Stack>
	);
}

/* ------------------------------------------------------------------ pricing */

function FormulaHelper( { field, fields, onAccept } ) {
	const { boot } = useBuilder();
	const [ rule, setRule ] = useState( '' );
	const [ busy, setBusy ] = useState( false );
	const [ error, setError ] = useState( null );
	const [ draft, setDraft ] = useState( null );
	const off = boot.features && boot.features.ai_formula === false;
	const ask = () => {
		setBusy( true );
		setError( null );
		request( 'ai/formula', { method: 'POST', data: { rule, field_id: field.id, fields } } )
			.then( setDraft )
			.catch( setError )
			.finally( () => setBusy( false ) );
	};
	if ( off ) {
		return null;
	}
	return (
		<div className="dev3po-b-ai">
			<p className="dev3po-b-ai__title">{ __( 'Formula helper · DEV3 AI', D ) }</p>
			<Textarea label={ __( 'Describe the rule in plain words', D ) } value={ rule } onChange={ setRule } rows={ 2 } maxLength={ 400 } placeholder={ __( 'e.g. add 2 per letter over 10 letters', D ) } />
			<div>
				<AiButton onClick={ ask } loading={ busy && ! draft } disabled={ ! rule.trim() || busy }>
					{ __( 'Write formula with DEV3 AI', D ) }
				</AiButton>
			</div>
			<ErrorNotice error={ error } onRetry={ ask } onDismiss={ () => setError( null ) } />
			{ draft && (
				<>
					<AiDraftReview
						title={ __( 'DEV3 AI formula', D ) }
						draft={ draft.formula }
						original={ field.formula || undefined }
						meta={ { model: draft.model, tokens: draft.tokens, cached: draft.cached } }
						onAccept={ ( v ) => {
							onAccept( v );
							setDraft( null );
						} }
						onReject={ () => setDraft( null ) }
						onRegenerate={ ask }
						regenerating={ busy }
						acceptLabel={ __( 'Use formula', D ) }
					/>
					{ draft.explanation && <p className="dev3po-b-muted">{ draft.explanation }</p> }
					{ draft.examples && draft.examples.length > 0 && (
						<table className="dev3po-b-mini">
							<caption className="dev3po-ui-sr-only">{ __( 'Worked examples', D ) }</caption>
							<tbody>
								{ draft.examples.map( ( ex ) => (
									<tr key={ ex.input }>
										<th scope="row">{ ex.input }</th>
										<td>{ ex.price }</td>
									</tr>
								) ) }
							</tbody>
						</table>
					) }
				</>
			) }
		</div>
	);
}

function FormulaField( { field, fields, onChange } ) {
	const compiled = useMemo( () => compile( field.formula ), [ field.formula ] );
	const keys = fields.filter( ( f ) => isInput( f.type ) && f.id !== field.id ).map( ( f ) => f.key );
	const unknown = compiled.ok ? compiled.vars.filter( ( v ) => ! [ 'price', 'qty', 'value', 'len', 'words', 'lines', 'count' ].includes( v ) && ! keys.includes( v.split( '.' )[ 0 ] ) ) : [];
	const [ test, setTest ] = useState( { value: 5, len: 12, price: 20, qty: 1 } );
	const result = compiled.ok ? evaluate( field.formula, { ...test, words: 2, lines: 1, count: 1 } ) : 0;
	let error = '';
	if ( field.formula && ! compiled.ok ) {
		error = __( 'The formula has a syntax error.', D ) + ` (${ compiled.error })`;
	} else if ( unknown.length ) {
		/* translators: %s: variable names */
		error = sprintf( __( 'Unknown variables: %s', D ), unknown.map( ( v ) => `{${ v }}` ).join( ', ' ) );
	}
	const insert = ( token ) => onChange( { formula: `${ field.formula || '' }${ field.formula && ! / $/.test( field.formula ) ? ' ' : '' }${ token }` } );
	return (
		<Stack gap={ 10 }>
			<Textarea label={ __( 'Formula', D ) } value={ field.formula } onChange={ ( v ) => onChange( { formula: v } ) } rows={ 2 } className="is-mono" error={ error } help={ __( 'Result = add-on price per item. Functions: min, max, round, ceil, floor, abs, sqrt, pow, if, clamp.', D ) } />
			<div className="dev3po-b-vars" role="group" aria-label={ __( 'Insert variable', D ) }>
				{ [ 'price', 'qty', 'value', 'len', 'words', 'count', ...keys ].map( ( v ) => (
					<button type="button" key={ v } className="dev3po-b-var" onClick={ () => insert( `{${ v }}` ) }>
						{ `{${ v }}` }
					</button>
				) ) }
			</div>
			<div className="dev3po-b-test">
				<NumberField label="{price}" value={ test.price } onChange={ ( v ) => setTest( { ...test, price: v === '' ? 0 : v } ) } />
				<NumberField label="{value}" value={ test.value } onChange={ ( v ) => setTest( { ...test, value: v === '' ? 0 : v } ) } />
				<NumberField label="{len}" value={ test.len } onChange={ ( v ) => setTest( { ...test, len: v === '' ? 0 : v } ) } />
				<NumberField label="{qty}" value={ test.qty } onChange={ ( v ) => setTest( { ...test, qty: v === '' ? 1 : v } ) } />
				<p className="dev3po-b-test__out" aria-live="polite">
					<span>{ __( 'Result', D ) }</span>
					<strong>{ compiled.ok ? Math.round( result * 100 ) / 100 : '—' }</strong>
				</p>
			</div>
			<FormulaHelper field={ field } fields={ fields } onAccept={ ( formula ) => onChange( { formula } ) } />
		</Stack>
	);
}

function PricingEditor( { field, fields, onChange } ) {
	const types = priceTypesFor( field.type );
	const opts = hasOptions( field.type );
	return (
		<Stack gap={ 12 }>
			{ opts && <Notice tone="info">{ __( 'Each choice has its own price on the Choices tab. You can add a formula on top here.', D ) }</Notice> }
			<FormGrid>
				<Select label={ __( 'Price type', D ) } value={ field.price_type } onChange={ ( v ) => onChange( { price_type: v } ) } options={ types.map( ( t ) => ( { value: t, label: PRICE_TYPES[ t ] } ) ) } />
				{ [ 'flat', 'percent', 'per_char', 'per_unit' ].includes( field.price_type ) && (
					<NumberField label={ field.price_type === 'percent' ? __( 'Percent', D ) : __( 'Price', D ) } value={ field.price } step={ 0.01 } onChange={ ( v ) => onChange( { price: v === '' ? 0 : v } ) } help={ field.price < 0 ? __( 'Negative = discount.', D ) : '' } />
				) }
				{ field.price_type !== 'none' || opts ? (
					<Select
						label={ __( 'Charge', D ) }
						value={ field.charge }
						onChange={ ( v ) => onChange( { charge: v } ) }
						options={ [
							{ value: 'item', label: __( 'Per item (× quantity)', D ) },
							{ value: 'once', label: __( 'Once per cart line', D ) },
						] }
					/>
				) : null }
			</FormGrid>
			{ field.price_type === 'per_char' && (
				<FormGrid>
					<NumberField label={ __( 'Free characters', D ) } value={ field.free_chars } min={ 0 } onChange={ ( v ) => onChange( { free_chars: v === '' ? 0 : v } ) } />
					<Toggle label={ __( 'Count spaces', D ) } checked={ !! field.count_space } onChange={ ( v ) => onChange( { count_space: v } ) } />
				</FormGrid>
			) }
			{ field.price_type === 'qty_tiers' && (
				<Stack gap={ 8 }>
					{ ( field.tiers || [] ).map( ( t, i ) => (
						<div className="dev3po-b-tier" key={ i }>
							<NumberField label={ __( 'From quantity', D ) } value={ t.min } min={ 1 } onChange={ ( v ) => onChange( { tiers: field.tiers.map( ( x, j ) => ( j === i ? { ...x, min: v === '' ? 1 : v } : x ) ) } ) } />
							<NumberField label={ __( 'Price per item', D ) } value={ t.price } step={ 0.01 } onChange={ ( v ) => onChange( { tiers: field.tiers.map( ( x, j ) => ( j === i ? { ...x, price: v === '' ? 0 : v } : x ) ) } ) } />
							<IconButton icon="trash" label={ __( 'Remove tier', D ) } onClick={ () => onChange( { tiers: field.tiers.filter( ( _, j ) => j !== i ) } ) } />
						</div>
					) ) }
					<div>
						<Button icon="plus" onClick={ () => onChange( { tiers: [ ...( field.tiers || [] ), { min: ( field.tiers || [] ).length ? Number( field.tiers[ field.tiers.length - 1 ].min ) + 10 : 1, price: 0 } ] } ) }>
							{ __( 'Add tier', D ) }
						</Button>
					</div>
				</Stack>
			) }
			{ field.price_type === 'formula' && <FormulaField field={ field } fields={ fields } onChange={ onChange } /> }
		</Stack>
	);
}

/* ------------------------------------------------------------------ logic */

function ConditionsEditor( { field, fields, onChange } ) {
	const c = field.conditions || { enabled: false, action: 'show', match: 'all', rules: [] };
	const targets = fields.filter( ( f ) => f.id !== field.id && isInput( f.type ) && f.type !== 'hidden' );
	const setC = ( patch ) => onChange( { conditions: { ...c, ...patch } } );
	const setRule = ( i, patch ) => setC( { rules: c.rules.map( ( r, j ) => ( j === i ? { ...r, ...patch } : r ) ) } );
	const addRule = () => {
		const t = targets[ 0 ];
		setC( { enabled: true, rules: [ ...c.rules, { field: t ? t.id : '', op: t ? operatorsFor( t.type )[ 0 ] : 'is', value: t && t.options && t.options[ 0 ] ? t.options[ 0 ].id : '' } ] } );
	};
	if ( ! targets.length ) {
		return <Notice tone="info">{ __( 'Add another field first: rules show or hide this field based on other fields.', D ) }</Notice>;
	}
	return (
		<Stack gap={ 12 }>
			<Toggle label={ __( 'Use conditional logic', D ) } checked={ !! c.enabled } onChange={ ( v ) => ( v && ! c.rules.length ? addRule() : setC( { enabled: v } ) ) } />
			{ c.enabled && (
				<>
					<FormGrid>
						<Select
							label={ __( 'Action', D ) }
							value={ c.action }
							onChange={ ( v ) => setC( { action: v } ) }
							options={ [
								{ value: 'show', label: __( 'Show this field', D ) },
								{ value: 'hide', label: __( 'Hide this field', D ) },
							] }
						/>
						<Select
							label={ __( 'When', D ) }
							value={ c.match }
							onChange={ ( v ) => setC( { match: v } ) }
							options={ [
								{ value: 'all', label: __( 'All rules match', D ) },
								{ value: 'any', label: __( 'Any rule matches', D ) },
							] }
						/>
					</FormGrid>
					<ol className="dev3po-b-rules">
						{ c.rules.map( ( r, i ) => {
							const t = fields.find( ( f ) => f.id === r.field );
							const ops = t ? operatorsFor( t.type ) : Object.keys( OPERATORS );
							return (
								<li className="dev3po-b-rule" key={ i }>
									<Select
										label={ __( 'Field', D ) }
										value={ r.field }
										onChange={ ( v ) => {
											const nt = fields.find( ( f ) => f.id === v );
											setRule( i, { field: v, op: nt ? operatorsFor( nt.type )[ 0 ] : 'is', value: nt && nt.options && nt.options[ 0 ] ? nt.options[ 0 ].id : '' } );
										} }
										options={ [ ...( t ? [] : [ { value: r.field, label: __( '(missing field)', D ) } ] ), ...targets.map( ( f ) => ( { value: f.id, label: f.label || f.key } ) ) ] }
									/>
									<Select label={ __( 'Operator', D ) } value={ r.op } onChange={ ( v ) => setRule( i, { op: v } ) } options={ ops.map( ( o ) => ( { value: o, label: OPERATORS[ o ] } ) ) } />
									{ needsValue( r.op ) &&
										( t && t.options && [ 'is', 'is_not', 'contains', 'not_contains' ].includes( r.op ) ? (
											<Select label={ __( 'Value', D ) } value={ r.value } onChange={ ( v ) => setRule( i, { value: v } ) } options={ t.options.map( ( o ) => ( { value: o.id, label: o.label } ) ) } />
										) : (
											<TextField label={ __( 'Value', D ) } value={ r.value } onChange={ ( v ) => setRule( i, { value: v } ) } inputMode={ [ 'gt', 'lt', 'gte', 'lte' ].includes( r.op ) ? 'decimal' : undefined } />
										) ) }
									<IconButton icon="trash" label={ __( 'Remove rule', D ) } onClick={ () => setC( { rules: c.rules.filter( ( _, j ) => j !== i ), enabled: c.rules.length > 1 } ) } />
								</li>
							);
						} ) }
					</ol>
					<div>
						<Button icon="plus" onClick={ addRule }>
							{ __( 'Add rule', D ) }
						</Button>
					</div>
				</>
			) }
		</Stack>
	);
}

/* ------------------------------------------------------------------ general */

const DAYS = [ __( 'Sun', D ), __( 'Mon', D ), __( 'Tue', D ), __( 'Wed', D ), __( 'Thu', D ), __( 'Fri', D ), __( 'Sat', D ) ];
const FILE_TYPES = [ 'jpg', 'jpeg', 'png', 'webp', 'gif', 'pdf', 'txt', 'zip' ];

function GeneralEditor( { field, fields, onChange } ) {
	const k = kind( field.type );
	const layout = k === 'layout';
	return (
		<Stack gap={ 12 }>
			<FormGrid>
				<TextField label={ layout ? __( 'Heading', D ) : __( 'Label', D ) } value={ field.label } onChange={ ( v ) => onChange( { label: v } ) } maxLength={ 120 } required={ ! layout } />
				{ ! layout && (
					<TextField
						label={ __( 'Key', D ) }
						value={ field.key }
						onChange={ ( v ) => onChange( { key: slugKey( v ) } ) }
						onBlur={ () => onChange( { key: uniqueKey( field.key || slugKey( field.label ), fields, field.id ) } ) }
						help={ __( 'Used in formulas as {key}.', D ) }
						className="is-mono"
					/>
				) }
			</FormGrid>
			{ [ 'section', 'step', 'paragraph' ].includes( field.type ) && <Textarea label={ __( 'Description', D ) } value={ field.description || '' } onChange={ ( v ) => onChange( { description: v } ) } rows={ 3 } /> }
			{ field.type === 'section' && <Toggle label={ __( 'Collapsible', D ) } checked={ !! field.collapsible } onChange={ ( v ) => onChange( { collapsible: v } ) } /> }
			{ ! layout && field.type !== 'hidden' && (
				<>
					<Textarea label={ __( 'Help text', D ) } value={ field.help } onChange={ ( v ) => onChange( { help: v } ) } rows={ 2 } maxLength={ 300 } />
					<FormGrid>
						<TextField label={ __( 'Tooltip', D ) } value={ field.tooltip } onChange={ ( v ) => onChange( { tooltip: v } ) } maxLength={ 300 } />
						{ [ 'text', 'number', 'date' ].includes( k ) || field.type === 'select' ? <TextField label={ __( 'Placeholder', D ) } value={ field.placeholder } onChange={ ( v ) => onChange( { placeholder: v } ) } maxLength={ 120 } /> : null }
					</FormGrid>
					<FormGrid>
						<Toggle label={ __( 'Required', D ) } checked={ !! field.required } onChange={ ( v ) => onChange( { required: v } ) } />
						<Select
							label={ __( 'Width', D ) }
							value={ field.width }
							onChange={ ( v ) => onChange( { width: v } ) }
							options={ [
								{ value: 'full', label: __( 'Full width', D ) },
								{ value: 'half', label: __( 'Half width (desktop)', D ) },
							] }
						/>
					</FormGrid>
				</>
			) }
			{ ( [ 'text', 'number', 'date', 'color', 'hidden' ].includes( k ) || k === 'bool' ) && (
				<TextField label={ field.type === 'hidden' ? __( 'Value', D ) : __( 'Default value', D ) } value={ String( field.default ?? '' ) } onChange={ ( v ) => onChange( { default: v } ) } help={ k === 'bool' ? __( 'Type "yes" to tick it by default.', D ) : '' } />
			) }
			{ k === 'number' && (
				<FormGrid>
					<NumberField label={ __( 'Minimum', D ) } value={ field.min ?? '' } onChange={ ( v ) => onChange( { min: num( v ) } ) } />
					<NumberField label={ __( 'Maximum', D ) } value={ field.max ?? '' } onChange={ ( v ) => onChange( { max: num( v ) } ) } />
					<NumberField label={ __( 'Step', D ) } value={ field.step ?? '' } step={ 0.01 } onChange={ ( v ) => onChange( { step: num( v ) } ) } />
					<TextField label={ __( 'Unit', D ) } value={ field.unit || '' } onChange={ ( v ) => onChange( { unit: v } ) } maxLength={ 12 } placeholder="cm" />
				</FormGrid>
			) }
			{ k === 'text' && (
				<FormGrid>
					<NumberField label={ __( 'Max length (0 = none)', D ) } value={ field.maxlength || 0 } min={ 0 } onChange={ ( v ) => onChange( { maxlength: v === '' ? 0 : v } ) } />
					<NumberField label={ __( 'Min length', D ) } value={ field.minlength || 0 } min={ 0 } onChange={ ( v ) => onChange( { minlength: v === '' ? 0 : v } ) } />
				</FormGrid>
			) }
			{ k === 'date' && field.type !== 'time' && (
				<>
					<FormGrid>
						<NumberField label={ __( 'Earliest (days from today)', D ) } value={ field.date_min ?? '' } onChange={ ( v ) => onChange( { date_min: num( v ) } ) } />
						<NumberField label={ __( 'Latest (days from today)', D ) } value={ field.date_max ?? '' } onChange={ ( v ) => onChange( { date_max: num( v ) } ) } />
					</FormGrid>
					<fieldset className="dev3po-b-days">
						<legend>{ __( 'Unavailable weekdays', D ) }</legend>
						{ DAYS.map( ( d, i ) => (
							<Checkbox key={ d } label={ d } checked={ ( field.disabled_days || [] ).includes( i ) } onChange={ ( v ) => onChange( { disabled_days: v ? [ ...( field.disabled_days || [] ), i ] : ( field.disabled_days || [] ).filter( ( x ) => x !== i ) } ) } />
						) ) }
					</fieldset>
				</>
			) }
			{ field.type === 'file' && (
				<>
					<fieldset className="dev3po-b-days">
						<legend>{ __( 'Allowed file types', D ) }</legend>
						{ FILE_TYPES.map( ( ext ) => {
							const list = String( field.accept || '' ).split( ',' ).filter( Boolean );
							return <Checkbox key={ ext } label={ ext.toUpperCase() } checked={ list.includes( ext ) } onChange={ ( v ) => onChange( { accept: ( v ? [ ...list, ext ] : list.filter( ( x ) => x !== ext ) ).join( ',' ) } ) } />;
						} ) }
					</fieldset>
					<FormGrid>
						<NumberField label={ __( 'Max files', D ) } value={ field.max_files } min={ 1 } max={ 10 } onChange={ ( v ) => onChange( { max_files: v === '' ? 1 : v } ) } />
						<NumberField label={ __( 'Max size (MB)', D ) } value={ field.max_size } min={ 0.1 } step={ 0.5 } onChange={ ( v ) => onChange( { max_size: v === '' ? 5 : v } ) } />
						<NumberField label={ __( 'Min width (px)', D ) } value={ field.min_width } min={ 0 } onChange={ ( v ) => onChange( { min_width: v === '' ? 0 : v } ) } />
						<NumberField label={ __( 'Min height (px)', D ) } value={ field.min_height } min={ 0 } onChange={ ( v ) => onChange( { min_height: v === '' ? 0 : v } ) } />
					</FormGrid>
					<div className="dev3po-b-ai">
						<Toggle label={ __( 'Check uploaded images with DEV3 AI vision', D ) } checked={ !! field.vision } onChange={ ( v ) => onChange( { vision: v } ) } help={ __( 'DEV3 AI looks at each uploaded image and tells the shopper when it does not fit. Resolution is always checked, also without AI.', D ) } />
						{ field.vision && <Textarea label={ __( 'What should the image show?', D ) } value={ field.vision_rule } onChange={ ( v ) => onChange( { vision_rule: v } ) } rows={ 2 } maxLength={ 300 } placeholder={ __( 'e.g. A clear photo of one pet facing the camera, no text', D ) } /> }
					</div>
				</>
			) }
			{ field.type === 'repeater' && (
				<FormGrid>
					<NumberField label={ __( 'Min entries', D ) } value={ field.min_rows } min={ 0 } onChange={ ( v ) => onChange( { min_rows: v === '' ? 0 : v } ) } />
					<NumberField label={ __( 'Max entries', D ) } value={ field.max_rows } min={ 1 } onChange={ ( v ) => onChange( { max_rows: v === '' ? 1 : v } ) } />
					<TextField label={ __( 'Add button text', D ) } value={ field.button_label } onChange={ ( v ) => onChange( { button_label: v } ) } placeholder={ __( 'Add another', D ) } />
				</FormGrid>
			) }
		</Stack>
	);
}

function TranslationsEditor( { field, onChange } ) {
	const { boot } = useBuilder();
	const locales = ( boot.locales || [] ).filter( ( l ) => l !== boot.locale );
	const [ extra, setExtra ] = useState( '' );
	const all = [ ...new Set( [ ...locales, ...Object.keys( field.i18n || {} ) ] ) ];
	const setT = ( loc, patch ) => onChange( { i18n: { ...( field.i18n || {} ), [ loc ]: { ...( ( field.i18n || {} )[ loc ] || {} ), ...patch } } } );
	return (
		<Stack gap={ 12 }>
			<p className="dev3po-b-muted">{ __( 'Shoppers see these texts when the site or their language (WPML, Polylang) matches. Use "Translate with DEV3 AI" on the set to fill them in bulk.', D ) }</p>
			{ all.map( ( loc ) => {
				const t = ( field.i18n || {} )[ loc ] || {};
				return (
					<fieldset key={ loc } className="dev3po-b-locale">
						<legend>
							<Badge tone="info">{ loc }</Badge>
						</legend>
						<FormGrid>
							<TextField label={ __( 'Label', D ) } value={ t.label || '' } onChange={ ( v ) => setT( loc, { label: v } ) } />
							<TextField label={ __( 'Help text', D ) } value={ t.help || '' } onChange={ ( v ) => setT( loc, { help: v } ) } />
						</FormGrid>
						{ ( field.options || [] ).length > 0 && (
							<FormGrid>
								{ field.options.map( ( o ) => (
									<TextField key={ o.id } label={ o.label } value={ ( t.options || {} )[ o.id ] || '' } onChange={ ( v ) => setT( loc, { options: { ...( t.options || {} ), [ o.id ]: v } } ) } />
								) ) }
							</FormGrid>
						) }
					</fieldset>
				);
			} ) }
			<Inline gap={ 8 } align="start" className="dev3po-b-addlocale">
				<TextField label={ __( 'Add language (locale)', D ) } value={ extra } onChange={ setExtra } placeholder="de_DE" />
				<Button
					onClick={ () => {
						const loc = extra.replace( /[^A-Za-z_]/g, '' );
						if ( loc ) {
							setT( loc, {} );
							setExtra( '' );
						}
					} }
					disabled={ ! extra.trim() }
				>
					{ __( 'Add', D ) }
				</Button>
			</Inline>
		</Stack>
	);
}

/**
 * The drawer.
 *
 * @param {Object}   props          Props.
 * @param {Object}   props.field    Field.
 * @param {Array}    props.fields   All fields at this level (for conditions/keys).
 * @param {Function} props.onChange ( patch ) => void.
 * @param {Function} props.onClose  Close.
 * @param {Function} props.renderSubfields ( field ) => element, for repeaters.
 * @return {Element} Drawer.
 */
export default function FieldEditor( { field, fields, onChange, onClose, renderSubfields } ) {
	const [ tab, setTab ] = useState( 'general' );
	if ( ! field ) {
		return null;
	}
	const meta = typeMeta( field.type );
	const input = isInput( field.type );
	const tabs = [
		{ id: 'general', label: __( 'General', D ) },
		hasOptions( field.type ) && { id: 'choices', label: __( 'Choices', D ), badge: ( field.options || [] ).length },
		field.type === 'repeater' && { id: 'subfields', label: __( 'Sub-fields', D ), badge: ( field.subfields || [] ).length },
		input && { id: 'pricing', label: __( 'Pricing', D ) },
		{ id: 'logic', label: __( 'Logic', D ), badge: field.conditions && field.conditions.enabled ? field.conditions.rules.length : undefined },
		input && field.type !== 'hidden' && { id: 'translations', label: __( 'Translations', D ) },
	].filter( Boolean );
	const current = tabs.find( ( t ) => t.id === tab ) ? tab : 'general';
	return (
		<Drawer
			open
			onClose={ onClose }
			size="lg"
			title={ field.label || meta.label }
			description={ `${ meta.label }${ input ? ` · {${ field.key }}` : '' }` }
			footer={
				<Button variant="primary" onClick={ onClose } data-autofocus>
					{ __( 'Done', D ) }
				</Button>
			}
		>
			<Tabs tabs={ tabs } value={ current } onChange={ setTab } label={ __( 'Field settings', D ) } />
			<div className="dev3po-b-drawer-body">
				{ current === 'general' && <GeneralEditor field={ field } fields={ fields } onChange={ onChange } /> }
				{ current === 'choices' && <OptionsEditor field={ field } onChange={ onChange } /> }
				{ current === 'subfields' && renderSubfields && renderSubfields( field ) }
				{ current === 'pricing' && <PricingEditor field={ field } fields={ fields } onChange={ onChange } /> }
				{ current === 'logic' && <ConditionsEditor field={ field } fields={ fields } onChange={ onChange } /> }
				{ current === 'translations' && <TranslationsEditor field={ field } onChange={ onChange } /> }
			</div>
		</Drawer>
	);
}
