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
				// Not the progress box's own controls: Cancel has to stay clickable while
				// a run is going, which is the only time it is on screen.
				$rebuild.find( 'button' ).not( '#sbsk-progress-cancel, #sbsk-progress-close' ).prop( 'disabled', ! on );

				if ( on ) {
					refreshBuild();
				}
			}

			function scan( quiet ) {
				buttons( false );

				if ( ! quiet ) {
					$panel.prop( 'hidden', false ).html( '<p>Looking through the library...</p>' );

					// Asking for a normal scan means the leftovers list is last question's
					// answer. A quiet scan after a deletion keeps it, because that run
					// redraws it with what is left.
					$( '#sbsk-deep-panel' ).prop( 'hidden', true ).empty();
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

			/**
			 * How long the run has been going, and how long each image is taking.
			 * The average is the useful half: it says whether a big library will
			 * take two minutes or twenty.
			 */
			var $timer  = $( '#sbsk-progress-timer' );
			var $cancel = $( '#sbsk-progress-cancel' );
			var $close  = $( '#sbsk-progress-close' );
			var started = 0;
			var ticking = null;
			var seenNow = 0;
			var stopped = false;

			function spell( seconds ) {
				if ( seconds < 60 ) {
					return seconds + 's';
				}

				return Math.floor( seconds / 60 ) + 'm ' + ( seconds % 60 ) + 's';
			}

			function elapsed() {
				return Math.max( 0, Math.round( ( Date.now() - started ) / 1000 ) );
			}

			function paintTimer() {
				var seconds = elapsed();
				var text    = spell( seconds );

				if ( seenNow > 0 && seconds > 0 ) {
					text += ' | ' + ( Math.round( ( seconds / seenNow ) * 100 ) / 100 ) + 's per image';
				}

				$timer.text( text );
			}

			function startClock() {
				stopped = false;
				started = Date.now();
				seenNow = 0;

				window.clearInterval( ticking );
				paintTimer();
				ticking = window.setInterval( paintTimer, 1000 );

				$cancel.prop( 'hidden', false );
				$close.prop( 'hidden', true );
			}

			/** Called however a run ends: finished, cancelled or failed. */
			function stopClock() {
				window.clearInterval( ticking );
				ticking = null;
				paintTimer();
				$cancel.prop( 'hidden', true );
				$close.prop( 'hidden', false );
			}

			$cancel.on( 'click', function () {
				// The batch in flight finishes; nothing after it is asked for.
				stopped = true;
				$cancel.prop( 'disabled', true );
				$status.text( 'Stopping after this batch...' );
			} );

			function finished( text ) {
				stopClock();
				$status.text( text + ' Took ' + spell( elapsed() ) + ( seenNow > 0 ? ', ' + ( Math.round( ( elapsed() / seenNow ) * 100 ) / 100 ) + 's per image.' : '.' ) );
				$cancel.prop( 'disabled', false );
			}

			function batch( offset, total, force, mode, sizes ) {
				$.post( ajaxurl, { action: 'sbsk_images_batch', nonce: nonce, offset: offset, force: force ? 1 : 0, mode: mode, sizes: sizes || [], batch: ( retried || force ) ? 1 : 5 } ).done( function ( response ) {
					if ( ! response || ! response.success ) {
						stopClock();
						$status.text( 'Something went wrong. Try again.' );
						buttons( true );
						return;
					}

					var data = response.data;

					totals.built += data.built;
					totals.files += data.files;

					// The server knows how many the run really covers; a plain build
					// skips the images that need nothing.
					if ( data.total ) {
						total = data.total;
					}

					var done = Math.min( data.offset, total );

					seenNow = done;
					paintTimer();

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
							// Made and skipped in one list, smallest size first.
							var sizes = ( item.rows || [] ).map( function ( row ) {
								if ( row.made ) {
									return '<li><span class="sbsk-tick">&#10003;</span>' + row.name + '</li>';
								}

								return '<li class="is-skipped"><span class="sbsk-tick sbsk-tick--skip">&#10005;</span>' + row.name + ' <em>' + row.why + '</em></li>';
							} ).join( '' );

							var thumb = item.thumb ? '<img class="sbsk-log__thumb" src="' + item.thumb + '" alt="">' : '<span class="sbsk-log__thumb is-empty"></span>';
							var dims  = item.dims ? '<span class="sbsk-log__dims">' + item.dims + '</span>' : '';

							$log.prepend( '<li class="sbsk-log__row">' + thumb + '<div class="sbsk-log__detail"><strong>' + item.name + '</strong>' + dims + '<ul>' + sizes + '</ul></div></li>' );
						} );

						// The whole run stays in the list; the box scrolls.
					}

					if ( data.last && data.last.name ) {
						$status.text( done + ' of ' + total + ' checked. ' + data.last.name );
					}

					if ( data.done || done >= total || stopped ) {
						finished( ( stopped && ! data.done && done < total ? 'Stopped. ' : 'Finished. ' ) + totals.built + ' sizes built, ' + totals.files + ' files removed.' );

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

					stopClock();
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
				$( '#sbsk-progress-log' ).empty();
				startClock();

				$.post( ajaxurl, { action: 'sbsk_images_count', nonce: nonce } ).done( function ( response ) {
					var total = ( response && response.success ) ? response.data.total : 0;

					if ( ! total ) {
						stopClock();
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

			/**
			 * Deep scan: the files on disk whose dimensions no registered size
			 * would produce. Its own button, its own panel, and nothing is
			 * deleted without ticking a set of dimensions and confirming.
			 */
			var $deep = $( '#sbsk-deep-panel' );

			function deepButtons() {
				$deep.find( '#sbsk-deep-remove' ).prop( 'disabled', $deep.find( '.sbsk-deep-choice:checked' ).length < 1 );
			}

			$( '#sbsk-deep' ).on( 'click', function () {
				var $button = $( this );

				$button.prop( 'disabled', true );
				$deep.prop( 'hidden', false ).html( '<p class="sbsk-deep__working">Looking through the uploads folder...</p>' );

				$.post( ajaxurl, { action: 'sbsk_images_deep', nonce: nonce } ).done( function ( response ) {
					$button.prop( 'disabled', false );

					if ( response && response.success ) {
						$deep.html( response.data.html );
						deepButtons();

						return;
					}

					$deep.html( '<p class="sbsk-deep__working">Something went wrong. Nothing was changed.</p>' );
				} ).fail( function () {
					$button.prop( 'disabled', false );
					$deep.html( '<p class="sbsk-deep__working">The server did not answer. Nothing was changed.</p>' );
				} );
			} );

			$deep.on( 'click', '.sbsk-deep__close', function () {
				$deep.prop( 'hidden', true ).empty();
			} );

			$deep.on( 'change', '.sbsk-deep-choice', deepButtons );

			$deep.on( 'click', '.sbsk-deep-show', function () {
				var $files = $( this ).closest( 'li' ).find( '.sbsk-deep__files' );
				var shut   = $files.prop( 'hidden' );

				$files.prop( 'hidden', ! shut );
				$( this ).text( shut ? 'Hide files' : 'Show files' );
			} );

			$deep.on( 'click', '#sbsk-deep-all, #sbsk-deep-none', function () {
				$deep.find( '.sbsk-deep-choice' ).prop( 'checked', this.id === 'sbsk-deep-all' );
				deepButtons();
			} );

			$deep.on( 'click', '#sbsk-deep-remove', function () {
				var dims  = [];
				var files = 0;

				$deep.find( '.sbsk-deep-choice:checked' ).each( function () {
					dims.push( this.value );
					files += parseInt( $( this ).closest( 'li' ).find( '.sbsk-deep__row span' ).text(), 10 ) || 0;
				} );

				if ( ! dims.length ) {
					return;
				}

				if ( ! window.confirm( 'Delete ' + files + ' file' + ( files === 1 ? '' : 's' ) + ' at ' + dims.length + ' size' + ( dims.length === 1 ? '' : 's' ) + '? This cannot be undone.' ) ) {
					return;
				}

				var removed = 0;
				var freed   = 0;
				var skipped = [];
				var began   = Date.now();

				// The same clock the rebuild keeps, so a long delete reads the same way.
				function took() {
					return Math.max( 0, Math.round( ( Date.now() - began ) / 1000 ) );
				}

				function spellTook( seconds ) {
					return seconds < 60 ? seconds + 's' : Math.floor( seconds / 60 ) + 'm ' + ( seconds % 60 ) + 's';
				}

				function each( done ) {
					return done > 0 && took() > 0 ? ', ' + ( Math.round( ( took() / done ) * 100 ) / 100 ) + 's per file' : '';
				}

				// The same bar the rebuild uses, so a long delete looks like work rather
				// than a stuck line of text.
				$deep.html( '<div class="sbsk-rebuild__bar sbsk-deep__bar"><span></span></div><p class="sbsk-deep__working">Deleting...</p>' );

				/**
				 * One batch per request, so a big clean-up never sits in one call
				 * long enough to hit a time limit. Skipped files come back with
				 * their reasons and are shown once it finishes.
				 */
				function batch( offset ) {
					$.post( ajaxurl, { action: 'sbsk_images_deep_remove', nonce: nonce, dims: dims, offset: offset } ).done( function ( response ) {
						if ( ! response || ! response.success ) {
							$deep.html( '<p class="sbsk-deep__working">Something went wrong. ' + removed + ' files had already been removed.</p>' );

							return;
						}

						var data = response.data;

						removed += data.removed;
						freed   += data.bytes;
						skipped  = skipped.concat( data.skipped || [] );

						if ( ! data.done ) {
							var seen = Math.min( removed + skipped.length, data.total || files );

							$deep.find( '.sbsk-deep__bar span' ).css( 'width', ( data.total ? Math.round( ( seen / data.total ) * 100 ) : 0 ) + '%' );
							$deep.find( '.sbsk-deep__working' ).text( 'Deleting... ' + removed + ' of ' + files + ' removed. ' + spellTook( took() ) + each( removed ) );
							batch( data.offset );

							return;
						}

						var note = '<p class="sbsk-deep__working">Removed ' + removed + ' file' + ( removed === 1 ? '' : 's' ) + ', ' + Math.round( freed / 1048576 * 10 ) / 10 + ' MB freed. Took ' + spellTook( took() ) + each( removed ) + '.</p>';

						if ( skipped.length ) {
							note += '<div class="sbsk-deep__skipped"><p>' + skipped.length + ' file' + ( skipped.length === 1 ? ' was' : 's were' ) + ' left alone:</p><ul>';

							skipped.slice( 0, 20 ).forEach( function ( skip ) {
								// why_html is built and escaped server side, and carries the edit links.
								note += '<li><code>' + $( '<span>' ).text( skip.name ).html() + '</code> <span>' + ( skip.why_html || $( '<span>' ).text( skip.why ).html() ) + '</span></li>';
							} );

							if ( skipped.length > 20 ) {
								note += '<li class="sbsk-deep__more">and ' + ( skipped.length - 20 ) + ' more</li>';
							}

							note += '</ul></div>';
						}

						$deep.html( note + data.html );
						deepButtons();
						scan( true );
					} ).fail( function () {
						$deep.html( '<p class="sbsk-deep__working">The server did not answer. ' + removed + ' files had already been removed.</p>' );
					} );
				}

				batch( 0 );
			} );

			// The Sizes to build figure opens the list of images behind it.
			$panel.on( 'click', '.sbsk-stat.is-openable', function () {
				var $list = $panel.find( '#' + $( this ).data( 'opens' ) );
				var shut  = $list.prop( 'hidden' );

				$list.prop( 'hidden', ! shut );
				$( this ).find( '.sbsk-stat__more' ).text( shut ? 'Hide images' : 'Show images' );
			} );

			/**
			 * Rebuild by an image name does every size it is missing; rebuild by a
			 * size does that one. The row keeps up with what is left, and goes when
			 * there is nothing left to build.
			 */
			$panel.on( 'click', '.sbsk-fix', function () {
				var $button = $( this );
				var $row    = $button.closest( '.sbsk-missing__row' );
				var size    = $button.data( 'size' );

				$row.find( '.sbsk-fix' ).prop( 'disabled', true );

				$.post( ajaxurl, { action: 'sbsk_images_fix', nonce: nonce, id: $button.data( 'id' ), sizes: size ? [ size ] : [] } ).done( function ( response ) {
					if ( ! response || ! response.success ) {
						$row.find( '.sbsk-fix' ).prop( 'disabled', false );

						return;
					}

					var left = response.data.left || [];

					if ( ! left.length ) {
						$row.remove();
						scan( true );

						return;
					}

					$row.find( 'li[data-size]' ).each( function () {
						if ( left.indexOf( $( this ).data( 'size' ) ) === -1 ) {
							$( this ).remove();
						}
					} );

					$row.find( '.sbsk-fix' ).prop( 'disabled', false );
					scan( true );
				} ).fail( function () {
					$row.find( '.sbsk-fix' ).prop( 'disabled', false );
				} );
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
						stopClock();
						$status.text( 'Something went wrong. Nothing else was changed.' );
						buttons( true );
						return;
					}

					var data = response.data;

					cleared += data.removed;
					freed += data.bytes;

					var seen = Math.min( data.offset, data.total );
					var pct  = data.total ? Math.round( ( seen / data.total ) * 100 ) : 100;

					seenNow = seen;
					paintTimer();

					$bar.css( 'width', pct + '%' );
					$status.text( cleared + ' of ' + data.total + ' removed.' );

					if ( data.done || stopped ) {
						$bar.css( 'width', data.done ? '100%' : pct + '%' );
						finished( ( data.done ? 'Finished. ' : 'Stopped. ' ) + cleared + ' files removed, ' + Math.round( freed / 1048576 * 10 ) / 10 + ' MB freed.' );
						scan( true );
						return;
					}

					clearOrphans( data.offset, cleared, freed );
				} ).fail( function () {
					stopClock();
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
				$( '#sbsk-progress-log' ).empty();
				$bar.css( 'width', '0%' );
				$status.text( 'Clearing...' );
				startClock();

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

		// Select all and Select none on a long checklist. The tick has to be set
		// with a real DOM change event: save-state.js listens with
		// addEventListener, which a jQuery trigger never reaches, and the save
		// button would sit disabled while the ticks plainly changed.
		$( document ).on( 'click', '[data-sbsk-check]', function () {
			var wanted = $( this ).data( 'sbsk-check' ) === 'all';
			var $field = $( this ).closest( '.sbsk-field' );

			$field.find( '.sbsk-checklist input[type="checkbox"]' ).each( function () {
				if ( this.disabled || this.checked === wanted ) {
					return;
				}

				this.checked = wanted;
				this.dispatchEvent( new Event( 'change', { bubbles: true } ) );
			} );
		} );
	} );
} )( jQuery );