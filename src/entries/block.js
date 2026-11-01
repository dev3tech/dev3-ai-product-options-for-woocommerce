/**
 * Block editor UI for "Product options & add to cart" (server-rendered).
 */
import { registerBlockType } from '@wordpress/blocks';
import { InspectorControls, useBlockProps } from '@wordpress/block-editor';
import { PanelBody, TextControl } from '@wordpress/components';
import ServerSideRender from '@wordpress/server-side-render';
import { __ } from '@wordpress/i18n';

const D = 'dev3-ai-product-options-for-woocommerce';

registerBlockType( 'dev3po/product-options', {
	apiVersion: 2,
	title: __( 'Product options & add to cart', D ),
	description: __( 'Option fields with live price and the add-to-cart button for one product.', D ),
	category: 'woocommerce',
	icon: 'forms',
	keywords: [ 'options', 'add-ons', 'product' ],
	attributes: { productId: { type: 'number', default: 0 } },
	edit: ( { attributes, setAttributes } ) => {
		const props = useBlockProps();
		return (
			<div { ...props }>
				<InspectorControls>
					<PanelBody title={ __( 'Product', D ) }>
						<TextControl
							label={ __( 'Product ID', D ) }
							help={ __( '0 uses the current product (single product templates).', D ) }
							type="number"
							value={ attributes.productId || 0 }
							onChange={ ( v ) => setAttributes( { productId: parseInt( v, 10 ) || 0 } ) }
						/>
					</PanelBody>
				</InspectorControls>
				{ attributes.productId ? (
					<ServerSideRender block="dev3po/product-options" attributes={ attributes } />
				) : (
					<p style={ { padding: 16, border: '1px dashed #949494', borderRadius: 8 } }>{ __( 'Product options appear here on product pages. Set a product ID to preview a specific product.', D ) }</p>
				) }
			</div>
		);
	},
	save: () => null,
} );
