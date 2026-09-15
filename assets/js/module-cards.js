/**
 * Cards that collapse to their title, and a Reorder dialogue to arrange them.
 *
 * Shared by the three SocialBUMP plugins and identical in each. Works on any
 * element carrying data-sb-cards, whose children carry data-sb-card. A card's
 * first child is its head (the title and the switch): a chevron goes at its
 * start, and it is all that shows while the card is collapsed.
 *
 * Reorder opens a list of the cards to drag up and down, with Cancel and Save
 * order. Nothing here touches a form field, so the save button never lights up
 * for it; the arrangement goes to the plugin's own endpoint, per user.
 */
( function () {
	'use strict';

	function post( action, data ) {
		var body = new FormData();

		body.append( 'action', action );

		Object.keys( data ).forEach( function ( key ) {
			if ( Array.isArray( data[ key ] ) ) {
				data[ key ].forEach( function ( value ) {
					body.append( key + '[]', value );
				} );

				return;
			}

			body.append( key, data[ key ] );
		} );

		return window.fetch( window.ajaxurl, { method: 'POST', credentials: 'same-origin', body: body } );
	}

	function el( tag, className, text ) {
		var node = document.createElement( tag );

		if ( className ) {
			node.className = className;
		}

		if ( text ) {
			node.textContent = text;
		}

		return node;
	}

	function setUp( grid ) {
		var key       = grid.getAttribute( 'data-sb-cards' );
		var action    = grid.getAttribute( 'data-sb-cards-action' );
		var nonce     = grid.getAttribute( 'data-sb-cards-nonce' );
		var collapsed = ( grid.getAttribute( 'data-sb-cards-collapsed' ) || '' ).split( ',' ).filter( Boolean );
		// The links sit above the grid and the Reorder button below it, so the
		// controls are looked for across the page rather than in one element.
		var tools     = document;

		function cards() {
			return Array.prototype.slice.call( grid.children ).filter( function ( node ) {
				return node.hasAttribute( 'data-sb-card' );
			} );
		}

		function save() {
			var order = [];
			var shut  = [];

			cards().forEach( function ( card ) {
				var id = card.getAttribute( 'data-sb-card' );

				order.push( id );

				if ( card.classList.contains( 'is-collapsed' ) ) {
					shut.push( id );
				}
			} );

			return post( action, { key: key, nonce: nonce, order: order, collapsed: shut } );
		}

		function setCollapsed( card, shut ) {
			var toggle = card.querySelector( '.sb-card__toggle' );

			card.classList.toggle( 'is-collapsed', shut );

			if ( toggle ) {
				toggle.setAttribute( 'aria-expanded', shut ? 'false' : 'true' );
				toggle.setAttribute( 'aria-label', shut ? 'Expand' : 'Collapse' );
			}
		}

		function title( card ) {
			var heading = card.querySelector( 'h1, h2, h3, h4' );

			return heading ? heading.textContent.trim() : card.getAttribute( 'data-sb-card' );
		}

		/** The Reorder dialogue: a list to drag, then Cancel or Save order. */
		function reorder() {
			var overlay = el( 'div', 'sb-reorder' );
			var box     = el( 'div', 'sb-reorder__box' );
			var list    = el( 'ul', 'sb-reorder__list' );
			var foot    = el( 'div', 'sb-reorder__foot' );
			var cancel  = el( 'button', 'button', 'Cancel' );
			var done    = el( 'button', 'button button-primary', 'Save order' );
			var moving  = null;
			var ghost   = null;
			var grab    = 0;

			box.appendChild( el( 'h2', 'sb-reorder__title', 'Reorder' ) );
			box.appendChild( el( 'p', 'sb-reorder__note', 'Drag the names into the order you want them, on this page, in the menu and in the admin bar.' ) );

			cards().forEach( function ( card ) {
				var item = el( 'li', 'sb-reorder__item', title( card ) );

				item.setAttribute( 'data-sb-id', card.getAttribute( 'data-sb-card' ) );
				item.insertBefore( el( 'span', 'sb-reorder__grip', '\u22EE\u22EE' ), item.firstChild );
				list.appendChild( item );
			} );

			cancel.type = 'button';
			done.type   = 'button';

			foot.appendChild( cancel );
			foot.appendChild( done );
			box.appendChild( list );
			box.appendChild( foot );
			overlay.appendChild( box );
			document.body.appendChild( overlay );

			function close() {
				document.removeEventListener( 'pointermove', onMove );
				document.removeEventListener( 'pointerup', onUp );
				overlay.parentNode.removeChild( overlay );
			}

			/**
			 * Listeners live on the document, not the item, so the drag keeps
			 * going when the pointer leaves the item it started on.
			 */
			function onMove( event ) {
				if ( ! moving ) {
					return;
				}

				event.preventDefault();

				// The copy follows the pointer; the row itself is the placeholder.
				if ( ghost ) {
					ghost.style.top = ( event.clientY - grab ) + 'px';
				}

				var items = Array.prototype.slice.call( list.children );

				for ( var i = 0; i < items.length; i++ ) {
					var other = items[ i ];

					if ( other === moving ) {
						continue;
					}

					var rect = other.getBoundingClientRect();

					if ( event.clientY < rect.top + rect.height / 2 ) {
						if ( other.previousSibling !== moving ) {
							list.insertBefore( moving, other );
						}

						return;
					}
				}

				if ( list.lastChild !== moving ) {
					list.appendChild( moving );
				}
			}

			function onUp() {
				if ( ghost && ghost.parentNode ) {
					ghost.parentNode.removeChild( ghost );
				}

				ghost = null;

				if ( moving ) {
					moving.classList.remove( 'is-moving' );
					moving = null;
				}
			}

			list.addEventListener( 'pointerdown', function ( event ) {
				var item = event.target.closest( '.sb-reorder__item' );

				if ( ! item ) {
					return;
				}

				event.preventDefault();
				moving = item;
				item.classList.add( 'is-moving' );

				var rect = item.getBoundingClientRect();

				grab  = event.clientY - rect.top;
				ghost = item.cloneNode( true );
				ghost.className = 'sb-reorder__item sb-reorder__ghost';
				ghost.style.left  = rect.left + 'px';
				ghost.style.top   = rect.top + 'px';
				ghost.style.width = rect.width + 'px';
				overlay.appendChild( ghost );
			} );

			document.addEventListener( 'pointermove', onMove );
			document.addEventListener( 'pointerup', onUp );

			cancel.addEventListener( 'click', close );

			overlay.addEventListener( 'click', function ( event ) {
				if ( event.target === overlay ) {
					close();
				}
			} );

			done.addEventListener( 'click', function () {
				done.disabled = true;

				// The cards are left where they are: moving them inside the form made
				// the save prompt think the settings had changed. The order goes to
				// the server and the page reloads, which redraws the cards, the menu
				// and the admin bar together.
				var order = Array.prototype.map.call( list.children, function ( item ) {
					return item.getAttribute( 'data-sb-id' );
				} );
				var shut = cards().filter( function ( card ) {
					return card.classList.contains( 'is-collapsed' );
				} ).map( function ( card ) {
					return card.getAttribute( 'data-sb-card' );
				} );

				post( action, { key: key, nonce: nonce, order: order, collapsed: shut } ).then( function () {
					window.location.reload();
				} );
			} );

			cancel.focus();
		}

		cards().forEach( function ( card ) {
			var head   = card.firstElementChild;
			var toggle = el( 'button', 'sb-card__toggle' );

			if ( ! head ) {
				return;
			}

			toggle.type = 'button';
			head.insertBefore( toggle, head.firstChild );

			// The title is a bigger target than the chevron, so it toggles too.
			var heading = head.querySelector( 'h1, h2, h3, h4' );

			if ( heading ) {
				heading.classList.add( 'sb-card__title' );
				heading.addEventListener( 'click', function () {
					setCollapsed( card, ! card.classList.contains( 'is-collapsed' ) );
					save();
				} );
			}

			setCollapsed( card, collapsed.indexOf( card.getAttribute( 'data-sb-card' ) ) !== -1 );

			toggle.addEventListener( 'click', function () {
				setCollapsed( card, ! card.classList.contains( 'is-collapsed' ) );
				save();
			} );
		} );

		if ( tools ) {
			var arrange = tools.querySelector( '[data-sb-cards-tools="' + key + '"] [data-sb-cards-reorder]' );
			var shutAll = tools.querySelector( '[data-sb-cards-tools="' + key + '"] [data-sb-cards-collapse]' );
			var openAll = tools.querySelector( '[data-sb-cards-tools="' + key + '"] [data-sb-cards-expand]' );
			var shutOff = tools.querySelector( '[data-sb-cards-tools="' + key + '"] [data-sb-cards-collapse-off]' );

			/** A card whose switch in the head is off. */
			function disabled( card ) {
				var box = card.firstElementChild ? card.firstElementChild.querySelector( 'input[type="checkbox"]' ) : null;

				return !! ( box && ! box.checked );
			}

			if ( shutOff ) {
				shutOff.addEventListener( 'click', function () {
					cards().filter( disabled ).forEach( function ( card ) {
						setCollapsed( card, true );
					} );
					save();
				} );
			}

			if ( arrange ) {
				arrange.addEventListener( 'click', reorder );
			}

			if ( shutAll ) {
				shutAll.addEventListener( 'click', function () {
					cards().forEach( function ( card ) {
						setCollapsed( card, true );
					} );
					save();
				} );
			}

			if ( openAll ) {
				openAll.addEventListener( 'click', function () {
					cards().forEach( function ( card ) {
						setCollapsed( card, false );
					} );
					save();
				} );
			}
		}
	}

	function start() {
		Array.prototype.forEach.call( document.querySelectorAll( '[data-sb-cards]' ), setUp );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', start );
	} else {
		start();
	}
} )();