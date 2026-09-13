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
				$( this ).closest( '.sbsk-width' ).find( '.sbsk-width__value' ).text( this.value );
			} );
		}

		// Images page: rebuild in batches so a big library cannot time out.
		var $rebuild = $( '#sbsk-rebuild' );

		if ( $rebuild.length ) {
			var $run    = $( '#sbsk-rebuild-run' );
			var $status = $rebuild.find( '.sbsk-rebuild__status' );
			var $bar    = $rebuild.find( '.sbsk-rebuild__bar span' );
			var totals  = { built: 0, removed: 0, files: 0 };

			function batch( offset, total, force ) {
				$.post( ajaxurl, {
					action: 'sbsk_images_batch',
					nonce: $rebuild.data( 'nonce' ),
					offset: offset,
					force: force ? 1 : 0
				} ).done( function ( response ) {
					if ( ! response || ! response.success ) {
						$status.text( 'Something went wrong. Try again.' );
						$run.prop( 'disabled', false );
						return;
					}

					var data = response.data;

					totals.built += data.built;
					totals.removed += data.removed;
					totals.files += data.files;

					var done = Math.min( data.offset, total );
					var pct  = total ? Math.round( ( done / total ) * 100 ) : 100;

					$bar.css( 'width', pct + '%' );
					$status.text( done + ' of ' + total + ' checked. ' + totals.built + ' sizes built, ' + totals.files + ' files removed.' );

					if ( data.done || done >= total ) {
						$status.text( 'Finished. ' + totals.built + ' sizes built, ' + totals.files + ' files removed.' );
						$run.prop( 'disabled', false );
						return;
					}

					batch( data.offset, total, force );
				} ).fail( function () {
					$status.text( 'The server did not answer. Nothing else was changed.' );
					$run.prop( 'disabled', false );
				} );
			}

			$run.on( 'click', function () {
				var force = $( '#sbsk-rebuild-force' ).prop( 'checked' );

				if ( force && ! window.confirm( 'Rebuild every size again? This replaces files that already exist.' ) ) {
					return;
				}

				totals = { built: 0, removed: 0, files: 0 };
				$run.prop( 'disabled', true );
				$status.text( 'Working...' );
				$bar.css( 'width', '0%' );

				$.post( ajaxurl, { action: 'sbsk_images_count', nonce: $rebuild.data( 'nonce' ) } ).done( function ( response ) {
					var total = ( response && response.success ) ? response.data.total : 0;

					if ( ! total ) {
						$status.text( 'No images found.' );
						$run.prop( 'disabled', false );
						return;
					}

					batch( 0, total, force );
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

		$( '.sbsk-card .sbsk-switch input' ).on( 'change', function () {
			$( this ).closest( '.sbsk-card' ).toggleClass( 'is-on', this.checked );
		} );
	} );
} )( jQuery );