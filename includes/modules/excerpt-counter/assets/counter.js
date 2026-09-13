/**
 * Excerpt character counter.
 *
 * The classic editor has its excerpt box in the page from the start. The block
 * editor builds its panel the moment you open it, and rebuilds it whenever the
 * sidebar changes, so this watches the page for the field instead of polling.
 *
 * WooCommerce puts the product short description in a TinyMCE editor over the
 * same textarea. Typing there happens inside an iframe and never touches the
 * textarea until the post is saved, so the editor is asked for its own content
 * whenever it is the one on show.
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

	/** The rich editor sitting over this field, when it is the one being used. */
	function editorFor( field ) {
		if ( ! window.tinymce || ! field.id ) {
			return null;
		}

		var editor = window.tinymce.get( field.id );

		return ( editor && ! editor.isHidden() ) ? editor : null;
	}

	/** What the person can actually see in the field. */
	function contentOf( field ) {
		var editor = editorFor( field );

		if ( ! editor ) {
			return field.value;
		}

		// Text rather than markup. Only the line break TinyMCE adds on the end is
		// dropped: trailing spaces are real characters someone has just typed.
		return editor.getContent( { format: 'text' } ).replace( /\u00a0/g, ' ' ).replace( /[\r\n]+$/, '' );
	}

	function update( field, readout ) {
		var used = contentOf( field ).length;

		readout.textContent = format( settings.format || '%1$s of %2$s characters', used, max );
		readout.classList.toggle( 'is-over', used > max );
	}

	// Fields waiting for TinyMCE to turn up, and whether it has been hooked yet.
	var waiting = [];
	var hooked = false;
	var looking = false;

	function bindEditor( entry, editor ) {
		if ( ! editor || editor.id !== entry.field.id || editor.sbskCounterBound ) {
			return;
		}

		editor.sbskCounterBound = true;

		// Covers typing, pasting, undo, and switching between Visual and Code.
		editor.on( 'input keyup change SetContent Undo Redo init', entry.refresh );
		entry.refresh();
	}

	function bindWaiting( editor ) {
		waiting.forEach( function ( entry ) {
			bindEditor( entry, editor || window.tinymce.get( entry.field.id ) );
		} );
	}

	/**
	 * TinyMCE loads separately and may not be there yet, so keep looking for a
	 * while rather than giving up the first time.
	 */
	function findTinymce( attempts ) {
		if ( ! window.tinymce ) {
			if ( attempts > 40 ) {
				looking = false;

				return;
			}

			window.setTimeout( function () {
				findTinymce( attempts + 1 );
			}, 250 );

			return;
		}

		looking = false;

		if ( ! hooked ) {
			hooked = true;

			// An editor built after this point still gets picked up.
			window.tinymce.on( 'AddEditor', function ( event ) {
				bindWaiting( event.editor );
			} );
		}

		bindWaiting();
	}

	/** Follow the rich editor over this field, whenever it arrives. */
	function follow( field, refresh ) {
		if ( ! field.id ) {
			return;
		}

		waiting.push( { field: field, refresh: refresh } );

		if ( window.tinymce ) {
			findTinymce( 0 );

			return;
		}

		if ( ! looking ) {
			looking = true;

			findTinymce( 0 );
		}
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

		follow( field, refresh );
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
