import { useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { request } from '../core3/api';
import { Badge, Button, Card, ErrorNotice, IconButton, Inline, Modal, PageHeader, ResponsiveTable, Tabs, useToast } from '../core3/ui';

const D = 'dev3-ai-product-options-for-woocommerce';

const STATUS = {
	ok: { tone: 'success', label: __( 'Looks good', D ) },
	warn: { tone: 'warning', label: __( 'Flagged', D ) },
	fail: { tone: 'danger', label: __( 'Rejected', D ) },
	unchecked: { tone: 'neutral', label: __( 'Not checked', D ) },
	none: { tone: 'neutral', label: __( 'Not checked', D ) },
};

const size = ( b ) => ( b > 1048576 ? `${ ( b / 1048576 ).toFixed( 1 ) } MB` : `${ Math.max( 1, Math.round( b / 1024 ) ) } KB` );

export default function UploadsTab() {
	const toast = useToast();
	const [ data, setData ] = useState( null );
	const [ error, setError ] = useState( null );
	const [ page, setPage ] = useState( 1 );
	const [ status, setStatus ] = useState( 'all' );
	const [ confirm, setConfirm ] = useState( null );
	const load = () => {
		setError( null );
		request( `uploads?page=${ page }&status=${ status }` ).then( setData ).catch( setError );
	};
	useEffect( load, [ page, status ] ); // eslint-disable-line react-hooks/exhaustive-deps
	const remove = () =>
		request( `uploads/${ confirm.id }`, { method: 'DELETE' } )
			.then( () => {
				toast.success( __( 'File deleted.', D ) );
				setConfirm( null );
				load();
			} )
			.catch( ( e ) => toast.error( e ) );
	const orderUrl = ( id ) => `${ window.dev3poConfig && window.dev3poConfig.adminUrl ? window.dev3poConfig.adminUrl : '/wp-admin/' }post.php?post=${ id }&action=edit`;
	return (
		<>
			<PageHeader title={ __( 'Uploads', D ) } description={ __( 'Files shoppers uploaded with their options, with resolution and DEV3 AI vision results. Files never ordered are removed after the retention period.', D ) } />
			<Tabs
				variant="pills"
				label={ __( 'Filter uploads', D ) }
				value={ status }
				onChange={ ( v ) => {
					setStatus( v );
					setPage( 1 );
				} }
				tabs={ [
					{ id: 'all', label: __( 'All', D ) },
					{ id: 'attached', label: __( 'In orders', D ) },
					{ id: 'pending', label: __( 'Not ordered yet', D ) },
					{ id: 'warn', label: __( 'Flagged', D ) },
				] }
			/>
			<ErrorNotice error={ error } onRetry={ load } />
			<Card flush>
				<ResponsiveTable
					loading={ ! data && ! error }
					rows={ data ? data.items : [] }
					pagination={ data ? { page, perPage: 20, total: data.total, onPageChange: setPage } : undefined }
					columns={ [
						{
							key: 'file_name',
							label: __( 'File', D ),
							primary: true,
							render: ( r ) => (
								<span className="dev3po-b-product">
									{ /^image\//.test( r.mime ) ? <img src={ r.url } alt="" width="40" height="40" loading="lazy" /> : <span className="dev3po-b-product__ph" aria-hidden="true" /> }
									<span>
										<a href={ r.url } target="_blank" rel="noopener noreferrer">
											{ r.file_name }
										</a>
										<small className="dev3po-b-sub">
											{ size( r.size ) }
											{ r.width ? ` · ${ r.width }×${ r.height } px` : '' }
										</small>
									</span>
								</span>
							),
						},
						{ key: 'product', label: __( 'Product', D ) },
						{
							key: 'check_status',
							label: __( 'Check', D ),
							render: ( r ) => (
								<span className="dev3po-b-check">
									<Badge tone={ ( STATUS[ r.check_status ] || STATUS.none ).tone }>{ ( STATUS[ r.check_status ] || STATUS.none ).label }</Badge>
									{ r.check_message && <small className="dev3po-b-sub">{ r.check_message }</small> }
								</span>
							),
						},
						{ key: 'order_id', label: __( 'Order', D ), render: ( r ) => ( r.order_id ? <a href={ orderUrl( r.order_id ) }>{ `#${ r.order_id }` }</a> : <span className="dev3po-b-muted">{ __( 'Not ordered', D ) }</span> ) },
						{ key: 'created_at', label: __( 'Uploaded', D ), hideOnMobile: true, render: ( r ) => new Date( r.created_at ).toLocaleString() },
					] }
					rowActions={ ( r ) => (
						<Inline gap={ 2 }>
							<IconButton icon="external" label={ sprintf( /* translators: %s: file */ __( 'Open %s', D ), r.file_name ) } href={ r.url } target="_blank" />
							<IconButton icon="trash" label={ sprintf( /* translators: %s: file */ __( 'Delete %s', D ), r.file_name ) } onClick={ () => setConfirm( r ) } />
						</Inline>
					) }
					empty={ { icon: 'file', title: __( 'No uploads yet', D ), description: __( 'Add a File upload field to a product; shopper files appear here.', D ) } }
				/>
			</Card>
			<Modal
				open={ !! confirm }
				onClose={ () => setConfirm( null ) }
				size="sm"
				title={ __( 'Delete this file?', D ) }
				description={ confirm && confirm.order_id ? __( 'This file belongs to an order. The order keeps the file name but the file itself is gone.', D ) : __( 'The file is removed from the server.', D ) }
				footer={
					<>
						<Button variant="ghost" onClick={ () => setConfirm( null ) } data-autofocus>
							{ __( 'Cancel', D ) }
						</Button>
						<Button variant="danger" onClick={ remove }>
							{ __( 'Delete file', D ) }
						</Button>
					</>
				}
			/>
		</>
	);
}
