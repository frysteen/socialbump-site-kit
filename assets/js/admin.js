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
					$build.prop( 'disabled', lastMissing < 1 && ! $( '#sbsk-rebuild-force' ).prop( 'checked' ) );
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
					$build.prop( 'hidden', false ).prop( 'disabled', data.missing < 1 && ! $( '#sbsk-rebuild-force' ).prop( 'checked' ) );
					$clean.prop( 'hidden', data.stale < 1 );
					lastOrphans = ( typeof data.deletable === 'number' ) ? data.deletable : data.orphans;
					$orphan.prop( 'hidden', lastOrphans < 1 );
					$force.prop( 'hidden', false );
					$scan.text( 'Scan again' );
				} );
			}

			function batch( offset, total, force, mode ) {
				$.post( ajaxurl, { action: 'sbsk_images_batch', nonce: nonce, offset: offset, force: force ? 1 : 0, mode: mode } ).done( function ( response ) {
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

					if ( data.last && data.last.thumb ) {
						$( '#sbsk-progress-thumb' ).html( '<img src="' + data.last.thumb + '" alt="">' );
						$status.text( done + ' of ' + total + ' checked. ' + data.last.name );
					}

					if ( data.done || done >= total ) {
						$status.text( 'Finished. ' + totals.built + ' sizes built, ' + totals.files + ' files removed.' );
						scan( true );
						return;
					}

					batch( data.offset, total, force, mode );
				} ).fail( function () {
					$status.text( 'The server did not answer. Nothing else was changed.' );
					buttons( true );
				} );
			}

			function start( mode, force ) {
				totals = { built: 0, removed: 0, files: 0 };

				buttons( false );
				$progress.prop( 'hidden', false );
				$bar.css( 'width', '0%' );
				$status.text( 'Working...' );
				$( '#sbsk-progress-thumb' ).empty();

				$.post( ajaxurl, { action: 'sbsk_images_count', nonce: nonce } ).done( function ( response ) {
					var total = ( response && response.success ) ? response.data.total : 0;

					if ( ! total ) {
						$status.text( 'No images found.' );
						buttons( true );
						return;
					}

					batch( 0, total, force, mode );
				} );
			}

			$( '#sbsk-rebuild-force' ).on( 'change', function () {
				$build.prop( 'disabled', ! this.checked && lastMissing < 1 );
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

				if ( force && ! window.confirm( 'Build every size again? This replaces files that already exist.' ) ) {
					return;
				}

				start( 'build', force );
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

					var seen = Math.min( cleared + data.offset, data.total );
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
					$status.text( 'The server did not answer. Nothing else was changed.' );
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
				$bar.css( 'width', '0%' );
				$status.text( 'Clearing...' );

				clearOrphans( 0, 0, 0 );
			} );
			} );
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
		var $rebuildOn = $( '#sbsk-rebuild-on' );

		if ( $rebuildOn.length ) {
			$rebuildOn.on( 'change', function () {
				$( '#sbsk-rebuild-body' ).prop( 'hidden', ! this.checked );
			} );
		}

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