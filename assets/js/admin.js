/**
 * SB Site Kit settings screen.
 *
 * Colour fields: the swatch shows whatever the text box resolves to on this
 * page, including var(--name). Picking with the swatch writes a hex back.
 * Card settings show or hide as the switch is flipped.
 */
( function ( $ ) {
	/**
	 * Resolve any CSS colour (hex, rgb, hsl, var) to a hex, or null if it can't be.
	 * A variable that isn't defined here inherits the sentinel colour, so it's caught.
	 */
	function resolveHex( value ) {
		if ( ! value ) {
			return null;
		}

		var holder = document.createElement( 'span' );
		var probe  = document.createElement( 'span' );

		holder.style.color   = 'rgb(1, 2, 3)';
		holder.style.display = 'none';
		holder.appendChild( probe );
		document.body.appendChild( holder );

		probe.style.color = value;

		var accepted = probe.style.color !== '';
		var computed = window.getComputedStyle( probe ).color;

		holder.parentNode.removeChild( holder );

		var m = accepted ? computed.match( /rgba?\(\s*(\d+)[,\s]+(\d+)[,\s]+(\d+)/ ) : null;

		if ( ! m || ( m[1] === '1' && m[2] === '2' && m[3] === '3' ) ) {
			return null;
		}

		return '#' + [ m[1], m[2], m[3] ].map( function ( n ) {
			return ( '0' + parseInt( n, 10 ).toString( 16 ) ).slice( -2 );
		} ).join( '' );
	}

	$( function () {
		$( '.sbsk-colour' ).each( function () {
			var $swatch = $( this ).find( '.sbsk-colour__swatch' );
			var $value  = $( this ).find( '.sbsk-colour__value' );

			var sync = function () {
				var hex = resolveHex( $.trim( $value.val() ) );

				if ( hex ) {
					$swatch.val( hex );
				}
			};

			$swatch.on( 'input change', function () {
				$value.val( this.value );
			} );

			$value.on( 'input', sync );
			sync();
		} );

		// Fields that hide while another checkbox is ticked.
		$( '[data-sbsk-hide-when]' ).each( function () {
			var $field = $( this );
			var $box   = $( '#' + $field.data( 'sbsk-hide-when' ) );

			if ( ! $box.length ) {
				return;
			}

			var sync = function () {
				$field.toggle( ! $box.prop( 'checked' ) );
			};

			$box.on( 'change', sync );
			sync();
		} );

		// Images page: the list of widths.
		var $widths = $( '#sbsk-widths' );

		if ( $widths.length ) {
			$( '#sbsk-add-width' ).on( 'click', function () {
				var $row = $widths.find( '.sbsk-width' ).last().clone();

				if ( ! $row.length ) {
					return;
				}

				$row.find( 'input' ).val( '' );
				$row.find( '.sbsk-width__value' ).text( '' );
				$widths.append( $row );
				$row.find( 'input' ).trigger( 'focus' );
			} );

			$widths.on( 'click', '.sbsk-width__remove', function () {
				$( this ).closest( '.sbsk-width' ).remove();
			} );

			$widths.on( 'input', 'input[type="number"]', function () {
				var $row  = $( this ).closest( '.sbsk-width' );
				var value = this.value;

				$row.find( '.sbsk-width__value' ).text( value );

				// Flag a width that is already in the list rather than silently merging it.
				var seen = 0;

				$widths.find( 'input[type="number"]' ).each( function () {
					if ( this.value !== '' && this.value === value ) {
						seen++;
					}
				} );

				$row.toggleClass( 'is-duplicate', seen > 1 );
				$row.find( '.sbsk-width__dupe' ).remove();

				if ( seen > 1 ) {
					$row.find( '.sbsk-width__name' ).after( '<span class="sbsk-width__dupe">already in the list</span>' );
				}
			} );
		}

		// Images page: scan first, then offer only the jobs that are worth doing.
		var $rebuild = $( '#sbsk-rebuild' );

		if ( $rebuild.length ) {
			var $scan   = $( '#sbsk-scan' );
			var $build  = $( '#sbsk-rebuild-run' );
			var $clean  = $( '#sbsk-rebuild-clean' );
			var $orphan = $( '#sbsk-orphans-run' );
			var $force  = $( '#sbsk-force-wrap' );
			var $panel  = $( '#sbsk-report' );
			var $status = $rebuild.find( '.sbsk-rebuild__status' );
			var $progress = $( '#sbsk-progress' );
			var $barWrap  = $( '#sbsk-rebuild-bar' );
			var $bar    = $barWrap.find( 'span' );
			var totals  = { built: 0, removed: 0, files: 0 };
			var lastMissing = 0;
			var lastOrphans = 0;
			var nonce   = $rebuild.data( 'nonce' );

			function buttons( on ) {
				$rebuild.find( 'button' ).prop( 'disabled', ! on );

				if ( on ) {
					refreshBuild();
				}
			}

			function scan( quiet ) {
				buttons( false );

				if ( ! quiet ) {
					$panel.prop( 'hidden', false ).html( '<p>Looking through the library...</p>' );
				}

				return $.post( ajaxurl, { action: 'sbsk_images_report', nonce: nonce } ).done( function ( response ) {
					buttons( true );

					if ( ! response || ! response.success ) {
						$panel.html( '<p>The scan could not be run.</p>' );
						return;
					}

					var data = response.data;

					$panel.prop( 'hidden', false ).html( data.html );
					lastMissing = data.missing;
					$build.prop( 'hidden', false );
					refreshBuild();
					$clean.prop( 'hidden', data.stale < 1 );
					lastOrphans = ( typeof data.deletable === 'number' ) ? data.deletable : data.orphans;
					$orphan.prop( 'hidden', lastOrphans < 1 );
					$force.prop( 'hidden', false );
					$scan.text( 'Scan again' );
				} );
			}

			var retried = false;

			function batch( offset, total, force, mode, sizes ) {
				$.post( ajaxurl, { action: 'sbsk_images_batch', nonce: nonce, offset: offset, force: force ? 1 : 0, mode: mode, sizes: sizes || [], batch: ( retried || force ) ? 1 : 5 } ).done( function ( response ) {
					if ( ! response || ! response.success ) {
						$status.text( 'Something went wrong. Try again.' );
						buttons( true );
						return;
					}

					var data = response.data;

					totals.built += data.built;
					totals.files += data.files;

					var done = Math.min( data.offset, total );

					$bar.css( 'width', ( total ? Math.round( ( done / total ) * 100 ) : 100 ) + '%' );
					$status.text( done + ' of ' + total + ' checked.' );

					if ( data.items && data.items.length ) {
						var $log = $( '#sbsk-progress-log' );

						/**
						 * One image to a row, with its own thumbnail and the sizes built for
						 * it underneath. A batch does several images at once, so a single
						 * thumbnail beside a list of names never matched what you were
						 * reading.
						 */
						data.items.forEach( function ( item ) {
							var sizes = ( item.sizes || [] ).map( function ( name ) {
								return '<li><span class="sbsk-tick">&#10003;</span>' + name + '</li>';
							} ).join( '' );

							var thumb = item.thumb ? '<img class="sbsk-log__thumb" src="' + item.thumb + '" alt="">' : '<span class="sbsk-log__thumb is-empty"></span>';

							$log.prepend( '<li class="sbsk-log__row">' + thumb + '<div class="sbsk-log__detail"><strong>' + item.name + '</strong><ul>' + sizes + '</ul></div></li>' );
						} );

						$log.find( 'li.sbsk-log__row:gt( 20 )' ).remove();
					}

					if ( data.last && data.last.name ) {
						$status.text( done + ' of ' + total + ' checked. ' + data.last.name );
					}

					if ( data.done || done >= total ) {
						$status.text( 'Finished. ' + totals.built + ' sizes built, ' + totals.files + ' files removed.' );

						// A forced run is a deliberate act, so it does not stay armed.
						if ( force ) {
							$( '#sbsk-rebuild-force' ).prop( 'checked', false );
						}

						scan( true );
						return;
					}

					batch( data.offset, total, force, mode, sizes );
				} ).fail( function () {
					/**
					 * One request failing is usually the host, not the work.
					 *
					 * Building several sizes from a large original can run long enough to
					 * be cut off. Nothing is lost when that happens, because each request
					 * starts where the last one finished, so the same batch is tried again
					 * one image at a time. Only a second failure is worth reporting.
					 */
					if ( ! retried ) {
						retried = true;

						$status.text( 'That took too long. Trying again, one at a time.' );
						batch( offset, total, force, mode, sizes );

						return;
					}

					$status.text( 'The server did not answer. Anything already built has been kept, so you can start again from here.' );
					buttons( true );
				} );
			}

			function start( mode, force, sizes ) {
				totals = { built: 0, removed: 0, files: 0 };

				buttons( false );
				$progress.prop( 'hidden', false );
				$bar.css( 'width', '0%' );
				$status.text( 'Working...' );
				$( '#sbsk-progress-thumb' ).empty();
				$( '#sbsk-progress-log' ).empty();

				$.post( ajaxurl, { action: 'sbsk_images_count', nonce: nonce } ).done( function ( response ) {
					var total = ( response && response.success ) ? response.data.total : 0;

					if ( ! total ) {
						$status.text( 'No images found.' );
						buttons( true );
						return;
					}

					batch( 0, total, force, mode, sizes );
				} );
			}

			function refreshBuild() {
				var force = $( '#sbsk-rebuild-force' ).prop( 'checked' );

				// A forced run remakes the saved sizes; a plain build needs something missing.
				$build.prop( 'disabled', force ? savedCount < 1 : lastMissing < 1 );
			}

			$( '#sbsk-rebuild-force' ).on( 'change', refreshBuild );

			/**
			 * The size list on the left is a plain form, so save-state.js handles the
			 * button, the reminder and the warning on leaving. These only tick the
			 * boxes, and fire change so it notices.
			 */
			var $sizes     = $( '#sbsk-cleaner-sizes' );
			var savedCount = $sizes.find( '.sbsk-size-choice:checked' ).length;

			/**
			 * A real DOM event, not jQuery's: save-state.js listens with
			 * addEventListener, and a jQuery trigger never reaches it, so the save
			 * button sat disabled while the ticks plainly changed.
			 */
			function ticked( $boxes, on ) {
				$boxes.prop( 'checked', on );

				if ( $boxes.length ) {
					$boxes[0].dispatchEvent( new Event( 'change', { bubbles: true } ) );
				}
			}

			$sizes.on( 'click', '[data-sizes-all], [data-sizes-none]', function () {
				ticked( $( this ).closest( '.sbsk-sizegroup' ).find( '.sbsk-size-choice' ), this.hasAttribute( 'data-sizes-all' ) );
			} );

			$( '#sbsk-sizes-all' ).on( 'click', function () {
				ticked( $sizes.find( '.sbsk-size-choice' ), true );
			} );

			$( '#sbsk-sizes-none' ).on( 'click', function () {
				ticked( $sizes.find( '.sbsk-size-choice' ), false );
			} );

			$panel.on( 'click', '#sbsk-show-all', function () {
				$panel.find( 'tr.is-extra' ).removeClass( 'is-extra' );
				$( this ).closest( 'tr' ).remove();
			} );

			$panel.on( 'click', '.sbsk-report__keep', function () {
				var $button = $( this );

				$button.prop( 'disabled', true );

				$.post( ajaxurl, { action: 'sbsk_images_keep', nonce: nonce, file: $button.data( 'file' ), keep: $button.data( 'keep' ) } ).done( function () {
					scan( true );
				} );
			} );

			$panel.on( 'click', '.sbsk-report__delete', function () {
				var $button = $( this );
				var file    = $button.data( 'file' );

				if ( ! window.confirm( 'Delete ' + file + '? This cannot be undone.' ) ) {
					return;
				}

				$button.prop( 'disabled', true ).text( 'Deleting...' );

				$.post( ajaxurl, { action: 'sbsk_images_orphan_one', nonce: nonce, file: file } ).done( function ( response ) {
					if ( response && response.success ) {
						$button.closest( 'tr' ).remove();
						scan( true );
						return;
					}

					$button.prop( 'disabled', false ).text( 'Delete' );
				} );
			} );

			$( '#sbsk-progress-close' ).on( 'click', function () {
				$progress.prop( 'hidden', true );
			} );

			$scan.on( 'click', function () {
				$progress.prop( 'hidden', true );
				scan( false );
			} );

			$build.on( 'click', function () {
				var force = $( '#sbsk-rebuild-force' ).prop( 'checked' );

				if ( force && ! window.confirm( 'Rebuild the ' + savedCount + ' saved size' + ( savedCount === 1 ? '' : 's' ) + ' on every image? This replaces files that already exist.' ) ) {
					return;
				}

				start( 'build', force, [] );
			} );

			$clean.on( 'click', function () {
				if ( ! window.confirm( 'Remove the thumbnails left over from sizes you have taken off the list?' ) ) {
					return;
				}

				start( 'clean', false );
			} );

			function clearOrphans( offset, cleared, freed ) {
				$.post( ajaxurl, { action: 'sbsk_images_orphans', nonce: nonce, offset: offset } ).done( function ( response ) {
					if ( ! response || ! response.success ) {
						$status.text( 'Something went wrong. Nothing else was changed.' );
						buttons( true );
						return;
					}

					var data = response.data;

					cleared += data.removed;
					freed += data.bytes;

					var seen = Math.min( data.offset, data.total );
					var pct  = data.total ? Math.round( ( seen / data.total ) * 100 ) : 100;

					$bar.css( 'width', pct + '%' );
					$status.text( cleared + ' of ' + data.total + ' removed.' );

					if ( data.done ) {
						$bar.css( 'width', '100%' );
						$status.text( 'Finished. ' + cleared + ' files removed, ' + Math.round( freed / 1048576 * 10 ) / 10 + ' MB freed.' );
						scan( true );
						return;
					}

					clearOrphans( data.offset, cleared, freed );
				} ).fail( function () {
					$status.text( 'The server did not answer. Nothing else was changed, and what was already removed has gone.' );
					buttons( true );
				} );
			}

			$orphan.on( 'click', function () {
				if ( ! window.confirm( 'Confirm you want to delete ' + lastOrphans + ' orphaned image' + ( lastOrphans === 1 ? '' : 's' ) + '. This cannot be undone.' ) ) {
					return;
				}

				buttons( false );
				$progress.prop( 'hidden', false );
				$( '#sbsk-progress-thumb' ).empty();
				$( '#sbsk-progress-log' ).empty();
				$bar.css( 'width', '0%' );
				$status.text( 'Clearing...' );

				clearOrphans( 0, 0, 0 );
			} );
		}

		/**
		 * Attachment details: the sizes list is fetched when its pane is on screen.
		 * The media modal builds panes as you click through, so the page is
		 * watched for new ones and each is filled once, the first time it shows.
		 */
		function fillPanels() {
			$( '.sbsk-attachment-sizes[data-lazy]:visible' ).each( function () {
				var $panel = $( this );

				$panel.removeAttr( 'data-lazy' );

				$.post( ajaxurl, { action: 'sbsk_images_panel', nonce: $panel.data( 'nonce' ), id: $panel.data( 'id' ) } ).done( function ( response ) {
					if ( response && response.success ) {
						$panel.find( '.sbsk-sizes' ).replaceWith( response.data.html );
					}
				} );
			} );
		}

		if ( $( '.sbsk-attachment-sizes' ).length || $( 'body' ).hasClass( 'upload-php' ) || $( 'body' ).hasClass( 'post-php' ) || $( 'body' ).hasClass( 'post-new-php' ) ) {
			var panelTimer = null;
			var watcher    = new MutationObserver( function () {
				window.clearTimeout( panelTimer );
				panelTimer = window.setTimeout( fillPanels, 150 );
			} );

			watcher.observe( document.body, { childList: true, subtree: true } );
			fillPanels();
		}

		// Attachment details: rebuild one image.
		$( document ).on( 'click', '.sbsk-regenerate', function () {
			var $button = $( this );
			var $panel  = $button.closest( '.sbsk-attachment-sizes' );

			$button.prop( 'disabled', true ).text( 'Working...' );

			$.post( ajaxurl, {
				action: 'sbsk_images_single',
				nonce: $panel.data( 'nonce' ),
				id: $panel.data( 'id' ),
				force: 1
			} ).done( function ( response ) {
				if ( response && response.success ) {
					$panel.find( '.sbsk-sizes' ).replaceWith( response.data.html );
				}

				$button.prop( 'disabled', false ).text( 'Regenerate sizes' );
			} ).fail( function () {
				$button.prop( 'disabled', false ).text( 'Regenerate sizes' );
			} );
		} );

		// Images page: the sizes list follows its switch, and reset asks first.
		var $sizesOn = $( '#sbsk-sizes-on' );

		if ( $sizesOn.length ) {
			$sizesOn.on( 'change', function () {
				$( '#sbsk-sizes-body' ).prop( 'hidden', ! this.checked );
			} );
		}

		$( '#sbsk-reset-widths' ).on( 'click', function ( e ) {
			if ( ! window.confirm( 'Put the standard image sizes back? Any widths you have added or changed will be lost.' ) ) {
				e.preventDefault();
			}
		} );

		$( '.sbsk-card .sbsk-switch input' ).on( 'change', function () {
			$( this ).closest( '.sbsk-card' ).toggleClass( 'is-on', this.checked );
		} );
	} );
} )( jQuery );