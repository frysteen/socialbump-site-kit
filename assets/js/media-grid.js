/**
 * Site Kit: Media Library grid thumbnail size and fit.
 *
 * Adds two selects to the grid toolbar, on Media > Library and in the media
 * pop-up wherever it opens. Size changes the ideal column width WordPress
 * uses to work out how many columns to draw, and asks for a bigger image
 * file. Fit switches between WordPress's square crop and the whole image.
 * The choice is saved per person, so the pop-up follows the Media Library.
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

	/**
	 * The Media Library grid, or any media pop-up. A pop-up frame carries its
	 * modal; the Media Library's own frame is built without one.
	 */
	function wanted( controller ) {
		return isGrid( controller ) || !! ( controller && controller.modal );
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

	/**
	 * Ask for the bigger file on grid tiles. Not on the small thumbnails in a
	 * pop-up's selection bar, which stay tiny whatever is chosen.
	 */
	var origImageSize = media.view.Attachment.prototype.imageSize;
	var Selection     = media.view.Attachment.Selection;

	media.view.Attachment.prototype.imageSize = function ( size ) {
		if ( ! size && this.model && wanted( this.controller ) && ! ( Selection && this instanceof Selection ) ) {
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

			// A page can build more than one pop-up, so each picker's ids are its own.
			var sizeId = 'sbsk-grid-size-' + this.cid;
			var fitId  = 'sbsk-grid-fit-' + this.cid;

			html += '<label for="' + sizeId + '" class="screen-reader-text">' + _.escape( labels.size ) + '</label>';
			html += '<select id="' + sizeId + '" class="attachment-filters sbsk-grid-size" data-key="size">';
			_.each( [ 'standard', 'large', 'xlarge' ], function ( key ) {
				html += '<option value="' + key + '"' + ( key === state.size ? ' selected' : '' ) + '>' + _.escape( labels[ key ] ) + '</option>';
			} );
			html += '</select>';

			html += '<label for="' + fitId + '" class="screen-reader-text">' + _.escape( labels.fit ) + '</label>';
			html += '<select id="' + fitId + '" class="attachment-filters sbsk-grid-fit" data-key="fit">';
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

	/**
	 * HappyFiles folders as a dropdown, only in pop-ups opened from a post list
	 * screen, where HappyFiles leaves out its own folder panel. The server only
	 * sends the folder list on those screens. Choosing a folder sets the
	 * sbsk_folder prop, which the library sends with its query.
	 */
	var FolderFilter = media.View.extend( {
		tagName: 'div',
		className: 'sbsk-folder-filter',

		events: {
			'change select': 'changed'
		},

		initialize: function () {
			this.listenTo( this.model, 'change:sbsk_folder', this.select );
		},

		render: function () {
			var id   = 'sbsk-folder-' + this.cid;
			var html = '<label for="' + id + '" class="sbsk-folder-filter__label">' + _.escape( labels.folder ) + '</label>';

			html += '<select id="' + id + '" class="attachment-filters sbsk-folder-filter__select">';
			html += '<option value="">' + _.escape( labels.allFolders ) + '</option>';
			html += '<option value="-1">' + _.escape( labels.uncategorised ) + '</option>';

			_.each( cfg.folders, function ( folder ) {
				// Non-breaking spaces show the nesting inside a plain select.
				var indent = new Array( ( folder.depth * 3 ) + 1 ).join( ' ' );

				html += '<option value="' + parseInt( folder.id, 10 ) + '">' + indent + _.escape( folder.name ) + '</option>';
			} );

			html += '</select>';

			this.$el.html( html );
			this.select();

			return this;
		},

		changed: function ( event ) {
			var value = $( event.currentTarget ).val();

			this.model.set( 'sbsk_folder', '' === value ? null : parseInt( value, 10 ) );
		},

		select: function () {
			var value = this.model.get( 'sbsk_folder' );

			this.$( 'select' ).val( null === value || undefined === value ? '' : String( value ) );
		}
	} );

	function addFolders( browser ) {
		if ( ! cfg.folders || ! browser.toolbar || ! browser.collection || browser.toolbar.get( 'sbskFolderFilter' ) ) {
			return;
		}

		browser.toolbar.set( 'sbskFolderFilter', new FolderFilter( {
			controller: browser.controller,
			model: browser.collection.props,
			priority: -70
		} ).render() );
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

	// Hook into every grid browser as it's built, the Media Library's and each
	// pop-up's. createToolbar runs before createAttachments, so the column
	// width is in place for the first draw.
	var origCreateToolbar = media.view.AttachmentsBrowser.prototype.createToolbar;

	media.view.AttachmentsBrowser.prototype.createToolbar = function () {
		var result = origCreateToolbar.apply( this, arguments );

		if ( wanted( this.controller ) ) {
			this.options.idealColumnWidth = idealWidth();

			if ( this.controller.modal ) {
				addFolders( this );
			}

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

	// In case the Media Library grid was already built before this file ran.
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
