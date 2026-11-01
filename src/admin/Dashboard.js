import { useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { request } from '../core3/api';
import { Badge, Button, Card, Columns, EmptyState, ErrorNotice, Notice, PageHeader, ResponsiveTable, Stat, StatGrid } from '../core3/ui';
import { useBuilder } from '../builder/context';

const D = 'dev3-ai-product-options-for-woocommerce';

const FEATURES = [
	{ key: 'ai_generate', title: __( 'Generate options', D ), text: __( 'Builds a full option set with prices and conditions from the product.', D ) },
	{ key: 'ai_logic', title: __( 'Logic review', D ), text: __( 'Finds impossible conditions, loops and price conflicts.', D ) },
	{ key: 'ai_formula', title: __( 'Formula helper', D ), text: __( '"Add 2 per letter over 10" becomes a working formula.', D ) },
	{ key: 'ai_labels', title: __( 'Labels & translations', D ), text: __( 'Clear labels, help texts and tooltips in many languages.', D ) },
	{ key: 'ai_vision', title: __( 'Upload vision check', D ), text: __( 'Checks uploaded images for resolution and content fit.', D ) },
];

export default function Dashboard( { settings, navigate } ) {
	const { boot } = useBuilder();
	const [ stats, setStats ] = useState( null );
	const [ error, setError ] = useState( null );
	const load = () => {
		setError( null );
		request( 'stats' ).then( setStats ).catch( setError );
	};
	useEffect( load, [] );
	const steps = stats
		? [
				{ done: stats.sets > 0, title: __( 'Create an option set', D ), action: () => navigate( 'sets/new' ) },
				{ done: stats.published > 0, title: __( 'Publish it and assign categories or products', D ), action: () => navigate( 'sets' ) },
				{ done: settings.api_key_set, title: __( 'Connect DEV3 AI (optional)', D ), action: () => navigate( 'api' ) },
				{ done: stats.order_items > 0, title: __( 'Receive the first order with options', D ) },
		  ]
		: [];

	return (
		<>
			<PageHeader
				title={ __( 'Dashboard', D ) }
				description={ __( 'Product options, add-ons and price formulas across your store.', D ) }
				actions={
					<Button variant="primary" icon="plus" onClick={ () => navigate( 'sets/new' ) }>
						{ __( 'New option set', D ) }
					</Button>
				}
			/>
			{ ! settings.api_key_set && (
				<Notice
					tone="ai"
					title={ __( 'Connect DEV3 AI', D ) }
					actions={
						<Button size="sm" variant="primary" href="#/api">
							{ __( 'Add your API key', D ) }
						</Button>
					}
				>
					{ __( 'Add a Google Gemini API key to generate option sets, review logic, write formulas and check uploads. Every option, condition and price works without it.', D ) }
				</Notice>
			) }
			<ErrorNotice error={ error } onRetry={ load } />
			<StatGrid min={ 260 }>
				<Stat label={ __( 'Option sets', D ) } value={ stats ? String( stats.sets ) : '' } loading={ ! stats } icon="list" tone="brand" hint={ stats ? sprintf( /* translators: %d: count */ __( '%d published', D ), stats.published ) : '' } />
				<Stat label={ __( 'Option fields', D ) } value={ stats ? String( stats.fields ) : '' } loading={ ! stats } icon="columns" hint={ __( 'In global sets', D ) } />
				<Stat label={ __( 'Products with own options', D ) } value={ stats ? String( stats.products ) : '' } loading={ ! stats } icon="box" />
				<Stat label={ __( 'Ordered items with options', D ) } value={ stats ? String( stats.order_items ) : '' } loading={ ! stats } icon="success" tone="success" />
				<Stat label={ __( 'Customer uploads', D ) } value={ stats ? String( stats.uploads.total || 0 ) : '' } loading={ ! stats } icon="file" hint={ stats && stats.uploads.warn ? sprintf( /* translators: %d: count */ __( '%d flagged by DEV3 AI', D ), stats.uploads.warn ) : '' } />
				<Stat label={ __( 'Logic errors', D ) } value={ stats ? String( stats.issues ) : '' } loading={ ! stats } icon="alert" tone={ stats && stats.issues ? 'danger' : 'neutral' } hint={ stats && ! stats.issues ? __( 'All sets are consistent', D ) : '' } />
			</StatGrid>
			<Columns min={ 340 }>
				<Card
					title={ __( 'Recent option sets', D ) }
					flush
					actions={
						<Button size="sm" variant="ghost" href="#/sets">
							{ __( 'View all', D ) }
						</Button>
					}
				>
					<ResponsiveTable
						loading={ ! stats }
						rows={ stats ? stats.recent : [] }
						columns={ [
							{ key: 'title', label: __( 'Set', D ), primary: true, render: ( r ) => <a href={ `#/sets/${ r.id }` }>{ r.title }</a> },
							{ key: 'fields', label: __( 'Fields', D ), align: 'end' },
							{ key: 'status', label: __( 'Status', D ), render: ( r ) => ( r.errors ? <Badge tone="danger">{ __( 'Errors', D ) }</Badge> : <Badge tone={ r.status === 'publish' ? 'success' : 'neutral' }>{ r.status === 'publish' ? __( 'Live', D ) : __( 'Draft', D ) }</Badge> ) },
						] }
						empty={ { icon: 'list', title: __( 'No option sets yet', D ), description: __( 'Option sets add fields to many products at once.', D ), action: <Button variant="primary" onClick={ () => navigate( 'sets/new' ) }>{ __( 'Create option set', D ) }</Button> } }
					/>
				</Card>
				<Card title={ __( 'Get started', D ) }>
					{ stats ? (
						<ol className="dev3po-b-steps">
							{ steps.map( ( s, i ) => (
								<li key={ i } className={ s.done ? 'is-done' : '' }>
									<span className="dev3po-b-steps__dot" aria-hidden="true">
										{ s.done ? '✓' : i + 1 }
									</span>
									<span className="dev3po-b-steps__text">
										{ s.title }
										{ s.done && <span className="dev3po-ui-sr-only">{ __( '(done)', D ) }</span> }
									</span>
									{ ! s.done && s.action && (
										<Button size="sm" variant="ghost" onClick={ s.action }>
											{ __( 'Start', D ) }
										</Button>
									) }
								</li>
							) ) }
						</ol>
					) : (
						<EmptyState compact icon="clock" title={ __( 'Loading…', D ) } />
					) }
				</Card>
			</Columns>
			<Card tone="ai" title={ __( 'DEV3 AI tools', D ) } description={ __( 'Every AI result is a draft you accept, edit or reject.', D ) }>
				<div className="dev3po-b-features">
					{ FEATURES.map( ( f ) => {
						const on = boot.features[ f.key ] !== false;
						return (
							<div key={ f.key } className="dev3po-b-feature">
								<p className="dev3po-b-feature__title">
									{ f.title } <Badge tone={ on && settings.api_key_set ? 'success' : 'neutral' }>{ ! on ? __( 'Off', D ) : settings.api_key_set ? __( 'Ready', D ) : __( 'Needs key', D ) }</Badge>
								</p>
								<p className="dev3po-b-muted">{ f.text }</p>
							</div>
						);
					} ) }
				</div>
			</Card>
		</>
	);
}
