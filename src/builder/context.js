import { createContext, useContext, useEffect, useState } from '@wordpress/element';
import { request } from '../core3/api';
import { formatMoney } from '../storefront';

const fallback = { types: [], operators: [], locale: 'en_US', locales: [ 'en_US' ], currency: { symbol: '$', format: '%1$s%2$s', decimals: 2, dsep: '.', tsep: ',' }, features: {}, canManage: true };

export const BuilderContext = createContext( { boot: fallback, money: ( n ) => formatMoney( Number( n ) || 0, fallback.currency ) } );

export const useBuilder = () => useContext( BuilderContext );

let cache = null;

/**
 * Loads /bootstrap once per page.
 *
 * @return {Object} { boot, money, ready }
 */
export function useBootstrap() {
	const [ boot, setBoot ] = useState( cache || fallback );
	const [ ready, setReady ] = useState( !! cache );
	useEffect( () => {
		if ( cache ) {
			return;
		}
		request( 'bootstrap' )
			.then( ( b ) => {
				cache = { ...fallback, ...b };
				setBoot( cache );
			} )
			.catch( () => {} )
			.finally( () => setReady( true ) );
	}, [] );
	return { boot, ready, money: ( n ) => formatMoney( Number( n ) || 0, boot.currency ) };
}
