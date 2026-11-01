/**
 * Product-level options: mode, global sets (exclude + price overrides), own fields, AI tools, preview.
 * Used by the admin app (#/products/<id>) and the "DEV3 AI Options" tab in the product editor.
 */
import { useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { Badge, Card, Notice, NumberField, Select, Stack, Toggle } from '../core3/ui';
import Builder from './Builder';
import { useBuilder } from './context';
import { GeneratePanel, IssuesPanel, LabelsPanel, Preview, useChecker } from './panels';

const D = 'dev3-ai-product-options-for-woocommerce';

function SetPrices( { set, overrides, onChange } ) {
	const { money } = useBuilder();
	const rows = [];
	( set.fields || [] ).forEach( ( f ) => {
		if ( [ 'flat', 'per_char', 'per_unit' ].includes( f.price_type ) ) {
			rows.push( { ref: f.id, label: f.label || f.key, price: f.price } );
		}
		( f.options || [] ).forEach( ( o ) => {
			if ( o.price_type === 'flat' ) {
				rows.push( { ref: o.id, label: `${ f.label || f.key } › ${ o.label }`, price: o.price } );
			}
		} );
	} );
	if ( ! rows.length ) {
		return <p className="dev3po-b-muted">{ __( 'This set has no fixed prices to override.', D ) }</p>;
	}
	return (
		<div className="dev3po-b-overrides">
			{ rows.map( ( r ) => (
				<NumberField
					key={ r.ref }
					label={ r.label }
					value={ overrides[ r.ref ] ?? '' }
					placeholder={ String( r.price ) }
					step={ 0.01 }
					help={ sprintf( /* translators: %s: price */ __( 'Set price: %s', D ), money( r.price ) ) }
					onChange={ ( v ) => {
						const next = { ...overrides };
						if ( v === '' ) {
							delete next[ r.ref ];
						} else {
							next[ r.ref ] = v;
						}
						onChange( next );
					} }
				/>
			) ) }
		</div>
	);
}

/**
 * @param {Object}   props            Props.
 * @param {Object}   props.product    Product payload (GET products/<id>).
 * @param {Object}   props.config     { fields, mode, exclude_sets, price_overrides }.
 * @param {Function} props.onChange   ( config ) => void.
 * @param {Function} props.getContext Optional live title/description reader (product editor).
 * @param {boolean}  props.compact    Single column (product editor tab).
 * @return {Element} UI.
 */
export default function ProductOptions( { product, config, onChange, getContext, compact = false } ) {
	const [ editing, setEditing ] = useState( null );
	const [ openSet, setOpenSet ] = useState( null );
	const [ aiIssues, setAiIssues ] = useState( [] );
	const { issues, loading } = useChecker( config.fields );
	const set = ( patch ) => onChange( { ...config, ...patch } );
	const sets = product.sets || [];
	const included = sets.filter( ( s ) => ! config.exclude_sets.includes( s.id ) );
	const previewFields = [ ...( config.mode === 'none' ? [] : config.fields ), ...( config.mode === 'inherit' ? included.flatMap( ( s ) => s.fields ) : [] ) ];
	const context = getContext ? { ...getContext(), product_id: product.id, price: product.price } : { product_id: product.id, title: product.title, description: product.description, price: product.price };

	const main = (
		<Stack gap={ 16 }>
			<Card title={ __( 'Options on this product', D ) }>
				<Stack gap={ 12 }>
					<Select
						label={ __( 'Which options apply', D ) }
						value={ config.mode }
						onChange={ ( mode ) => set( { mode } ) }
						options={ [
							{ value: 'inherit', label: __( 'Product fields + matching global option sets', D ) },
							{ value: 'override', label: __( 'Only the fields below (ignore global sets)', D ) },
							{ value: 'none', label: __( 'No options on this product', D ) },
						] }
					/>
					{ config.mode === 'inherit' && sets.length > 0 && (
						<ul className="dev3po-b-sets">
							{ sets.map( ( s ) => {
								const on = ! config.exclude_sets.includes( s.id );
								return (
									<li key={ s.id } className="dev3po-b-set">
										<div className="dev3po-b-set__head">
											<Toggle
												label={ s.title }
												checked={ on }
												onChange={ ( v ) => set( { exclude_sets: v ? config.exclude_sets.filter( ( x ) => x !== s.id ) : [ ...config.exclude_sets, s.id ] } ) }
												help={ sprintf( /* translators: %d: count */ __( 'Global set · %d fields', D ), s.fields.length ) }
											/>
											{ on && (
												<button type="button" className="dev3po-b-link" aria-expanded={ openSet === s.id } onClick={ () => setOpenSet( openSet === s.id ? null : s.id ) }>
													{ __( 'Prices for this product', D ) }
												</button>
											) }
										</div>
										{ on && openSet === s.id && <SetPrices set={ s } overrides={ config.price_overrides || {} } onChange={ ( price_overrides ) => set( { price_overrides } ) } /> }
									</li>
								);
							} ) }
						</ul>
					) }
					{ config.mode === 'inherit' && ! sets.length && <p className="dev3po-b-muted">{ __( 'No global option set matches this product. Create one under Option sets and assign it by category, tag or product.', D ) }</p> }
					{ config.mode === 'none' && <Notice tone="warning">{ __( 'Shoppers see no options on this product.', D ) }</Notice> }
				</Stack>
			</Card>
			{ config.mode !== 'none' && (
				<Card
					title={ __( 'Product fields', D ) }
					description={ __( 'Fields only this product has. They appear before the global sets.', D ) }
					actions={ <Badge tone="neutral">{ config.fields.length }</Badge> }
				>
					<Builder fields={ config.fields } onChange={ ( fields ) => set( { fields } ) } issues={ [ ...issues, ...aiIssues ] } editing={ editing } setEditing={ setEditing } />
				</Card>
			) }
		</Stack>
	);
	const side = (
		<Stack gap={ 16 }>
			{ config.mode !== 'none' && <GeneratePanel context={ context } fields={ config.fields } onApply={ ( list, mode ) => set( { fields: mode === 'replace' ? list : [ ...config.fields, ...list ] } ) } /> }
			{ config.mode !== 'none' && config.fields.length > 0 && <IssuesPanel fields={ config.fields } issues={ issues } loading={ loading } onEdit={ setEditing } product={ product.title } aiIssues={ aiIssues } onAiIssues={ setAiIssues } /> }
			{ config.mode !== 'none' && config.fields.length > 0 && <LabelsPanel fields={ config.fields } product={ product.title } onApply={ ( fields ) => set( { fields } ) } /> }
			{ previewFields.length > 0 && <Preview fields={ previewFields } price={ product.price } /> }
		</Stack>
	);
	return (
		<div className={ compact ? 'dev3po-b-layout is-compact' : 'dev3po-b-layout' }>
			{ main }
			{ side }
		</div>
	);
}
