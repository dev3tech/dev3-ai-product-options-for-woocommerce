/**
 * "DEV3 AI Options" tab in the WooCommerce product editor. Edits are written to a hidden input and
 * saved together with the product (Update button).
 */
import { createElement, render, useEffect, useState } from '@wordpress/element';
import * as element from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { request } from '../core3/api';
import { ErrorNotice, SkeletonCard, ToastProvider } from '../core3/ui';
import { BuilderContext, useBootstrap } from '../builder/context';
import ProductOptions from '../builder/ProductOptions';
import '../core3/admin-kit.css';
import '../storefront/storefront.css';
import '../admin/admin.css';

const D = 'dev3-ai-product-options-for-woocommerce';

function liveContext() {
	const title = document.getElementById( 'title' );
	const excerpt = document.getElementById( 'excerpt' );
	const mce = window.tinymce && window.tinymce.get( 'content' );
	const content = mce && ! mce.isHidden() ? mce.getContent( { format: 'text' } ) : ( document.getElementById( 'content' ) || {} ).value || '';
	const price = document.getElementById( '_regular_price' );
	return {
		title: title ? title.value : '',
		description: [ excerpt ? excerpt.value : '', content ].filter( Boolean ).join( '\n' ).slice( 0, 2500 ),
		price: price && price.value ? Number( String( price.value ).replace( ',', '.' ) ) : undefined,
	};
}

function ProductTab( { id, input } ) {
	const builder = useBootstrap();
	const [ product, setProduct ] = useState( null );
	const [ error, setError ] = useState( null );
	const [ config, setConfig ] = useState( () => {
		try {
			return { fields: [], mode: 'inherit', exclude_sets: [], price_overrides: {}, ...JSON.parse( input.value || '{}' ) };
		} catch ( e ) {
			return { fields: [], mode: 'inherit', exclude_sets: [], price_overrides: {} };
		}
	} );
	useEffect( () => {
		request( `products/${ id }` ).then( setProduct ).catch( setError );
	}, [ id ] );
	useEffect( () => {
		input.value = JSON.stringify( config );
	}, [ config, input ] );
	return (
		<BuilderContext.Provider value={ builder }>
			<ToastProvider>
				<div className="dev3po-product-tab">
					<p className="dev3po-product-tab__intro">
						<strong>{ __( 'DEV3 AI Product Options', D ) }</strong> · { __( 'Changes are saved when you update the product.', D ) }
					</p>
					<ErrorNotice error={ error } />
					{ ! product && ! error && <SkeletonCard lines={ 4 } /> }
					{ product && <ProductOptions product={ product } config={ config } onChange={ setConfig } getContext={ liveContext } compact /> }
				</div>
			</ToastProvider>
		</BuilderContext.Provider>
	);
}

const node = document.getElementById( 'dev3po-product-root' );
const input = document.getElementById( 'dev3po-product-config' );
if ( node && input ) {
	const app = createElement( ProductTab, { id: Number( node.dataset.product ), input } );
	if ( element.createRoot ) {
		element.createRoot( node ).render( app );
	} else {
		render( app, node );
	}
}
