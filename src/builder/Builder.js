/**
 * Field list builder: add (palette), reorder (drag + buttons), duplicate, delete, edit (drawer).
 */
import { useRef, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { Badge, Button, EmptyState, IconButton, Modal } from '../core3/ui';
import { useBuilder } from './context';
import FieldEditor from './FieldEditor';
import { cloneField, depths, GROUPS, isInput, newField, priceSummary, TYPES, typeMeta } from './model';

const D = 'dev3-ai-product-options-for-woocommerce';

export function TypeGlyph( { type } ) {
	return (
		<span className={ `dev3po-b-glyph is-${ typeMeta( type ).group }` } aria-hidden="true">
			{ typeMeta( type ).glyph }
		</span>
	);
}

/**
 * Palette modal.
 *
 * @param {Object}   props         Props.
 * @param {boolean}  props.open    Open.
 * @param {Function} props.onClose Close.
 * @param {Function} props.onPick  ( type ) => void.
 * @param {boolean}  props.nested  Repeater sub-fields (no layout types).
 * @return {Element} Modal.
 */
export function Palette( { open, onClose, onPick, nested = false } ) {
	const [ q, setQ ] = useState( '' );
	const list = TYPES.filter( ( t ) => ! nested || ( t.group !== 'layout' && t.type !== 'hidden' ) ).filter( ( t ) => ! q || ( t.label + ' ' + t.hint ).toLowerCase().includes( q.toLowerCase() ) );
	return (
		<Modal open={ open } onClose={ onClose } size="lg" title={ __( 'Add a field', D ) } description={ __( 'Pick a field type. You can change every setting afterwards.', D ) }>
			<div className="dev3po-b-palette">
				<label className="dev3po-b-search">
					<span className="dev3po-ui-sr-only">{ __( 'Search field types', D ) }</span>
					<input type="search" className="dev3po-ui-input" placeholder={ __( 'Search field types…', D ) } value={ q } onChange={ ( e ) => setQ( e.target.value ) } data-autofocus />
				</label>
				{ GROUPS.map( ( g ) => {
					const items = list.filter( ( t ) => t.group === g.id );
					if ( ! items.length ) {
						return null;
					}
					return (
						<section key={ g.id } className="dev3po-b-palette__group" aria-labelledby={ `dev3po-pal-${ g.id }` }>
							<h3 id={ `dev3po-pal-${ g.id }` }>{ g.label }</h3>
							<div className="dev3po-b-palette__grid">
								{ items.map( ( t ) => (
									<button
										type="button"
										key={ t.type }
										className="dev3po-b-palette__item"
										onClick={ () => {
											onPick( t.type );
											setQ( '' );
										} }
									>
										<TypeGlyph type={ t.type } />
										<span>
											<strong>{ t.label }</strong>
											<small>{ t.hint }</small>
										</span>
									</button>
								) ) }
							</div>
						</section>
					);
				} ) }
				{ ! list.length && <EmptyState compact icon="search" title={ __( 'No field type matches', D ) } /> }
			</div>
		</Modal>
	);
}

/**
 * Builder.
 *
 * @param {Object}   props           Props.
 * @param {Array}    props.fields    Fields.
 * @param {Function} props.onChange  ( fields ) => void.
 * @param {Array}    props.issues    Checker issues (badges per field).
 * @param {boolean}  props.nested    Repeater sub-field builder.
 * @param {*}        props.emptyAction Extra empty-state action (AI generate).
 * @param {string}   props.editing   Field id to open (controlled from issues).
 * @param {Function} props.setEditing Setter.
 * @return {Element} Builder.
 */
export default function Builder( { fields, onChange, issues = [], nested = false, emptyAction, editing: editingProp, setEditing: setEditingProp } ) {
	const { money } = useBuilder();
	const [ paletteOpen, setPaletteOpen ] = useState( false );
	const [ localEditing, setLocalEditing ] = useState( null );
	const editing = editingProp !== undefined ? editingProp : localEditing;
	const setEditing = setEditingProp || setLocalEditing;
	const [ dragId, setDragId ] = useState( null );
	const [ overId, setOverId ] = useState( null );
	const listRef = useRef();
	const depth = depths( fields );

	const update = ( id, patch ) => onChange( fields.map( ( f ) => ( f.id === id ? { ...f, ...patch } : f ) ) );
	const remove = ( id ) => {
		onChange( fields.filter( ( f ) => f.id !== id ) );
		if ( editing === id ) {
			setEditing( null );
		}
	};
	const move = ( index, delta ) => {
		const to = index + delta;
		if ( to < 0 || to >= fields.length ) {
			return;
		}
		const next = [ ...fields ];
		const [ item ] = next.splice( index, 1 );
		next.splice( to, 0, item );
		onChange( next );
		window.requestAnimationFrame( () => {
			const btn = listRef.current && listRef.current.querySelector( `[data-id="${ item.id }"] .dev3po-b-row__move-${ delta < 0 ? 'up' : 'down' }` );
			if ( btn && ! btn.disabled ) {
				btn.focus();
			}
		} );
	};
	const add = ( type ) => {
		const f = newField( type, fields );
		onChange( [ ...fields, f ] );
		setPaletteOpen( false );
		setEditing( f.id );
	};
	const drop = ( targetId ) => {
		if ( ! dragId || dragId === targetId ) {
			return;
		}
		const next = fields.filter( ( f ) => f.id !== dragId );
		const idx = next.findIndex( ( f ) => f.id === targetId );
		next.splice( idx, 0, fields.find( ( f ) => f.id === dragId ) );
		onChange( next );
	};
	const byField = {};
	issues.forEach( ( i ) => {
		if ( i.field ) {
			byField[ i.field ] = byField[ i.field ] || { error: 0, warning: 0 };
			if ( i.severity === 'error' || i.severity === 'warning' ) {
				byField[ i.field ][ i.severity ]++;
			}
		}
	} );
	const current = fields.find( ( f ) => f.id === editing );

	return (
		<div className="dev3po-b">
			{ fields.length === 0 ? (
				<EmptyState
					icon="list"
					title={ nested ? __( 'No sub-fields yet', D ) : __( 'No fields yet', D ) }
					description={ nested ? __( 'Add the fields that repeat for each entry.', D ) : __( 'Add fields one by one, or let DEV3 AI build the whole set from the product.', D ) }
					action={
						<Button variant="primary" icon="plus" onClick={ () => setPaletteOpen( true ) }>
							{ __( 'Add field', D ) }
						</Button>
					}
					secondary={ emptyAction }
					compact={ nested }
				/>
			) : (
				<ol className="dev3po-b-list" ref={ listRef } aria-label={ nested ? __( 'Sub-fields', D ) : __( 'Fields', D ) }>
					{ fields.map( ( f, i ) => {
						const counts = byField[ f.id ];
						const price = priceSummary( f, money );
						const layout = ! isInput( f.type );
						return (
							<li
								key={ f.id }
								data-id={ f.id }
								className={ `dev3po-b-row is-${ f.type }${ layout ? ' is-layout' : '' }${ dragId === f.id ? ' is-dragging' : '' }${ overId === f.id && dragId !== f.id ? ' is-over' : '' }` }
								style={ { '--dev3po-depth': depth[ f.id ] || 0 } }
								draggable
								onDragStart={ ( e ) => {
									setDragId( f.id );
									e.dataTransfer.effectAllowed = 'move';
									e.dataTransfer.setData( 'text/plain', f.id );
								} }
								onDragOver={ ( e ) => {
									e.preventDefault();
									setOverId( f.id );
								} }
								onDragLeave={ () => setOverId( null ) }
								onDrop={ ( e ) => {
									e.preventDefault();
									drop( f.id );
									setDragId( null );
									setOverId( null );
								} }
								onDragEnd={ () => {
									setDragId( null );
									setOverId( null );
								} }
							>
								<span className="dev3po-b-row__handle" aria-hidden="true" title={ __( 'Drag to reorder', D ) }>
									⋮⋮
								</span>
								<TypeGlyph type={ f.type } />
								<button type="button" className="dev3po-b-row__main" onClick={ () => setEditing( f.id ) } aria-label={ sprintf( /* translators: %s: field label */ __( 'Edit %s', D ), f.label || typeMeta( f.type ).label ) }>
									<span className="dev3po-b-row__title">
										{ f.label || <em>{ __( '(no label)', D ) }</em> }
										{ f.required && (
											<span className="dev3po-b-req" aria-label={ __( 'required', D ) }>
												*
											</span>
										) }
									</span>
									<span className="dev3po-b-row__meta">
										<span>{ typeMeta( f.type ).label }</span>
										{ ! layout && <code>{ `{${ f.key }}` }</code> }
										{ f.options && <span>{ sprintf( /* translators: %d: count */ __( '%d choices', D ), f.options.length ) }</span> }
										{ f.subfields && <span>{ sprintf( /* translators: %d: count */ __( '%d sub-fields', D ), f.subfields.length ) }</span> }
									</span>
								</button>
								<span className="dev3po-b-row__badges">
									{ price && <Badge tone="brand">{ price.length > 28 ? price.slice( 0, 28 ) + '…' : price }</Badge> }
									{ f.conditions && f.conditions.enabled && <Badge tone="info">{ f.conditions.action === 'hide' ? __( 'Hide if…', D ) : __( 'Show if…', D ) }</Badge> }
									{ counts && counts.error > 0 && (
										<Badge tone="danger" dot>
											{ sprintf( /* translators: %d: count */ __( '%d error', D ), counts.error ) }
										</Badge>
									) }
									{ counts && ! counts.error && counts.warning > 0 && (
										<Badge tone="warning" dot>
											{ __( 'Check', D ) }
										</Badge>
									) }
								</span>
								<span className="dev3po-b-row__actions">
									<IconButton icon="chevronLeft" className="dev3po-b-rot dev3po-b-row__move-up" label={ __( 'Move up', D ) } onClick={ () => move( i, -1 ) } disabled={ i === 0 } size="sm" />
									<IconButton icon="chevronRight" className="dev3po-b-rot dev3po-b-row__move-down" label={ __( 'Move down', D ) } onClick={ () => move( i, 1 ) } disabled={ i === fields.length - 1 } size="sm" />
									<IconButton
										icon="copy"
										label={ __( 'Duplicate', D ) }
										size="sm"
										onClick={ () => {
											const next = [ ...fields ];
											next.splice( i + 1, 0, cloneField( f, fields ) );
											onChange( next );
										} }
									/>
									<IconButton icon="trash" label={ __( 'Delete', D ) } size="sm" onClick={ () => remove( f.id ) } />
								</span>
							</li>
						);
					} ) }
				</ol>
			) }
			{ fields.length > 0 && (
				<div className="dev3po-b-addbar">
					<Button icon="plus" onClick={ () => setPaletteOpen( true ) }>
						{ nested ? __( 'Add sub-field', D ) : __( 'Add field', D ) }
					</Button>
				</div>
			) }
			<Palette open={ paletteOpen } onClose={ () => setPaletteOpen( false ) } onPick={ add } nested={ nested } />
			{ current && (
				<FieldEditor
					key={ current.id }
					field={ current }
					fields={ fields }
					onChange={ ( patch ) => update( current.id, patch ) }
					onClose={ () => setEditing( null ) }
					renderSubfields={ ( rf ) => <SubBuilder field={ rf } onChange={ ( subfields ) => update( rf.id, { subfields } ) } /> }
				/>
			) }
		</div>
	);
}

function SubBuilder( { field, onChange } ) {
	return <Builder fields={ field.subfields || [] } onChange={ onChange } nested />;
}
