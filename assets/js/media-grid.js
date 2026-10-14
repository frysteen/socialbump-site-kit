/**
 * Site Kit: Media Library grid thumbnail size and fit.
 *
 * Adds two selects to the grid toolbar. Size changes the ideal column width
 * WordPress uses to work out how many columns to draw, and asks for a bigger
 * image file. Fit switches between WordPress's square crop and the whole image.
 */
( function ( $, _, wp, cfg ) {
	'use strict';

	if ( ! wp || ! wp.media || ! wp.media.view || ! wp.media.view.AttachmentsBrowser || ! cfg ) {
		return;
	}

	var media  = wp.media;
	var state  = { size: cfg.choice.size, fit: cfg.choice.fit };
	var labels = cfg.labels;

	function isGrid( controller ) {
		return !! ( controller && controller.isModeActive && controller.isModeActive( 'grid' ) );
	}

	function idealWidth() {
		var w = parseInt( cfg.widths[ state.size ], 10 ) || 150;

		// Match WordPress's own small screen width for the standard view.
		if ( 'standard' === state.size && $( window ).width() < 640 ) {
			w = 135;
		}

		return w;
	}

	/**
	 * Which image file to ask for. Standard keeps WordPress's choice (medium).
	 * Bigger tiles need a bigger file or they look soft, so use large, or the
	 * original when the image is small enough that no large copy exists.
	 */
	function pickSize( sizes ) {
		if ( 'standard' === state.size || ! sizes ) {
			return null;
		}

		var hiDpi = ( window.devicePixelRatio || 1 ) > 1.25;

		if ( 'large' === state.size && ! hiDpi && sizes.medium ) {
			return 'medium';
		}

		if ( sizes.large ) {
			return 'large';
		}

		if ( sizes.full && sizes.full.width && sizes.full.width <= 2560 ) {
			return 'full';
		}

		return sizes.medium ? 'medium' : null;
	}

	// Ask for the bigger file on grid tiles.
	var origImageSize = media.view.Attachment.prototype.imageSize;

	media.view.Attachment.prototype.imageSize = function ( size ) {
		if ( ! size && isGrid( this.controller ) && this.model ) {
			var want = pickSize( this.model.get( 'sizes' ) );

			if ( want ) {
				size = want;
			}
		}

		return origImageSize.call( this, size );
	};

	// The two selects.
	var Picker = media.View.extend( {
		tagName: 'div',
		className: 'sbsk-grid-picker',

		events: {
			'change select': 'changed'
		},

		render: function () {
			var html = '';

			html += '<label for="sbsk-grid-size" class="screen-reader-text">' + _.escape( labels.size ) + '</label>';
			html += '<select id="sbsk-grid-size" class="attachment-filters sbsk-grid-size" data-key="size">';
			_.each( [ 'standard', 'large', 'xlarge' ], function ( key ) {
				html += '<option value="' + key + '"' + ( key === state.size ? ' selected' : '' ) + '>' + _.escape( labels[ key ] ) + '</option>';
			} );
			html += '</select>';

			html += '<label for="sbsk-grid-fit" class="screen-reader-text">' + _.escape( labels.fit ) + '</label>';
			html += '<select id="sbsk-grid-fit" class="attachment-filters sbsk-grid-fit" data-key="fit">';
			_.each( [ 'crop', 'contain' ], function ( key ) {
				html += '<option value="' + key + '"' + ( key === state.fit ? ' selected' : '' ) + '>' + _.escape( labels[ key ] ) + '</option>';
			} );
			html += '</select>';

			this.$el.html( html );

			return this;
		},

		changed: function ( event ) {
			var $select = $( event.currentTarget );
			var key     = $select.data( 'key' );
			var value   = $select.val();

			if ( state[ key ] === value ) {
				return;
			}

			state[ key ] = value;

			applyClasses();

			if ( 'size' === key ) {
				applyColumns( this.options.browser );
				redraw( this.options.browser );
			}

			$.post( cfg.ajaxurl, {
				action: cfg.action,
				nonce: cfg.nonce,
				size: state.size,
				fit: state.fit
			} );
		}
	} );

	function applyClasses() {
		var $body = $( document.body );

		$body.removeClass( function ( i, cls ) {
			return ( cls.match( /(^|\s)sbsk-grid-(size|fit)-\S+/g ) || [] ).join( ' ' );
		} );

		$body.addClass( 'sbsk-grid-size-' + state.size + ' sbsk-grid-fit-' + state.fit );
	}

	function applyColumns( browser ) {
		if ( ! browser ) {
			return;
		}

		browser.options.idealColumnWidth = idealWidth();

		if ( browser.attachments ) {
			browser.attachments.options.idealColumnWidth = idealWidth();
			browser.attachments.setColumns();
		}
	}

	function redraw( browser ) {
		if ( ! browser || ! browser.attachments || ! browser.attachments._viewsByCid ) {
			return;
		}

		_.each( browser.attachments._viewsByCid, function ( view ) {
			if ( view && view.render ) {
				view.render();
			}
		} );
	}

	function addPicker( browser ) {
		if ( ! browser.toolbar || browser.toolbar.get( 'sbskGridPicker' ) ) {
			return;
		}

		browser.toolbar.set( 'sbskGridPicker', new Picker( {
			controller: browser.controller,
			browser: browser,
			priority: -60
		} ).render() );
	}

	// Hook into every grid browser as it's built. createToolbar runs before
	// createAttachments, so the column width is in place for the first draw.
	var origCreateToolbar = media.view.AttachmentsBrowser.prototype.createToolbar;

	media.view.AttachmentsBrowser.prototype.createToolbar = function () {
		var result = origCreateToolbar.apply( this, arguments );

		if ( isGrid( this.controller ) ) {
			this.options.idealColumnWidth = idealWidth();
			addPicker( this );
		}

		return result;
	};

	// Keep the small screen rule for Standard working when the window resizes.
	$( window ).on( 'resize.sbsk-media-grid', _.debounce( function () {
		var frame = media.frames && media.frames.browse;

		if ( frame && frame.browserView && frame.browserView.attachments ) {
			frame.browserView.attachments.options.idealColumnWidth = idealWidth();
		}
	}, 40 ) );

	// In case the grid was already built before this file ran.
	$( function () {
		var frame = media.frames && media.frames.browse;

		if ( frame && frame.browserView && isGrid( frame ) ) {
			addPicker( frame.browserView );
			applyColumns( frame.browserView );
			if ( 'standard' !== state.size ) {
				redraw( frame.browserView );
			}
		}

		applyClasses();
	} );
}( jQuery, window._, window.wp, window.SBSKMediaGrid ) );