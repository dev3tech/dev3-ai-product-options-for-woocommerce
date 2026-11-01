import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { Button, Card, ColorField, FormGrid, NumberField, PageHeader, Select, Stack, TextField, Toggle, useToast } from '../core3/ui';

const D = 'dev3-ai-product-options-for-woocommerce';

const KEYS = [ 'enabled', 'show_totals', 'label_options_total', 'label_grand_total', 'loop_button', 'loop_button_text', 'cart_edit', 'cart_show_prices', 'accent_color', 'upload_max_mb', 'upload_retention', 'upload_rate', 'vision_mode', 'ai_generate', 'ai_logic', 'ai_formula', 'ai_labels', 'ai_vision', 'delete_on_uninstall' ];

export default function SettingsTab( { settings, save } ) {
	const toast = useToast();
	const [ form, setForm ] = useState( settings );
	const [ busy, setBusy ] = useState( false );
	const set = ( key ) => ( value ) => setForm( { ...form, [ key ]: value } );
	const dirty = KEYS.some( ( k ) => form[ k ] !== settings[ k ] );
	const submit = ( e ) => {
		e.preventDefault();
		setBusy( true );
		const patch = {};
		KEYS.forEach( ( k ) => ( patch[ k ] = form[ k ] === '' && [ 'upload_max_mb', 'upload_retention', 'upload_rate' ].includes( k ) ? settings[ k ] : form[ k ] ) );
		save( patch )
			.then( ( next ) => {
				setForm( next );
				toast.success( __( 'Settings saved.', D ) );
			} )
			.catch( ( err ) => toast.error( err ) )
			.finally( () => setBusy( false ) );
	};
	const footer = (
		<Button type="submit" variant="primary" loading={ busy } disabled={ ! dirty }>
			{ __( 'Save settings', D ) }
		</Button>
	);
	return (
		<form onSubmit={ submit }>
			<PageHeader title={ __( 'Settings', D ) } description={ __( 'Storefront display, cart behaviour, uploads and DEV3 AI features.', D ) } />
			<Stack gap={ 16 }>
				<Card title={ __( 'Storefront', D ) } footer={ footer }>
					<Stack gap={ 14 }>
						<Toggle label={ __( 'Show product options on the storefront', D ) } checked={ !! form.enabled } onChange={ set( 'enabled' ) } help={ __( 'Turn off to hide every option without deleting anything.', D ) } />
						<Toggle label={ __( 'Show the live price box', D ) } checked={ !! form.show_totals } onChange={ set( 'show_totals' ) } />
						<FormGrid>
							<TextField label={ __( '"Options total" label', D ) } value={ form.label_options_total } onChange={ set( 'label_options_total' ) } maxLength={ 40 } />
							<TextField label={ __( '"Total" label', D ) } value={ form.label_grand_total } onChange={ set( 'label_grand_total' ) } maxLength={ 40 } />
						</FormGrid>
						<FormGrid>
							<Select
								label={ __( 'Shop & category buttons', D ) }
								value={ form.loop_button }
								onChange={ set( 'loop_button' ) }
								options={ [
									{ value: 'required', label: __( 'Link to the product when an option is required', D ) },
									{ value: 'always', label: __( 'Always link to the product when it has options', D ) },
									{ value: 'never', label: __( 'Keep the normal add-to-cart button', D ) },
								] }
							/>
							<TextField label={ __( 'Button text', D ) } value={ form.loop_button_text } onChange={ set( 'loop_button_text' ) } maxLength={ 40 } />
						</FormGrid>
						<ColorField label={ __( 'Accent colour (optional)', D ) } value={ form.accent_color || '' } onChange={ set( 'accent_color' ) } help={ __( 'Empty = use the theme’s text colour.', D ) } />
					</Stack>
				</Card>
				<Card title={ __( 'Cart & orders', D ) } footer={ footer }>
					<Stack gap={ 14 }>
						<Toggle label={ __( 'Let shoppers edit options from the cart', D ) } checked={ !! form.cart_edit } onChange={ set( 'cart_edit' ) } help={ __( 'Adds an "Edit options" link; works in the classic and block cart.', D ) } />
						<Toggle label={ __( 'Show option prices in cart, checkout and emails', D ) } checked={ !! form.cart_show_prices } onChange={ set( 'cart_show_prices' ) } />
						<Toggle label={ __( 'Delete all plugin data when the plugin is uninstalled', D ) } checked={ !! form.delete_on_uninstall } onChange={ set( 'delete_on_uninstall' ) } help={ __( 'Option sets, product options, uploads and settings. Orders keep their option details.', D ) } />
					</Stack>
				</Card>
				<Card title={ __( 'Uploads', D ) } footer={ footer }>
					<FormGrid>
						<NumberField label={ __( 'Maximum file size (MB)', D ) } value={ form.upload_max_mb } min={ 0.5 } max={ 64 } step={ 0.5 } onChange={ set( 'upload_max_mb' ) } />
						<NumberField label={ __( 'Delete files never ordered after (days)', D ) } value={ form.upload_retention } min={ 1 } max={ 365 } onChange={ set( 'upload_retention' ) } />
						<NumberField label={ __( 'Uploads per minute per visitor', D ) } value={ form.upload_rate } min={ 1 } max={ 120 } onChange={ set( 'upload_rate' ) } />
						<Select
							label={ __( 'When DEV3 AI says an image does not fit', D ) }
							value={ form.vision_mode }
							onChange={ set( 'vision_mode' ) }
							options={ [
								{ value: 'warn', label: __( 'Warn the shopper, accept the file', D ) },
								{ value: 'block', label: __( 'Reject the file', D ) },
							] }
						/>
					</FormGrid>
				</Card>
				<Card tone="ai" title={ __( 'DEV3 AI features', D ) } description={ __( 'Each feature can be switched off. With AI off the plugin keeps working; only the AI buttons disappear.', D ) } footer={ footer }>
					<Stack gap={ 12 }>
						<Toggle label={ __( 'Generate options from the product', D ) } checked={ !! form.ai_generate } onChange={ set( 'ai_generate' ) } />
						<Toggle label={ __( 'Logic review', D ) } checked={ !! form.ai_logic } onChange={ set( 'ai_logic' ) } />
						<Toggle label={ __( 'Formula helper', D ) } checked={ !! form.ai_formula } onChange={ set( 'ai_formula' ) } />
						<Toggle label={ __( 'Labels, help texts & translations', D ) } checked={ !! form.ai_labels } onChange={ set( 'ai_labels' ) } />
						<Toggle label={ __( 'Vision check for uploaded images (storefront)', D ) } checked={ !! form.ai_vision } onChange={ set( 'ai_vision' ) } help={ __( 'Runs through the rate-limited storefront proxy; also needs "Storefront AI" on in API Config.', D ) } />
					</Stack>
				</Card>
			</Stack>
		</form>
	);
}
