import { useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { request } from '../core3/api';
import { Badge, Card, ErrorNotice, Inline, PageHeader, ResponsiveTable, Tabs, TextField } from '../core3/ui';
import { useBuilder } from '../builder/context';

const D = 'dev3-ai-product-options-for-woocommerce';

export default function ProductsList( { navigate } ) {
	const { money } = useBuilder();
	const [ data, setData ] = useState( null );
	const [ error, setError ] = useState( null );
	const [ page, setPage ] = useState( 1 );
	const [ search, setSearch ] = useState( '' );
	const [ only, setOnly ] = useState( 'all' );
	useEffect( () => {
		const t = setTimeout( () => {
			setError( null );
			request( `products?page=${ page }&per_page=20&search=${ encodeURIComponent( search ) }${ only === 'own' ? '&only=1' : '' }` )
				.then( setData )
				.catch( setError );
		}, 250 );
		return () => clearTimeout( t );
	}, [ page, search, only ] );
	const MODE = { inherit: __( 'Fields + global sets', D ), override: __( 'Own fields only', D ), none: __( 'Options off', D ) };
	return (
		<>
			<PageHeader title={ __( 'Products', D ) } description={ __( 'Per-product fields, which global sets apply, and price overrides.', D ) } />
			<Inline gap={ 12 } align="between" className="dev3po-b-toolbar">
				<Tabs
					variant="pills"
					label={ __( 'Filter', D ) }
					value={ only }
					onChange={ ( v ) => {
						setOnly( v );
						setPage( 1 );
					} }
					tabs={ [
						{ id: 'all', label: __( 'All products', D ) },
						{ id: 'own', label: __( 'With own fields', D ) },
					] }
				/>
				<TextField
					label={ __( 'Search products', D ) }
					hideLabel
					type="search"
					placeholder={ __( 'Search products…', D ) }
					value={ search }
					onChange={ ( v ) => {
						setSearch( v );
						setPage( 1 );
					} }
				/>
			</Inline>
			<ErrorNotice error={ error } />
			<Card flush>
				<ResponsiveTable
					loading={ ! data && ! error }
					rows={ data ? data.items : [] }
					pagination={ data ? { page, perPage: 20, total: data.total, onPageChange: setPage } : undefined }
					onRowClick={ ( r ) => navigate( `products/${ r.id }` ) }
					columns={ [
						{
							key: 'title',
							label: __( 'Product', D ),
							primary: true,
							render: ( r ) => (
								<span className="dev3po-b-product">
									{ r.image ? <img src={ r.image } alt="" width="36" height="36" /> : <span className="dev3po-b-product__ph" aria-hidden="true" /> }
									<a href={ `#/products/${ r.id }` } onClick={ ( e ) => e.stopPropagation() }>
										{ r.title }
									</a>
								</span>
							),
						},
						{ key: 'price', label: __( 'Price', D ), align: 'end', render: ( r ) => money( r.price ), hideOnMobile: true },
						{ key: 'total', label: __( 'Fields shown', D ), align: 'end', render: ( r ) => ( r.total ? String( r.total ) : '—' ) },
						{ key: 'own_fields', label: __( 'Own fields', D ), align: 'end', hideOnMobile: true },
						{ key: 'sets', label: __( 'Global sets', D ), render: ( r ) => ( r.sets.length ? sprintf( /* translators: %d: count */ __( '%d sets', D ), r.sets.length ) : '—' ) },
						{ key: 'mode', label: __( 'Mode', D ), render: ( r ) => <Badge tone={ r.mode === 'none' ? 'neutral' : r.mode === 'override' ? 'info' : 'brand' }>{ MODE[ r.mode ] }</Badge> },
					] }
					empty={ { icon: 'box', title: search ? __( 'No products match', D ) : __( 'No products yet', D ), description: __( 'Create WooCommerce products first, then add options here or in the product editor.', D ) } }
				/>
			</Card>
		</>
	);
}
