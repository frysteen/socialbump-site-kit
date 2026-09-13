/**
 * Download CSV buttons on the WooCommerce Analytics screens.
 *
 * Analytics is a React app, so the buttons are injected after each render and
 * put back whenever the route changes. Dates come from WooCommerce's own date
 * helpers, so a download always matches the figures on screen.
 */
( function () {
	'use strict';

	if ( typeof window.sbskAnalyticsCSV === 'undefined' ) {
		return;
	}

	var cfg = window.sbskAnalyticsCSV;

	var REPORTS = {
		'/analytics/overview': [ 'products', 'revenue', 'orders', 'variations' ],
		'/analytics/orders': [ 'orders' ],
		'/analytics/revenue': [ 'revenue' ],
		'/analytics/products': [ 'products' ],
		'/analytics/variations': [ 'variations' ]
	};

	var LABELS = {
		orders: 'Orders',
		revenue: 'Revenue',
		products: 'Products',
		variations: 'Variations'
	};

	var ICON = '<svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M3 17a1 1 0 011-1h12a1 1 0 110 2H4a1 1 0 01-1-1zm3.293-7.707a1 1 0 011.414 0L9 10.586V3a1 1 0 112 0v7.586l1.293-1.293a1 1 0 111.414 1.414l-3 3a1 1 0 01-1.414 0l-3-3a1 1 0 010-1.414z" clip-rule="evenodd"/></svg>';

	function params() {
		var out = {};

		window.location.search.replace( /[?&]([^=&]+)=([^&]*)/g, function ( whole, key, value ) {
			out[ decodeURIComponent( key ) ] = decodeURIComponent( value );

			return whole;
		} );

		return out;
	}

	/**
	 * The date range Analytics is actually showing, as plain YYYY-MM-DD.
	 */
	function dates() {
		var current = params();

		try {
			if ( window.wc && window.wc.date && typeof window.wc.date.getCurrentDates === 'function' ) {
				var resolved = window.wc.date.getCurrentDates( current, cfg.defaultRange );
				var primary = resolved && resolved.primary;

				if ( primary && primary.after && primary.before ) {
					return {
						after: primary.after.format( 'YYYY-MM-DD' ),
						before: primary.before.format( 'YYYY-MM-DD' )
					};
				}
			}
		} catch ( e ) {
			// Fall through to whatever is in the address bar.
		}

		return {
			after: ( current.after || '' ).substring( 0, 10 ),
			before: ( current.before || '' ).substring( 0, 10 )
		};
	}

	function url( action, report ) {
		var range = dates();
		var link = cfg.adminUrl + '?page=wc-admin&action=' + encodeURIComponent( action ) + '&_wpnonce=' + encodeURIComponent( cfg.nonce );

		if ( report ) {
			link += '&report=' + encodeURIComponent( report );
		}

		if ( range.after ) {
			link += '&after=' + encodeURIComponent( range.after );
		}

		if ( range.before ) {
			link += '&before=' + encodeURIComponent( range.before );
		}

		return link;
	}

	/**
	 * The address is rebuilt on mousedown, so a button clicked after the range
	 * changed still downloads what is on screen.
	 */
	function button( label, title, extraClass, action, report ) {
		var link = document.createElement( 'a' );

		link.className = 'sbsk-csv-btn' + ( extraClass ? ' ' + extraClass : '' );
		link.href = url( action, report );
		link.title = title;
		link.innerHTML = ICON + label;

		link.addEventListener( 'mousedown', function () {
			link.href = url( action, report );
		} );

		return link;
	}

	function clear() {
		var existing = document.querySelectorAll( '.sbsk-csv-wrap' );

		Array.prototype.forEach.call( existing, function ( node ) {
			node.remove();
		} );
	}

	function inject( reports, overview ) {
		clear();

		var anchor = document.querySelector( '.woocommerce-analytics-report-header' ) ||
			document.querySelector( '.woocommerce-filters-date' ) ||
			document.querySelector( '.woocommerce-filters' ) ||
			document.querySelector( '.woocommerce-analytics' );

		if ( ! anchor ) {
			return false;
		}

		var wrap = document.createElement( 'div' );
		wrap.className = 'sbsk-csv-wrap';

		if ( overview ) {
			var label = document.createElement( 'span' );
			label.className = 'sbsk-csv-wrap__label';
			label.textContent = 'Download CSV:';
			wrap.appendChild( label );
		}

		reports.forEach( function ( report ) {
			var text = overview ? LABELS[ report ] + ' CSV' : 'Download CSV';

			wrap.appendChild( button( text, 'Download ' + LABELS[ report ] + ' as CSV, for the range on screen', '', 'sbsk_analytics_csv', report ) );
		} );

		if ( overview && cfg.canZip ) {
			wrap.appendChild( button( 'Download All (ZIP)', 'Download every report as a ZIP, for the range on screen', 'sbsk-csv-btn--all', 'sbsk_analytics_csv_all', '' ) );
		}

		anchor.insertAdjacentElement( 'afterend', wrap );

		return true;
	}

	var timer = null;

	/**
	 * Analytics renders after the page loads, so keep looking for somewhere to
	 * put the buttons for a while, then give up quietly.
	 */
	function run() {
		var reports = REPORTS[ params().path || '' ];

		if ( ! reports ) {
			clear();

			return;
		}

		var overview = ( params().path === '/analytics/overview' );
		var tries = 0;

		clearInterval( timer );

		timer = setInterval( function () {
			tries++;

			if ( inject( reports, overview ) || tries > 60 ) {
				clearInterval( timer );
			}
		}, 200 );
	}

	// Analytics changes the address without reloading, so watch for that too.
	function watch( method ) {
		var original = history[ method ];

		history[ method ] = function () {
			original.apply( this, arguments );
			setTimeout( run, 150 );
		};
	}

	watch( 'pushState' );
	watch( 'replaceState' );

	window.addEventListener( 'popstate', function () {
		setTimeout( run, 150 );
	} );

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', run );
	} else {
		run();
	}
}() );
