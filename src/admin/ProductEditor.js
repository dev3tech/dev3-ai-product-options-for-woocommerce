import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { request } from '../core3/api';
import { Badge, Button, ErrorNotice, Inline, PageHeader, SkeletonCard, useToast } from '../core3/ui';
import ProductOptions from '../builder/ProductOptions';

const D = 'dev3-ai-product-options-for-woocommerce';
const pick = ( p ) => ( { fields: p.fields, mode: p.mode, exclude_sets: p.exclude_sets, price_overrides: p.price_overrides || {} } );

export default function ProductEditor( { id } ) {
	const toast = useToast();
	const [ product, setProduct ] = useState( null );
	const [ config, setConfig ] = useState( null );
	const [ saved, setSaved ] = useState( '' );
	const [ error, setError ] = useState( null );
	const [ busy, setBusy ] = useState( false );
	useEffect( () => {
		request( `products/${ id }` )
			.then( ( p ) => {
				setProduct( p );
				setConfig( pick( p ) );
				setSaved( JSON.stringify( pick( p ) ) );
			} )
			.catch( setError );
	}, [ id ] );
	if ( error ) {
		return <ErrorNotice error={ error } />;
	}
	if ( ! product ) {
		return <SkeletonCard lines={ 6 } />;
	}
	const dirty = JSON.stringify( config ) !== saved;
	const save = () => {
		setBusy( true );
		request( `products/${ id }`, { method: 'PUT', data: config } )
			.then( ( p ) => {
				setProduct( p );
				setConfig( pick( p ) );
				setSaved( JSON.stringify( pick( p ) ) );
				toast.success( __( 'Product options saved.', D ) );
			} )
			.catch( ( e ) => toast.error( e ) )
			.finally( () => setBusy( false ) );
	};
	return (
		<>
			<PageHeader
				back={ { href: '#/products', label: __( 'Products', D ) } }
				title={ product.title }
				description={ dirty ? <Badge tone="warning">{ __( 'Unsaved changes', D ) }</Badge> : undefined }
				actions={
					<Inline gap={ 8 }>
						<Button variant="ghost" icon="external" href={ product.view_url } target="_blank" rel="noopener noreferrer">
							{ __( 'View product', D ) }
						</Button>
						<Button variant="primary" icon="check" onClick={ save } loading={ busy } disabled={ ! dirty }>
							{ __( 'Save options', D ) }
						</Button>
					</Inline>
				}
			/>
			<ProductOptions product={ product } config={ config } onChange={ setConfig } />
		</>
	);
}
