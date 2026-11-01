import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { request } from '../core3/api';
import config from '../core3/config';
import ApiTab from '../core3/ApiTab';
import UsageTab from '../core3/UsageTab';
import { AppShell, ErrorNotice, PageHeader, SkeletonCard, UsageMeter, useHashRoute } from '../core3/ui';
import { BuilderContext, useBootstrap } from '../builder/context';
import Dashboard from './Dashboard';
import SetsList from './SetsList';
import SetEditor from './SetEditor';
import ProductsList from './ProductsList';
import ProductEditor from './ProductEditor';
import UploadsTab from './UploadsTab';
import SettingsTab from './SettingsTab';

const D = 'dev3-ai-product-options-for-woocommerce';

const NAV = [
	{ id: 'dashboard', label: __( 'Dashboard', D ), icon: 'dashboard' },
	{ id: 'sets', label: __( 'Option sets', D ), icon: 'list' },
	{ id: 'products', label: __( 'Products', D ), icon: 'box' },
	{ id: 'uploads', label: __( 'Uploads', D ), icon: 'file' },
	{ id: 'settings', label: __( 'Settings', D ), icon: 'settings' },
	{ id: 'api', label: __( 'API Config', D ), icon: 'key' },
	{ id: 'usage', label: __( 'Usage Logs', D ), icon: 'chart' },
];

export default function App() {
	const [ route, navigate ] = useHashRoute( NAV.map( ( n ) => n.id ), 'dashboard' );
	const [ settings, setSettings ] = useState( null );
	const [ error, setError ] = useState( null );
	const [ meter, setMeter ] = useState( 0 );
	const builder = useBootstrap();

	const load = () => {
		setError( null );
		request( 'settings' ).then( setSettings ).catch( setError );
	};
	useEffect( load, [] );

	const save = ( patch ) =>
		request( 'settings', { method: 'POST', data: patch } ).then( ( next ) => {
			setSettings( next );
			setMeter( ( n ) => n + 1 );
			return next;
		} );

	const param = route.params[ 0 ];
	const screens = {
		dashboard: () => <Dashboard settings={ settings } navigate={ navigate } />,
		sets: () => ( param ? <SetEditor id={ param } navigate={ navigate } onUsage={ () => setMeter( ( n ) => n + 1 ) } /> : <SetsList navigate={ navigate } /> ),
		products: () => ( param ? <ProductEditor id={ param } navigate={ navigate } /> : <ProductsList navigate={ navigate } /> ),
		uploads: () => <UploadsTab />,
		settings: () => <SettingsTab settings={ settings } save={ save } />,
		api: () => (
			<>
				<PageHeader title={ __( 'API Config', D ) } description={ __( 'Connect Google Gemini, pick models and set a monthly AI budget.', D ) } />
				<ApiTab settings={ settings } save={ save } />
			</>
		),
		usage: () => (
			<>
				<PageHeader title={ __( 'Usage Logs', D ) } description={ __( 'Requests, tokens and estimated cost of every DEV3 AI feature.', D ) } />
				<UsageTab />
			</>
		),
	};

	return (
		<BuilderContext.Provider value={ builder }>
			<AppShell
				name={ config.name || 'DEV3 AI Product Options & Add-Ons for WooCommerce' }
				version={ config.version }
				icon={ config.assetsUrl ? `${ config.assetsUrl }img/icon.svg` : undefined }
				nav={ NAV }
				active={ route.id }
				actions={ <UsageMeter compact refreshKey={ meter } /> }
				footer={ __( 'Made by DEV3 Technologies · AI by Google Gemini, using your own API key.', D ) }
			>
				{ error && <ErrorNotice error={ error } onRetry={ load } /> }
				{ ! settings && ! error && <SkeletonCard lines={ 5 } /> }
				{ settings && screens[ route.id ]() }
			</AppShell>
		</BuilderContext.Provider>
	);
}
