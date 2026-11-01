import { useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { request } from '../core3/api';
import { Badge, Button, Card, ErrorNotice, Inline, Modal, NumberField, PageHeader, Select, SkeletonCard, Stack, TextField, useToast } from '../core3/ui';
import Builder from '../builder/Builder';
import { GeneratePanel, IssuesPanel, LabelsPanel, Preview, useChecker } from '../builder/panels';
import { download } from './SetsList';

const D = 'dev3-ai-product-options-for-woocommerce';

function TermPicker( { label, taxonomy, ids, labels, onChange, help } ) {
	const [ q, setQ ] = useState( '' );
	const [ results, setResults ] = useState( [] );
	const [ names, setNames ] = useState( labels || {} );
	const prefix = taxonomy === 'product' ? 'p' : 't';
	useEffect( () => {
		const t = setTimeout( () => {
			request( `terms?taxonomy=${ taxonomy }&search=${ encodeURIComponent( q ) }` )
				.then( setResults )
				.catch( () => setResults( [] ) );
		}, 250 );
		return () => clearTimeout( t );
	}, [ q, taxonomy ] );
	const add = ( item ) => {
		setNames( { ...names, [ prefix + item.id ]: item.label } );
		onChange( [ ...ids, item.id ] );
		setQ( '' );
	};
	const options = results.filter( ( r ) => ! ids.includes( r.id ) ).slice( 0, 8 );
	return (
		<div className="dev3po-b-picker">
			<p className="dev3po-b-picker__label">{ label }</p>
			{ ids.length > 0 && (
				<ul className="dev3po-b-chips">
					{ ids.map( ( id ) => (
						<li key={ id }>
							<span>{ names[ prefix + id ] || `#${ id }` }</span>
							<button type="button" aria-label={ sprintf( /* translators: %s: name */ __( 'Remove %s', D ), names[ prefix + id ] || id ) } onClick={ () => onChange( ids.filter( ( x ) => x !== id ) ) }>
								×
							</button>
						</li>
					) ) }
				</ul>
			) }
			<TextField label={ sprintf( /* translators: %s: what */ __( 'Search %s', D ), label.toLowerCase() ) } hideLabel value={ q } onChange={ setQ } placeholder={ __( 'Type to search…', D ) } help={ help } />
			{ q.trim() !== '' && options.length > 0 && (
				<ul className="dev3po-b-results" aria-label={ __( 'Matches', D ) }>
					{ options.map( ( r ) => (
						<li key={ r.id }>
							<button type="button" onClick={ () => add( r ) }>
								<span>{ r.label }</span>
								{ r.count !== undefined && <small>{ sprintf( /* translators: %d: count */ __( '%d products', D ), r.count ) }</small> }
								<span aria-hidden="true">+</span>
							</button>
						</li>
					) ) }
				</ul>
			) }
		</div>
	);
}

function Assignment( { rules, labels, onChange } ) {
	const set = ( patch ) => onChange( { ...rules, ...patch } );
	return (
		<Card title={ __( 'Assignment', D ) } description={ __( 'Which products show this set. Products can still exclude it or override prices.', D ) }>
			<Stack gap={ 14 }>
				<Select
					label={ __( 'Show on', D ) }
					value={ rules.scope }
					onChange={ ( scope ) => set( { scope } ) }
					options={ [
						{ value: 'rules', label: __( 'Selected categories, tags or products', D ) },
						{ value: 'all', label: __( 'All products', D ) },
					] }
				/>
				{ rules.scope === 'rules' && (
					<>
						<TermPicker label={ __( 'Categories', D ) } taxonomy="product_cat" ids={ rules.categories } labels={ labels } onChange={ ( categories ) => set( { categories } ) } help={ __( 'Sub-categories are included.', D ) } />
						<TermPicker label={ __( 'Tags', D ) } taxonomy="product_tag" ids={ rules.tags } labels={ labels } onChange={ ( tags ) => set( { tags } ) } />
						<TermPicker label={ __( 'Products', D ) } taxonomy="product" ids={ rules.products } labels={ labels } onChange={ ( products ) => set( { products } ) } />
					</>
				) }
				<TermPicker label={ __( 'Exclude products', D ) } taxonomy="product" ids={ rules.exclude_products } labels={ labels } onChange={ ( exclude_products ) => set( { exclude_products } ) } />
			</Stack>
		</Card>
	);
}

const EMPTY = { title: '', status: 'draft', priority: 10, fields: [], rules: { scope: 'rules', products: [], categories: [], tags: [], exclude_products: [] } };

export default function SetEditor( { id, navigate } ) {
	const toast = useToast();
	const isNew = id === 'new';
	const [ set, setSet ] = useState( isNew ? EMPTY : null );
	const [ saved, setSaved ] = useState( isNew ? JSON.stringify( EMPTY ) : '' );
	const [ error, setError ] = useState( null );
	const [ busy, setBusy ] = useState( '' );
	const [ blocked, setBlocked ] = useState( null );
	const [ editing, setEditing ] = useState( null );
	const [ aiIssues, setAiIssues ] = useState( [] );
	const [ description, setDescription ] = useState( '' );
	const { issues, loading } = useChecker( set ? set.fields : [] );

	useEffect( () => {
		if ( isNew ) {
			return;
		}
		request( `sets/${ id }` )
			.then( ( s ) => {
				setSet( s );
				setSaved( JSON.stringify( { title: s.title, status: s.status, priority: s.priority, fields: s.fields, rules: s.rules } ) );
			} )
			.catch( setError );
	}, [ id ] ); // eslint-disable-line react-hooks/exhaustive-deps

	if ( error ) {
		return <ErrorNotice error={ error } />;
	}
	if ( ! set ) {
		return <SkeletonCard lines={ 6 } />;
	}
	const patch = ( p ) => setSet( { ...set, ...p } );
	const body = { title: set.title, status: set.status, priority: set.priority, fields: set.fields, rules: set.rules };
	const dirty = JSON.stringify( body ) !== saved;

	const persist = ( status, force = false ) => {
		setBusy( status );
		const data = { ...body, status, force };
		const req = isNew || ! set.id ? request( 'sets', { method: 'POST', data } ) : request( `sets/${ set.id }`, { method: 'PUT', data } );
		return req
			.then( ( s ) => {
				setSet( { ...s, rule_labels: set.rule_labels } );
				setSaved( JSON.stringify( { title: s.title, status: s.status, priority: s.priority, fields: s.fields, rules: s.rules } ) );
				setBlocked( null );
				toast.success( status === 'publish' ? __( 'Option set published.', D ) : __( 'Draft saved.', D ) );
				if ( isNew ) {
					navigate( `sets/${ s.id }` );
				}
			} )
			.catch( ( e ) => {
				if ( e.code === 'dev3po_logic_errors' ) {
					setBlocked( true );
				} else {
					toast.error( e );
				}
			} )
			.finally( () => setBusy( '' ) );
	};
	const allIssues = [ ...issues, ...aiIssues ];
	const errors = issues.filter( ( i ) => i.severity === 'error' ).length;

	return (
		<>
			<PageHeader
				back={ { href: '#/sets', label: __( 'Option sets', D ) } }
				title={ isNew ? __( 'New option set', D ) : set.title || __( 'Untitled option set', D ) }
				description={
					<>
						<Badge tone={ set.status === 'publish' ? 'success' : 'neutral' } dot>
							{ set.status === 'publish' ? __( 'Live', D ) : __( 'Draft', D ) }
						</Badge>
						{ dirty && <> <Badge tone="warning">{ __( 'Unsaved changes', D ) }</Badge></> }
					</>
				}
				actions={
					<Inline gap={ 8 }>
						{ ! isNew && (
							<Button variant="ghost" icon="external" onClick={ () => download( `option-set-${ set.id }.json`, { title: set.title, priority: set.priority, fields: set.fields } ) }>
								{ __( 'Export', D ) }
							</Button>
						) }
						<Button onClick={ () => persist( 'draft' ) } loading={ busy === 'draft' } disabled={ !! busy }>
							{ set.status === 'publish' ? __( 'Unpublish', D ) : __( 'Save draft', D ) }
						</Button>
						<Button variant="primary" icon="check" onClick={ () => persist( 'publish' ) } loading={ busy === 'publish' } disabled={ !! busy }>
							{ set.status === 'publish' ? __( 'Update', D ) : __( 'Publish', D ) }
						</Button>
					</Inline>
				}
			/>
			<div className="dev3po-b-layout">
				<Stack gap={ 16 }>
					<Card title={ __( 'Details', D ) }>
						<div className="dev3po-b-details">
							<TextField label={ __( 'Name', D ) } value={ set.title } onChange={ ( title ) => patch( { title } ) } placeholder={ __( 'e.g. Engraving & gift options', D ) } maxLength={ 120 } required />
							<NumberField label={ __( 'Priority', D ) } value={ set.priority } onChange={ ( v ) => patch( { priority: v === '' ? 10 : v } ) } help={ __( 'Lower numbers show first.', D ) } />
						</div>
					</Card>
					<Card title={ __( 'Fields', D ) } actions={ <Badge>{ set.fields.length }</Badge> }>
						<Builder
							fields={ set.fields }
							onChange={ ( fields ) => patch( { fields } ) }
							issues={ allIssues }
							editing={ editing }
							setEditing={ setEditing }
						/>
					</Card>
					<Preview fields={ set.fields } />
				</Stack>
				<Stack gap={ 16 }>
					<Assignment rules={ set.rules } labels={ set.rule_labels || {} } onChange={ ( rules ) => patch( { rules } ) } />
					<IssuesPanel fields={ set.fields } issues={ issues } loading={ loading } onEdit={ setEditing } product={ set.title } aiIssues={ aiIssues } onAiIssues={ setAiIssues } />
					<GeneratePanel
						context={ { title: set.title, description } }
						fields={ set.fields }
						onApply={ ( list, mode ) => {
							patch( { fields: mode === 'replace' ? list : [ ...set.fields, ...list ] } );
							setDescription( '' );
						} }
					/>
					{ set.fields.length > 0 && <LabelsPanel fields={ set.fields } product={ set.title } onApply={ ( fields ) => patch( { fields } ) } /> }
				</Stack>
			</div>
			<Modal
				open={ !! blocked }
				onClose={ () => setBlocked( null ) }
				title={ __( 'This set has logic errors', D ) }
				description={ sprintf( /* translators: %d: count */ __( 'The checker found %d errors. Shoppers may see fields that never show, or wrong prices.', D ), errors ) }
				footer={
					<>
						<Button variant="ghost" onClick={ () => setBlocked( null ) } data-autofocus>
							{ __( 'Fix them first', D ) }
						</Button>
						<Button variant="danger" onClick={ () => persist( 'publish', true ) } loading={ busy === 'publish' }>
							{ __( 'Publish anyway', D ) }
						</Button>
					</>
				}
			>
				<ul className="dev3po-b-issues">
					{ issues
						.filter( ( i ) => i.severity === 'error' )
						.map( ( i, n ) => (
							<li key={ n } className="dev3po-b-issue is-error">
								<Badge tone="danger">{ __( 'Error', D ) }</Badge>
								<div className="dev3po-b-issue__text">
									<p>{ i.message }</p>
								</div>
							</li>
						) ) }
				</ul>
			</Modal>
		</>
	);
}
