import { useEffect, useRef, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { request } from '../core3/api';
import { Badge, Button, Card, ErrorNotice, IconButton, Inline, Modal, PageHeader, ResponsiveTable, useToast } from '../core3/ui';

const D = 'dev3-ai-product-options-for-woocommerce';

export function download( name, data ) {
	const blob = new window.Blob( [ JSON.stringify( data, null, 2 ) ], { type: 'application/json' } );
	const a = document.createElement( 'a' );
	a.href = URL.createObjectURL( blob );
	a.download = name;
	document.body.appendChild( a );
	a.click();
	a.remove();
}

export default function SetsList( { navigate } ) {
	const toast = useToast();
	const [ sets, setSets ] = useState( null );
	const [ error, setError ] = useState( null );
	const [ confirm, setConfirm ] = useState( null );
	const [ busy, setBusy ] = useState( false );
	const fileRef = useRef();
	const load = () => {
		setError( null );
		request( 'sets' ).then( setSets ).catch( setError );
	};
	useEffect( load, [] );

	const duplicate = ( s ) =>
		request( `sets/${ s.id }/duplicate`, { method: 'POST' } )
			.then( () => {
				toast.success( __( 'Option set duplicated as a draft.', D ) );
				load();
			} )
			.catch( ( e ) => toast.error( e ) );
	const remove = () => {
		setBusy( true );
		request( `sets/${ confirm.id }`, { method: 'DELETE' } )
			.then( () => {
				toast.success( __( 'Option set deleted.', D ) );
				setConfirm( null );
				load();
			} )
			.catch( ( e ) => toast.error( e ) )
			.finally( () => setBusy( false ) );
	};
	const importFile = ( e ) => {
		const file = e.target.files && e.target.files[ 0 ];
		if ( ! file ) {
			return;
		}
		file.text().then( ( text ) => {
			let set;
			try {
				set = JSON.parse( text );
			} catch ( err ) {
				toast.error( __( 'That file is not valid JSON.', D ) );
				return;
			}
			request( 'sets/import', { method: 'POST', data: { set } } )
				.then( ( saved ) => {
					toast.success( __( 'Option set imported as a draft.', D ) );
					navigate( `sets/${ saved.id }` );
				} )
				.catch( ( err ) => toast.error( err ) );
		} );
		e.target.value = '';
	};

	return (
		<>
			<PageHeader
				title={ __( 'Option sets', D ) }
				description={ __( 'Global groups of fields, assigned to products by category, tag or product.', D ) }
				actions={
					<Inline gap={ 8 }>
						<Button icon="external" onClick={ () => fileRef.current.click() }>
							{ __( 'Import', D ) }
						</Button>
						<Button variant="primary" icon="plus" onClick={ () => navigate( 'sets/new' ) }>
							{ __( 'New option set', D ) }
						</Button>
					</Inline>
				}
			/>
			<input ref={ fileRef } type="file" accept="application/json,.json" className="dev3po-ui-sr-only" onChange={ importFile } tabIndex={ -1 } aria-hidden="true" />
			<ErrorNotice error={ error } onRetry={ load } />
			<Card flush>
				<ResponsiveTable
					loading={ ! sets && ! error }
					rows={ sets || [] }
					pageSize={ 20 }
					defaultSort={ { key: 'priority', dir: 'asc' } }
					onRowClick={ ( r ) => navigate( `sets/${ r.id }` ) }
					columns={ [
						{ key: 'title', label: __( 'Name', D ), primary: true, sortable: true, render: ( r ) => <a href={ `#/sets/${ r.id }` } onClick={ ( e ) => e.stopPropagation() }>{ r.title }</a> },
						{ key: 'summary', label: __( 'Applies to', D ) },
						{ key: 'field_count', label: __( 'Fields', D ), align: 'end', sortable: true },
						{ key: 'priority', label: __( 'Priority', D ), align: 'end', sortable: true, hideOnMobile: true },
						{
							key: 'status',
							label: __( 'Status', D ),
							render: ( r ) => (
								<Inline gap={ 4 }>
									<Badge tone={ r.status === 'publish' ? 'success' : 'neutral' } dot>
										{ r.status === 'publish' ? __( 'Live', D ) : __( 'Draft', D ) }
									</Badge>
									{ r.errors > 0 && <Badge tone="danger">{ sprintf( /* translators: %d: count */ __( '%d errors', D ), r.errors ) }</Badge> }
								</Inline>
							),
						},
					] }
					rowActions={ ( r ) => (
						<Inline gap={ 2 }>
							<IconButton icon="edit" label={ sprintf( /* translators: %s: set */ __( 'Edit %s', D ), r.title ) } onClick={ () => navigate( `sets/${ r.id }` ) } />
							<IconButton icon="copy" label={ sprintf( /* translators: %s: set */ __( 'Duplicate %s', D ), r.title ) } onClick={ () => duplicate( r ) } />
							<IconButton icon="external" label={ sprintf( /* translators: %s: set */ __( 'Export %s', D ), r.title ) } onClick={ () => download( `option-set-${ r.id }.json`, { title: r.title, priority: r.priority, fields: r.fields } ) } />
							<IconButton icon="trash" label={ sprintf( /* translators: %s: set */ __( 'Delete %s', D ), r.title ) } onClick={ () => setConfirm( r ) } />
						</Inline>
					) }
					empty={ {
						icon: 'list',
						title: __( 'No option sets yet', D ),
						description: __( 'Create a set once (e.g. "Engraving & gift wrap") and use it on every matching product.', D ),
						action: (
							<Button variant="primary" icon="plus" onClick={ () => navigate( 'sets/new' ) }>
								{ __( 'Create option set', D ) }
							</Button>
						),
					} }
				/>
			</Card>
			<Modal
				open={ !! confirm }
				onClose={ () => setConfirm( null ) }
				size="sm"
				title={ __( 'Delete option set?', D ) }
				description={ confirm ? sprintf( /* translators: %s: set */ __( '"%s" disappears from every product it is assigned to. Orders keep the options they were placed with.', D ), confirm.title ) : '' }
				footer={
					<>
						<Button variant="ghost" onClick={ () => setConfirm( null ) } data-autofocus>
							{ __( 'Cancel', D ) }
						</Button>
						<Button variant="danger" onClick={ remove } loading={ busy }>
							{ __( 'Delete', D ) }
						</Button>
					</>
				}
			/>
		</>
	);
}
