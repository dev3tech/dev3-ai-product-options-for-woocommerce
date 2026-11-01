/**
 * Builder side panels: logic checker (+ DEV3 AI review), generate options, labels/translations, live preview.
 */
import { useEffect, useMemo, useRef, useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import { request } from '../core3/api';
import { AiButton, AiDraftReview, Badge, Button, Card, EmptyState, ErrorNotice, Inline, Notice, NumberField, Select, Skeleton, Stack, Tabs, Textarea, TextField } from '../core3/ui';
import { init as initStorefront } from '../storefront';
import { useBuilder } from './context';
import { TypeGlyph } from './Builder';
import { priceSummary, typeMeta } from './model';

const D = 'dev3-ai-product-options-for-woocommerce';

/**
 * Debounced server checker.
 *
 * @param {Array} fields Fields.
 * @return {Object} { issues, loading }
 */
export function useChecker( fields ) {
	const [ issues, setIssues ] = useState( [] );
	const [ loading, setLoading ] = useState( false );
	const json = JSON.stringify( fields );
	useEffect( () => {
		setLoading( true );
		const t = setTimeout( () => {
			request( 'check', { method: 'POST', data: { fields } } )
				.then( ( r ) => setIssues( r.issues || [] ) )
				.catch( () => {} )
				.finally( () => setLoading( false ) );
		}, 450 );
		return () => clearTimeout( t );
	}, [ json ] ); // eslint-disable-line react-hooks/exhaustive-deps
	return { issues, loading, setIssues };
}

const TONE = { error: 'danger', warning: 'warning', info: 'info' };

export function IssuesPanel( { fields, issues, loading, onEdit, product, onAiIssues, aiIssues = [] } ) {
	const { boot } = useBuilder();
	const [ busy, setBusy ] = useState( false );
	const [ error, setError ] = useState( null );
	const [ summary, setSummary ] = useState( '' );
	const all = [ ...issues, ...aiIssues ];
	const errors = all.filter( ( i ) => i.severity === 'error' ).length;
	const warnings = all.filter( ( i ) => i.severity === 'warning' ).length;
	const label = ( id ) => {
		const f = fields.find( ( x ) => x.id === id );
		return f ? f.label || f.key : '';
	};
	const review = () => {
		setBusy( true );
		setError( null );
		request( 'ai/review', { method: 'POST', data: { fields, product } } )
			.then( ( r ) => {
				setSummary( r.summary || '' );
				onAiIssues( ( r.issues || [] ).filter( ( i ) => i.source === 'ai' ) );
			} )
			.catch( setError )
			.finally( () => setBusy( false ) );
	};
	return (
		<Card
			title={ __( 'Logic checker', D ) }
			description={ __( 'Finds impossible conditions, loops and price conflicts before you publish.', D ) }
			actions={ loading ? <Skeleton width={ 60 } /> : <Badge tone={ errors ? 'danger' : warnings ? 'warning' : 'success' }>{ errors ? sprintf( /* translators: %d: count */ _n( '%d error', '%d errors', errors, D ), errors ) : warnings ? sprintf( /* translators: %d: count */ _n( '%d warning', '%d warnings', warnings, D ), warnings ) : __( 'All good', D ) }</Badge> }
		>
			{ all.length === 0 && ! loading && <EmptyState compact icon="success" title={ __( 'No problems found', D ) } description={ __( 'Conditions, loops and prices look consistent.', D ) } /> }
			{ all.length > 0 && (
				<ul className="dev3po-b-issues">
					{ all.map( ( i, n ) => (
						<li key={ n } className={ `dev3po-b-issue is-${ i.severity }` }>
							<Badge tone={ TONE[ i.severity ] || 'neutral' }>{ i.severity === 'error' ? __( 'Error', D ) : i.severity === 'warning' ? __( 'Warning', D ) : __( 'Note', D ) }</Badge>
							<div className="dev3po-b-issue__text">
								<p>
									{ i.source === 'ai' && <span className="dev3po-b-ai-tag">{ __( 'DEV3 AI', D ) }</span> }
									{ i.message }
								</p>
								{ i.suggestion && <p className="dev3po-b-muted">{ i.suggestion }</p> }
							</div>
							{ i.field && label( i.field ) && (
								<Button size="sm" variant="ghost" onClick={ () => onEdit( i.field ) }>
									{ __( 'Fix', D ) }
								</Button>
							) }
						</li>
					) ) }
				</ul>
			) }
			{ boot.features.ai_logic !== false && (
				<Stack gap={ 8 }>
					<div>
						<AiButton onClick={ review } loading={ busy } disabled={ busy || ! fields.length } loadingText={ __( 'Reviewing…', D ) }>
							{ __( 'Review with DEV3 AI', D ) }
						</AiButton>
					</div>
					{ summary && <Notice tone="ai">{ summary }</Notice> }
					<ErrorNotice error={ error } onRetry={ review } onDismiss={ () => setError( null ) } />
				</Stack>
			) }
		</Card>
	);
}

/**
 * Generate options from the product.
 *
 * @param {Object}   props          Props.
 * @param {Object}   props.context  { product_id, title, description, price }.
 * @param {Array}    props.fields   Current fields.
 * @param {Function} props.onApply  ( fields, mode ) => void.
 * @return {Element} Card.
 */
export function GeneratePanel( { context = {}, fields, onApply } ) {
	const { boot, money } = useBuilder();
	const [ title, setTitle ] = useState( context.title || '' );
	const [ description, setDescription ] = useState( context.description || '' );
	const [ instructions, setInstructions ] = useState( '' );
	const [ busy, setBusy ] = useState( false );
	const [ error, setError ] = useState( null );
	const [ draft, setDraft ] = useState( null );
	const [ mode, setMode ] = useState( fields.length ? 'append' : 'replace' );
	useEffect( () => {
		setTitle( context.title || '' );
		setDescription( context.description || '' );
	}, [ context.title, context.description ] );
	if ( boot.features.ai_generate === false ) {
		return null;
	}
	const run = () => {
		setBusy( true );
		setError( null );
		request( 'ai/generate', { method: 'POST', data: { product_id: context.product_id || 0, title, description, price: context.price, instructions } } )
			.then( setDraft )
			.catch( setError )
			.finally( () => setBusy( false ) );
	};
	return (
		<Card tone="ai" title={ __( 'Generate options with DEV3 AI', D ) } description={ __( 'DEV3 AI reads the product and drafts the full option set: fields, choices, prices and conditions. You review it before anything is saved.', D ) }>
			<Stack gap={ 12 }>
				<TextField label={ __( 'Product title', D ) } value={ title } onChange={ setTitle } />
				<Textarea label={ __( 'Product description', D ) } value={ description } onChange={ setDescription } rows={ 3 } maxLength={ 2500 } />
				<Textarea label={ __( 'Instructions (optional)', D ) } value={ instructions } onChange={ setInstructions } rows={ 2 } maxLength={ 600 } placeholder={ __( 'e.g. Engraving up to 20 letters, gift box, express production', D ) } />
				<div>
					<AiButton onClick={ run } loading={ busy && ! draft } disabled={ busy || ( ! title.trim() && ! description.trim() ) }>
						{ __( 'Generate options with DEV3 AI', D ) }
					</AiButton>
				</div>
				<ErrorNotice error={ error } onRetry={ run } onDismiss={ () => setError( null ) } />
				{ draft && (
					<Stack gap={ 12 }>
						<div className="dev3po-b-gen">
							<p className="dev3po-b-gen__head">
								<strong>{ sprintf( /* translators: %d: count */ __( '%d fields drafted', D ), draft.fields.length ) }</strong>
								<span>
									{ draft.schema_errors && draft.schema_errors.length === 0 ? <Badge tone="success">{ __( 'Schema valid', D ) }</Badge> : <Badge tone="warning">{ sprintf( /* translators: %d: count */ __( '%d schema fixes applied', D ), draft.schema_errors.length ) }</Badge> }{ ' ' }
									{ draft.issues.filter( ( i ) => i.severity === 'error' ).length > 0 ? <Badge tone="danger">{ __( 'Logic errors', D ) }</Badge> : <Badge tone="success">{ __( 'Logic OK', D ) }</Badge> }
								</span>
							</p>
							<ol className="dev3po-b-gen__list">
								{ draft.fields.map( ( f ) => (
									<li key={ f.id }>
										<TypeGlyph type={ f.type } />
										<span className="dev3po-b-gen__label">
											{ f.label }
											<small>
												{ typeMeta( f.type ).label }
												{ f.options ? ` · ${ f.options.map( ( o ) => o.label ).join( ', ' ) }` : '' }
											</small>
										</span>
										{ priceSummary( f, money ) && <Badge tone="brand">{ priceSummary( f, money ) }</Badge> }
										{ f.conditions && f.conditions.enabled && <Badge tone="info">{ __( 'Conditional', D ) }</Badge> }
									</li>
								) ) }
							</ol>
							{ draft.notes && <p className="dev3po-b-muted">{ draft.notes }</p> }
						</div>
						{ fields.length > 0 && (
							<Select
								label={ __( 'When accepted', D ) }
								value={ mode }
								onChange={ setMode }
								options={ [
									{ value: 'append', label: __( 'Add to the existing fields', D ) },
									{ value: 'replace', label: __( 'Replace all existing fields', D ) },
								] }
							/>
						) }
						<AiDraftReview
							title={ __( 'DEV3 AI option set (JSON)', D ) }
							format="json"
							draft={ draft.fields }
							meta={ { model: draft.model, tokens: draft.tokens, cached: draft.cached } }
							acceptLabel={ mode === 'replace' ? __( 'Use these fields', D ) : __( 'Add these fields', D ) }
							onAccept={ ( value ) => {
								onApply( Array.isArray( value ) ? value : draft.fields, mode );
								setDraft( null );
							} }
							onReject={ () => setDraft( null ) }
							onRegenerate={ run }
							regenerating={ busy }
						/>
					</Stack>
				) }
			</Stack>
		</Card>
	);
}

const flatten = ( list ) => {
	const out = {};
	list.forEach( ( e ) => {
		[ 'label', 'help', 'tooltip', 'placeholder' ].forEach( ( k ) => {
			if ( e[ k ] !== undefined ) {
				out[ `${ e.id }.${ k }` ] = e[ k ];
			}
		} );
		Object.entries( e.options || {} ).forEach( ( [ oid, label ] ) => {
			out[ `${ e.id }.o.${ oid }` ] = label;
		} );
	} );
	return out;
};

/**
 * Labels, help text and tooltips (improve) or translations.
 *
 * @param {Object}   props          Props.
 * @param {Array}    props.fields   Fields.
 * @param {string}   props.product  Product/set name.
 * @param {Function} props.onApply  ( fields ) => void.
 * @return {Element} Card.
 */
export function LabelsPanel( { fields, product, onApply } ) {
	const { boot } = useBuilder();
	const [ mode, setMode ] = useState( 'improve' );
	const [ tone, setTone ] = useState( 'friendly' );
	const [ locale, setLocale ] = useState( ( boot.locales || [] ).find( ( l ) => l !== boot.locale ) || 'de_DE' );
	const [ busy, setBusy ] = useState( false );
	const [ error, setError ] = useState( null );
	const [ draft, setDraft ] = useState( null );
	if ( boot.features.ai_labels === false ) {
		return null;
	}
	const run = () => {
		setBusy( true );
		setError( null );
		request( 'ai/labels', { method: 'POST', data: { fields, mode, locale, tone, product } } )
			.then( setDraft )
			.catch( setError )
			.finally( () => setBusy( false ) );
	};
	const original = {};
	if ( draft ) {
		fields.forEach( ( f ) => {
			const t = draft.mode === 'translate' ? ( f.i18n || {} )[ draft.locale ] || {} : f;
			[ 'label', 'help', 'tooltip', 'placeholder' ].forEach( ( k ) => ( original[ `${ f.id }.${ k }` ] = t[ k ] || '' ) );
			( f.options || [] ).forEach( ( o ) => ( original[ `${ f.id }.o.${ o.id }` ] = draft.mode === 'translate' ? ( t.options || {} )[ o.id ] || '' : o.label ) );
		} );
	}
	const draftFlat = draft ? flatten( draft.draft ) : {};
	const rows = Object.keys( draftFlat )
		.filter( ( k ) => draftFlat[ k ] !== '' || original[ k ] )
		.map( ( key ) => {
			const [ fid, part, oid ] = key.split( '.' );
			const f = fields.find( ( x ) => x.id === fid );
			const name = f ? f.label || f.key : fid;
			const what = part === 'o' ? ( ( f && ( f.options || [] ).find( ( o ) => o.id === oid ) ) || {} ).label || oid : { label: __( 'label', D ), help: __( 'help', D ), tooltip: __( 'tooltip', D ), placeholder: __( 'placeholder', D ) }[ part ];
			return { key, label: `${ name } · ${ what }`, multiline: part === 'help' || part === 'tooltip', maxLength: part === 'help' || part === 'tooltip' ? 300 : 120 };
		} );
	const accept = ( values ) => {
		const next = fields.map( ( f ) => {
			const copy = { ...f, options: f.options ? f.options.map( ( o ) => ( { ...o } ) ) : f.options, i18n: { ...( f.i18n || {} ) } };
			const target = draft.mode === 'translate' ? { ...( copy.i18n[ draft.locale ] || {} ) } : copy;
			[ 'label', 'help', 'tooltip', 'placeholder' ].forEach( ( k ) => {
				const v = values[ `${ f.id }.${ k }` ];
				if ( v !== undefined && ( v !== '' || draft.mode === 'improve' ) && ! ( k === 'label' && v === '' ) ) {
					target[ k ] = v;
				}
			} );
			( f.options || [] ).forEach( ( o, idx ) => {
				const v = values[ `${ f.id }.o.${ o.id }` ];
				if ( v ) {
					if ( draft.mode === 'translate' ) {
						target.options = { ...( target.options || {} ), [ o.id ]: v };
					} else {
						copy.options[ idx ].label = v;
					}
				}
			} );
			if ( draft.mode === 'translate' ) {
				copy.i18n[ draft.locale ] = target;
			}
			return copy;
		} );
		onApply( next );
		setDraft( null );
	};
	return (
		<Card tone="ai" title={ __( 'Labels & translations · DEV3 AI', D ) } description={ __( 'Clear labels, help texts and tooltips, or translations into other languages.', D ) }>
			<Stack gap={ 12 }>
				<Tabs
					variant="pills"
					tabs={ [
						{ id: 'improve', label: __( 'Improve texts', D ) },
						{ id: 'translate', label: __( 'Translate', D ) },
					] }
					value={ mode }
					onChange={ setMode }
					label={ __( 'Mode', D ) }
				/>
				<Inline gap={ 12 } className="dev3po-b-inline-fields">
					{ mode === 'improve' ? (
						<Select
							label={ __( 'Tone', D ) }
							value={ tone }
							onChange={ setTone }
							options={ [ 'friendly', 'professional', 'playful', 'luxury', 'concise' ].map( ( t ) => ( { value: t, label: { friendly: __( 'Friendly', D ), professional: __( 'Professional', D ), playful: __( 'Playful', D ), luxury: __( 'Luxury', D ), concise: __( 'Concise', D ) }[ t ] } ) ) }
						/>
					) : (
						<TextField label={ __( 'Language (locale)', D ) } value={ locale } onChange={ setLocale } list="dev3po-locales" help={ __( 'e.g. de_DE, fr_FR, es_ES', D ) } />
					) }
				</Inline>
				<datalist id="dev3po-locales">
					{ [ ...new Set( [ ...( boot.locales || [] ), 'de_DE', 'fr_FR', 'es_ES', 'it_IT', 'nl_NL', 'pt_BR', 'ja', 'ar' ] ) ].map( ( l ) => (
						<option key={ l } value={ l } />
					) ) }
				</datalist>
				<div>
					<AiButton onClick={ run } loading={ busy && ! draft } disabled={ busy || ! fields.length || ( mode === 'translate' && ! locale.trim() ) }>
						{ mode === 'translate' ? __( 'Translate with DEV3 AI', D ) : __( 'Write texts with DEV3 AI', D ) }
					</AiButton>
				</div>
				<ErrorNotice error={ error } onRetry={ run } onDismiss={ () => setError( null ) } />
				{ draft && rows.length > 0 && (
					<AiDraftReview
						title={ draft.mode === 'translate' ? sprintf( /* translators: %s: locale */ __( 'DEV3 AI translation · %s', D ), draft.locale ) : __( 'DEV3 AI texts', D ) }
						fields={ rows }
						draft={ draftFlat }
						original={ original }
						meta={ { model: draft.model, tokens: draft.tokens, cached: draft.cached } }
						onAccept={ accept }
						onReject={ () => setDraft( null ) }
						onRegenerate={ run }
						regenerating={ busy }
						acceptLabel={ __( 'Apply texts', D ) }
					/>
				) }
			</Stack>
		</Card>
	);
}

/**
 * Live storefront preview (server-rendered markup + the real storefront script).
 *
 * @param {Object} props        Props.
 * @param {Array}  props.fields Fields.
 * @param {number} props.price  Default product price.
 * @return {Element} Card.
 */
export function Preview( { fields, price = 20 } ) {
	const { boot } = useBuilder();
	const [ base, setBase ] = useState( price || 20 );
	const [ locale, setLocale ] = useState( '' );
	const [ html, setHtml ] = useState( null );
	const [ error, setError ] = useState( null );
	const ref = useRef();
	const json = useMemo( () => JSON.stringify( fields ), [ fields ] );
	useEffect( () => {
		const t = setTimeout( () => {
			request( 'preview', { method: 'POST', data: { fields, price: base, locale } } )
				.then( ( r ) => {
					setHtml( r.html || '' );
					setError( null );
				} )
				.catch( setError );
		}, 400 );
		return () => clearTimeout( t );
	}, [ json, base, locale ] ); // eslint-disable-line react-hooks/exhaustive-deps
	useEffect( () => {
		if ( ref.current && html ) {
			initStorefront( ref.current );
		}
	}, [ html ] );
	return (
		<Card title={ __( 'Live preview', D ) } description={ __( 'Exactly what shoppers see, with live prices and conditions. Uploads are disabled here.', D ) }>
			<Inline gap={ 12 } className="dev3po-b-inline-fields">
				<NumberField label={ __( 'Product price', D ) } value={ base } min={ 0 } step={ 0.01 } onChange={ ( v ) => setBase( v === '' ? 0 : v ) } />
				{ ( boot.locales || [] ).length > 1 && (
					<Select label={ __( 'Language', D ) } value={ locale } onChange={ setLocale } options={ [ { value: '', label: __( 'Site language', D ) }, ...boot.locales.map( ( l ) => ( { value: l, label: l } ) ) ] } />
				) }
			</Inline>
			<ErrorNotice error={ error } />
			{ html === null && <Skeleton lines={ 5 } /> }
			{ html === '' && <EmptyState compact icon="list" title={ __( 'Nothing to preview yet', D ) } /> }
			{ html && <div className="dev3po-b-preview" ref={ ref } key={ html } dangerouslySetInnerHTML={ { __html: html } } /> }
		</Card>
	);
}

