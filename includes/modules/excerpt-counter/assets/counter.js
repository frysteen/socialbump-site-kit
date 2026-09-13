/**
 * Excerpt character counter.
 *
 * The classic editor has its excerpt box in the page from the start. The block
 * editor builds its panel the moment you open it, and rebuilds it whenever the
 * sidebar changes, so this watches the page for the field instead of polling.
 */
( function () {
	var settings = window.sbskExcerptCounter || {};
	var max = parseInt( settings.max, 10 ) || 160;

	// Anywhere WordPress puts an excerpt field, old and new.
	var SELECTORS = [
		'#excerpt',
		'.editor-post-excerpt textarea',
		'.editor-post-excerpt__textarea textarea',
		'.editor-post-excerpt__dropdown__content textarea'
	].join( ',' );

	function format( template, a, b ) {
		return String( template ).replace( '%1$s', a ).replace( '%2$s', b ).replace( '%s', a );
	}

	function update( field, readout ) {
		var used = field.value.length;

		readout.textContent = format( settings.format || '%1$s of %2$s characters', used, max );
		readout.classList.toggle( 'is-over', used > max );
	}

	function attach( field ) {
		if ( ! field || field.dataset.sbskCounter ) {
			return;
		}

		field.dataset.sbskCounter = '1';

		var readout = document.createElement( 'p' );
		readout.className = 'sbsk-excerpt-counter';
		readout.setAttribute( 'aria-live', 'polite' );

		if ( field.parentNode ) {
			field.parentNode.insertBefore( readout, field.nextSibling );
		}

		var refresh = function () {
			update( field, readout );
		};

		field.addEventListener( 'input', refresh );
		field.addEventListener( 'change', refresh );
		refresh();
	}

	function scan( root ) {
		var scope = root && root.querySelectorAll ? root : document;

		Array.prototype.forEach.call( scope.querySelectorAll( SELECTORS ), attach );

		// The node itself may be the field, when the editor swaps a panel in.
		if ( root && root.matches && root.matches( SELECTORS ) ) {
			attach( root );
		}
	}

	function start() {
		scan( document );

		if ( ! window.MutationObserver ) {
			return;
		}

		var pending = false;

		var observer = new MutationObserver( function ( records ) {
			if ( pending ) {
				return;
			}

			pending = true;

			// Let the editor finish rendering, then look once.
			window.requestAnimationFrame( function () {
				pending = false;

				records.forEach( function ( record ) {
					Array.prototype.forEach.call( record.addedNodes, function ( node ) {
						if ( node.nodeType === 1 ) {
							scan( node );
						}
					} );
				} );
			} );
		} );

		observer.observe( document.body, { childList: true, subtree: true } );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', start );
	} else {
		start();
	}
} )();