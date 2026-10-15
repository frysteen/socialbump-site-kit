/**
 * SB Site Kit settings screen.
 *
 * Colour fields: the swatch shows whatever the text box resolves to on this
 * page, including var(--name). Picking with the swatch writes a hex back.
 * Card settings show or hide as the switch is flipped.
 */
( function ( $ ) {
	/**
	 * Confirm popups in the SocialBUMP style, the same box as SEO for AI and the
	 * SocialBUMP Tweaks hub, instead of the browser's own. A handler that needs a
	 * yes calls ask( element, options ) and returns. The action clicks the
	 * element again with a one shot pass that confirmed( element ) spends, so the
	 * handler carries on as it did after window.confirm().
	 *
	 * options: title, message, action (the button's words), check (a tick box
	 * label), before( checked ) run just ahead of the second click.
	 */
	var $sbskModal = null;

	function closeModal() {
		if ( $sbskModal ) {
			$sbskModal.remove();
			$sbskModal = null;
		}

		$( document ).off( 'keydown.sbskModal' );
	}

	function openModal( options, onAction ) {
		closeModal();

		var $modal  = $( '<div class="sbsk-modal"><div class="sbsk-dialog" role="dialog" aria-modal="true"></div></div>' );
		var $dialog = $modal.children();
		var $row    = $( '<p class="sbsk-dialog__buttons"></p>' );
		var $go     = $( '<button type="button" class="button button-primary"></button>' ).text( options.action || 'OK' );
		var $box    = null;

		$dialog.append( '<button type="button" class="sbsk-dialog__x" aria-label="Close">&times;</button>' );
		$dialog.append( $( '<h2></h2>' ).text( options.title || '' ) );

		if ( options.message ) {
			$dialog.append( $( '<p class="sbsk-dialog__message"></p>' ).text( options.message ) );
		}

		// body: anything else to show, such as a run's summary.
		if ( options.body ) {
			$dialog.append( options.body );
		}

		// check is the tick box: its words, or { label, list } for words over a
		// list, each line's first word (a size name, say) in bold.
		if ( options.check ) {
			var check  = 'string' === typeof options.check ? { label: options.check } : options.check;
			var $label = $( '<label class="sbsk-dialog__check"><input type="checkbox"><span></span></label>' );
			var $words = $label.find( 'span' );

			$words.text( check.label || '' );

			if ( check.list && check.list.length ) {
				var $list = $( '<ul class="sbsk-dialog__list"></ul>' );

				check.list.forEach( function ( line ) {
					var text  = String( line );
					var at    = text.indexOf( ' ' );
					var $item = $( '<li></li>' );

					if ( at > 0 ) {
						$item.append( $( '<strong></strong>' ).text( text.slice( 0, at ) ), document.createTextNode( text.slice( at ) ) );
					} else {
						$item.text( text );
					}

					$list.append( $item );
				} );

				$words.append( $list );
			}

			$box = $label.find( 'input' );
			$dialog.append( $label );
		}

		if ( ! options.alert ) {
			$row.append( $( '<button type="button" class="button sbsk-dialog__cancel"></button>' ).text( options.cancel || 'Cancel' ) );
		}

		$row.append( $go );
		$dialog.append( $row );

		// A click on the dark area closes it, as do the X, Cancel and Escape.
		$modal.on( 'click', function ( e ) {
			if ( e.target === this ) {
				closeModal();
			}
		} );

		$dialog.on( 'click', '.sbsk-dialog__x, .sbsk-dialog__cancel', closeModal );

		$go.on( 'click', function () {
			var checked = $box ? $box.prop( 'checked' ) : false;

			closeModal();

			if ( onAction ) {
				onAction( checked );
			}
		} );

		$( document ).on( 'keydown.sbskModal', function ( e ) {
			if ( e.key === 'Escape' ) {
				closeModal();
			}
		} );

		$( document.body ).append( $modal );
		$sbskModal = $modal;
		$go.trigger( 'focus' );
	}

	function confirmed( element ) {
		if ( element && element.sbskPass ) {
			element.sbskPass = false;

			return true;
		}

		return false;
	}

	function ask( element, options ) {
		openModal( options, function ( checked ) {
			if ( options.before ) {
				options.before( checked );
			}

			element.sbskPass = true;
			$( element ).trigger( 'click' );
		} );
	}

	function tell( options ) {
		openModal( $.extend( { action: 'OK', alert: true }, options ) );
	}

	// For scripts printed by the modules' own pages.
	window.SBSKConfirm = { ask: ask, confirmed: confirmed, tell: tell };
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
				$row.removeClass( 'is-default is-duplicate' ).find( '.sbsk-width__default, .sbsk-width__clash, .sbsk-width__dupe' ).remove();
				$widths.append( $row );
				$row.find( 'input' ).trigger( 'focus' );
			} );

			$widths.on( 'click', '.sbsk-width__remove', function () {
				$( this ).closest( '.sbsk-width' ).remove();
			} );

			// The standard widths: marked in the list, and added back by Add default sizes.
			var defaults   = ( $widths.data( 'defaults' ) || [] ).map( Number );
			var $addDefaults = $( '#sbsk-add-defaults' );
			var $blank     = $widths.find( '.sbsk-width' ).first().clone();

			$blank.removeClass( 'is-duplicate is-default' ).find( '.sbsk-width__clash, .sbsk-width__dupe, .sbsk-width__default' ).remove();

			function listed() {
				return $widths.find( 'input[type="number"]' ).map( function () {
					return this.value === '' ? null : Number( this.value );
				} ).get();
			}

			function missingDefaults() {
				var have = listed();

				return defaults.filter( function ( width ) {
					return have.indexOf( width ) === -1;
				} );
			}

			function syncDefaults() {
				$addDefaults.prop( 'disabled', ! missingDefaults().length );
			}

			function markDefault( $row, value ) {
				var isDefault = value !== '' && defaults.indexOf( Number( value ) ) !== -1;

				$row.toggleClass( 'is-default', isDefault ).find( '.sbsk-width__default' ).remove();

				if ( isDefault ) {
					$row.find( '.sbsk-width__value' ).after( '<span class="sbsk-width__default">default size</span>' );
				}
			}

			$widths.on( 'click', '.sbsk-width__remove', syncDefaults );

			$widths.on( 'input', 'input[type="number"]', function () {
				markDefault( $( this ).closest( '.sbsk-width' ), this.value );
				syncDefaults();
			} );

			// Adds only the standard widths that are not in the list, each in order.
			// Nothing is saved until Save changes, like Add a width.
			$addDefaults.on( 'click', function () {
				if ( ! $blank.length ) {
					return;
				}

				missingDefaults().forEach( function ( width ) {
					var $row    = $blank.clone();
					var $before = $widths.find( '.sbsk-width' ).filter( function () {
						var value = $( this ).find( 'input[type="number"]' ).val();

						return value !== '' && Number( value ) > width;
					} ).first();

					$row.find( 'input[type="number"]' ).val( width );

					if ( $before.length ) {
						$row.insertBefore( $before );
					} else {
						$widths.append( $row );
					}

					// A real input event, so the name, the marks and the save button all follow.
					$row.find( 'input[type="number"]' )[0].dispatchEvent( new Event( 'input', { bubbles: true } ) );
				} );

				syncDefaults();
			} );

			syncDefaults();


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

			// Which run is going, for the title of the summary that pops up at the end.
			var jobTitle = '';

			/** Show the progress box for a new run, or for watching another window's. */
			function openJob( title ) {
				jobTitle = title;
				$progress.removeClass( 'is-done is-failed' ).prop( 'hidden', false );
				$( '#sbsk-progress-log' ).empty();
				$bar.css( 'width', '0%' );
			}

			/** Stop where it is, the bar red, with the reason. */
			function failJob( text ) {
				stopClock();
				$progress.addClass( 'is-failed' );
				$status.text( text );
			}

			/**
			 * One token per run. The server holds a site wide lock while a long job
			 * runs and refuses requests from any window without that token,
			 * because every window shares the held image lists.
			 */
			var jobToken = '';

			function newJob() {
				jobToken = Math.random().toString( 36 ).slice( 2 ) + Date.now().toString( 36 );
			}

			function releaseJob() {
				if ( jobToken ) {
					$.post( ajaxurl, { action: 'sbsk_images_release', nonce: nonce, job: jobToken } );
				}

				jobToken = '';
			}

			/** This window's run was stopped from another window. */
			function stoppedIn( response ) {
				return !! ( response && ! response.success && response.data && response.data.stopped );
			}

			/**
			 * Leaving the page ends the run, so the lock is let go of straight away
			 * instead of shutting out other windows, or this one come back to, until
			 * it lapses. leaving also stops a batch still running on the server from
			 * taking the lock back when it finishes.
			 */
			window.addEventListener( 'pagehide', function () {
				if ( ! jobToken || ! navigator.sendBeacon ) {
					return;
				}

				var data = new FormData();

				data.append( 'action', 'sbsk_images_release' );
				data.append( 'nonce', nonce );
				data.append( 'job', jobToken );
				data.append( 'leaving', '1' );
				navigator.sendBeacon( ajaxurl, data );
			} );

			/** The job another window is running, when the server refused for that reason. */
			function busyIn( response ) {
				return response && ! response.success && response.data && response.data.busy ? response.data.busy : null;
			}

			function buttons( on ) {
				// Not the progress box's own controls: Cancel has to stay clickable while
				// a run is going, which is the only time it is on screen.
				$rebuild.find( 'button' ).not( '#sbsk-progress-cancel, #sbsk-progress-close, #sbsk-progress-takeover' ).prop( 'disabled', ! on );

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

				return $.post( ajaxurl, { action: 'sbsk_images_report', nonce: nonce, job: jobToken } ).done( function ( response ) {
					var busy = busyIn( response );

					if ( busy ) {
						$panel.prop( 'hidden', true ).empty();
						watch( busy );
						return;
					}

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
			var $takeover = $( '#sbsk-progress-takeover' );
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

			/**
			 * A job running in another window, or for another person. This window
			 * only watches: the counts are read from the server every few seconds
			 * and the buttons stay off until the job has finished. The per image
			 * log lives in the other window and is not repeated here.
			 */
			var watching = null;

			function paintWatch( busy ) {
				var total = busy.total || 0;
				var done  = Math.min( busy.done || 0, total );
				var line  = 'In another window, started by ' + busy.user + '. ';

				$bar.css( 'width', ( total ? Math.round( ( done / total ) * 100 ) : 0 ) + '%' );
				$status.text( line + ( total ? done + ' of ' + total + '. ' + ( busy.current || '' ) : 'Starting...' ) );
				$timer.text( spell( busy.elapsed ) + ( done > 0 && busy.elapsed > 0 ? ' | ' + ( Math.round( ( busy.elapsed / done ) * 100 ) / 100 ) + 's per ' + busy.unit : '' ) );
			}

			function pollWatch() {
				$.post( ajaxurl, { action: 'sbsk_images_status', nonce: nonce } ).done( function ( response ) {
					var busy = ( response && response.success ) ? response.data.busy : null;

					if ( busy ) {
						paintWatch( busy );
						return;
					}

					window.clearInterval( watching );
					watching = null;
					$bar.css( 'width', '100%' );
					$progress.addClass( 'is-done' );
					$status.text( 'Finished in the other window.' );
					$takeover.prop( 'hidden', true );
					$close.prop( 'hidden', false );
					buttons( true );
				} );
			}

			function watch( busy ) {
				buttons( false );
				openJob( busy.label );
				window.clearInterval( ticking );
				ticking = null;
				$cancel.prop( 'hidden', true );
				$close.prop( 'hidden', true );
				$takeover.prop( { hidden: false, disabled: false } );
				paintWatch( busy );

				if ( ! watching ) {
					watching = window.setInterval( pollWatch, 3000 );
				}
			}

			// Stops the run another window holds, or one left behind by a page that
			// was closed part way. Anything it has done already stays done.
			$takeover.on( 'click', function () {
				if ( ! confirmed( this ) ) {
					ask( this, { title: 'Stop that run', message: 'Stop the run in the other window? Anything it has already done is kept.', action: 'Stop it' } );
					return;
				}

				$takeover.prop( 'disabled', true );

				$.post( ajaxurl, { action: 'sbsk_images_stop', nonce: nonce } ).done( function ( response ) {
					if ( ! response || ! response.success ) {
						$takeover.prop( 'disabled', false );
						return;
					}

					window.clearInterval( watching );
					watching = null;
					$takeover.prop( 'hidden', true );
					$progress.addClass( 'is-done' );
					$status.text( 'Stopped. Anything it had already done has been kept.' );
					$close.prop( 'hidden', false );
					buttons( true );
				} ).fail( function () {
					$takeover.prop( 'disabled', false );
				} );
			} );

			$cancel.on( 'click', function () {
				// The batch in flight finishes; nothing after it is asked for.
				stopped = true;
				$cancel.prop( 'disabled', true );
				$status.text( 'Stopping after this batch...' );
			} );

			/** How long a run took, as the summary says it: 42 seconds, 3 minutes 5 seconds. */
			function spoken( seconds ) {
				if ( seconds < 60 ) {
					return seconds + ( seconds === 1 ? ' second' : ' seconds' );
				}

				var m = Math.floor( seconds / 60 );
				var s = seconds % 60;

				return m + ( m === 1 ? ' minute ' : ' minutes ' ) + s + ( s === 1 ? ' second' : ' seconds' );
			}

			/**
			 * The end of a run. The progress box keeps its last line, as it always
			 * has, and a summary pops up: Finished or Stopped, the summary in bold
			 * with the time it took, Close, and Scan again for a fresh scan. The X,
			 * Escape and a click outside close it too.
			 */
			function finished( text ) {
				var parts   = /^(Finished|Stopped)\.\s*(.*)$/.exec( text );
				var seconds = elapsed();
				var rate    = seenNow > 0 && seconds > 0 ? ', ' + ( Math.round( ( seconds / seenNow ) * 100 ) / 100 ) + 's per image' : '';
				var $body   = $( '<div class="sbsk-summary"></div>' );
				var $line   = $( '<p class="sbsk-job__summary"></p>' );

				stopClock();

				if ( ! parts || 'Finished' === parts[1] ) {
					$bar.css( 'width', '100%' );
				}

				$status.text( text + ' Took ' + spell( seconds ) + rate + '.' );
				$cancel.prop( 'disabled', false );

				$line.append( $( '<strong></strong>' ).text( ( parts ? parts[2] : text ).replace( /\.$/, '' ) ), document.createTextNode( ' in ' + spoken( seconds ) + rate + '.' ) );
				$body.append( $( '<p class="sbsk-summary__head"></p>' ).text( parts ? parts[1] : 'Finished' ), $( '<div class="sbsk-job__report"></div>' ).append( $line ) );

				openModal( { title: jobTitle || 'Finished', body: $body, cancel: 'Close', action: 'Scan again' }, function () {
					$progress.prop( 'hidden', true );
					scan( false );
				} );
			}

			function batch( offset, total, force, mode, sizes ) {
				$.post( ajaxurl, { action: 'sbsk_images_batch', nonce: nonce, job: jobToken, offset: offset, force: force ? 1 : 0, mode: mode, sizes: sizes || [], batch: ( retried || force ) ? 1 : 5 } ).done( function ( response ) {
					if ( ! response || ! response.success ) {
						stopClock();

						if ( stoppedIn( response ) ) {
							jobToken = '';
							$status.text( 'Stopped from another window. Anything already done has been kept.' );
							buttons( true );
							return;
						}

						var busy = busyIn( response );

						if ( busy ) {
							jobToken = '';
							watch( busy );
							return;
						}

						releaseJob();
						failJob( 'Something went wrong. Try again.' );
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
						releaseJob();
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
					releaseJob();
					failJob( 'The server did not answer. Anything already built has been kept, so you can start again from here.' );
					buttons( true );
				} );
			}

			function start( mode, force, sizes ) {
				totals = { built: 0, removed: 0, files: 0 };
				newJob();

				buttons( false );
				openJob( force ? 'Rebuilding all thumbnails' : ( 'clean' === mode ? 'Removing old sizes' : 'Building thumbnails' ) );
				$status.text( 'Working...' );
				startClock();

				$.post( ajaxurl, { action: 'sbsk_images_count', nonce: nonce, job: jobToken, mode: mode, force: force ? 1 : 0 } ).done( function ( response ) {
					var busy = busyIn( response );

					if ( busy ) {
						stopClock();
						jobToken = '';
						watch( busy );
						return;
					}

					var total = ( response && response.success ) ? response.data.total : 0;

					if ( ! total ) {
						stopClock();
						releaseJob();
						$progress.addClass( 'is-done' );
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

				$.post( ajaxurl, { action: 'sbsk_images_deep', nonce: nonce, job: jobToken } ).done( function ( response ) {
					var busy = busyIn( response );

					if ( busy ) {
						$deep.prop( 'hidden', true ).empty();
						watch( busy );
						return;
					}

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

				if ( ! confirmed( this ) ) {
					ask( this, { title: 'Delete leftover thumbnails', message: 'Delete ' + files + ' file' + ( files === 1 ? '' : 's' ) + ' at ' + dims.length + ' size' + ( dims.length === 1 ? '' : 's' ) + '? This cannot be undone.', action: 'Delete' } );
					return;
				}

				var removed = 0;
				var freed   = 0;
				var skipped = [];

				// The same progress popup as the rebuild.
				buttons( false );
				openJob( 'Deleting leftover thumbnails' );
				$status.text( 'Deleting...' );
				startClock();

				// The files left alone, kept in the panel once the popup is closed.
				function leftAlone() {
					if ( ! skipped.length ) {
						return '';
					}

					var note = '<div class="sbsk-deep__skipped"><p>' + skipped.length + ' file' + ( skipped.length === 1 ? ' was' : 's were' ) + ' left alone:</p><ul>';

					skipped.slice( 0, 20 ).forEach( function ( skip ) {
						// why_html is built and escaped server side, and carries the edit links.
						note += '<li><code>' + $( '<span>' ).text( skip.name ).html() + '</code> <span>' + ( skip.why_html || $( '<span>' ).text( skip.why ).html() ) + '</span></li>';
					} );

					if ( skipped.length > 20 ) {
						note += '<li class="sbsk-deep__more">and ' + ( skipped.length - 20 ) + ' more</li>';
					}

					return note + '</ul></div>';
				}

				/**
				 * One batch per request, so a big clean-up never sits in one call
				 * long enough to hit a time limit. Skipped files come back with
				 * their reasons and are shown once it finishes.
				 */
				function batch( offset ) {
					$.post( ajaxurl, { action: 'sbsk_images_deep_remove', nonce: nonce, job: jobToken, dims: dims, offset: offset } ).done( function ( response ) {
						var busy = busyIn( response );

						if ( busy ) {
							jobToken = '';
							$deep.prop( 'hidden', true ).empty();
							watch( busy );
							return;
						}

						if ( stoppedIn( response ) ) {
							jobToken = '';
							stopClock();
							$status.text( 'Stopped from another window. ' + removed + ' files had already been removed.' );
							buttons( true );
							return;
						}

						if ( ! response || ! response.success ) {
							releaseJob();
							failJob( 'Something went wrong. ' + removed + ' files had already been removed.' );
							buttons( true );
							return;
						}

						var data = response.data;

						removed += data.removed;
						freed   += data.bytes;
						skipped  = skipped.concat( data.skipped || [] );

						var seen = Math.min( removed + skipped.length, data.total || files );

						seenNow = removed;
						paintTimer();
						$bar.css( 'width', ( data.total ? Math.round( ( seen / data.total ) * 100 ) : 0 ) + '%' );
						$status.text( removed + ' of ' + files + ' removed.' );

						if ( ! data.done && ! stopped ) {
							batch( data.offset );
							return;
						}

						finished( ( data.done ? 'Finished. ' : 'Stopped. ' ) + removed + ' file' + ( removed === 1 ? '' : 's' ) + ' removed, ' + Math.round( freed / 1048576 * 10 ) / 10 + ' MB freed.' );

						if ( data.html ) {
							$deep.html( leftAlone() + data.html );
							deepButtons();
						} else {
							$deep.html( leftAlone() );
						}

						scan( true );
						releaseJob();
					} ).fail( function () {
						releaseJob();
						failJob( 'The server did not answer. ' + removed + ' files had already been removed.' );
						buttons( true );
					} );
				}

				newJob();
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

				$.post( ajaxurl, { action: 'sbsk_images_fix', nonce: nonce, job: jobToken, id: $button.data( 'id' ), sizes: size ? [ size ] : [] } ).done( function ( response ) {
					if ( busyIn( response ) ) {
						watch( busyIn( response ) );
						return;
					}

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

			// An orphaned original goes back in the library where it is. The rescan
			// then drops it, and its thumbnails, from the orphan list.
			$panel.on( 'click', '.sbsk-report__add', function () {
				var $button = $( this );

				$button.prop( 'disabled', true ).text( 'Adding...' );

				$.post( ajaxurl, { action: 'sbsk_images_orphan_add', nonce: nonce, job: jobToken, file: $button.data( 'file' ) } ).done( function ( response ) {
					if ( busyIn( response ) ) {
						$button.prop( 'disabled', false ).text( 'Add to library' );
						watch( busyIn( response ) );
						return;
					}

					if ( response && response.success ) {
						scan( true );
						return;
					}

					$button.prop( 'disabled', false ).text( 'Add to library' );
					tell( { title: 'Not added', message: ( response && response.data && response.data.message ) || 'That file could not be added.' } );
				} ).fail( function () {
					$button.prop( 'disabled', false ).text( 'Add to library' );
				} );
			} );

			$panel.on( 'click', '.sbsk-report__delete', function () {
				var $button = $( this );
				var file    = $button.data( 'file' );

				if ( ! confirmed( this ) ) {
					ask( this, { title: 'Delete file', message: 'Delete ' + file + '? This cannot be undone.', action: 'Delete' } );
					return;
				}

				$button.prop( 'disabled', true ).text( 'Deleting...' );

				$.post( ajaxurl, { action: 'sbsk_images_orphan_one', nonce: nonce, job: jobToken, file: file } ).done( function ( response ) {
					if ( busyIn( response ) ) {
						$button.prop( 'disabled', false ).text( 'Delete' );
						watch( busyIn( response ) );
						return;
					}

					if ( response && response.success ) {
						$button.closest( 'tr' ).remove();
						scan( true );
						return;
					}

					$button.prop( 'disabled', false ).text( 'Delete' );
				} );
			} );

			/**
			 * Missing images: library entries with no file on disk. Taken out of the
			 * library a batch at a time in the progress box, so a long list never
			 * sits in one request. The server checks each one again before it goes.
			 */
			function deleteGone( ids, offset, deleted ) {
				var slice = ids.slice( offset, offset + 20 );

				$.post( ajaxurl, { action: 'sbsk_images_gone_delete', nonce: nonce, job: jobToken, ids: slice, offset: offset, total: ids.length } ).done( function ( response ) {
					if ( ! response || ! response.success ) {
						stopClock();

						if ( stoppedIn( response ) ) {
							jobToken = '';
							$status.text( 'Stopped from another window. Anything already done has been kept.' );
							buttons( true );
							return;
						}

						if ( busyIn( response ) ) {
							jobToken = '';
							watch( busyIn( response ) );
							return;
						}

						releaseJob();
						failJob( 'Something went wrong. ' + deleted + ' had already been deleted.' );
						buttons( true );
						return;
					}

					deleted += response.data.deleted;

					var done = Math.min( offset + slice.length, ids.length );

					seenNow = done;
					paintTimer();

					$bar.css( 'width', Math.round( ( done / ids.length ) * 100 ) + '%' );
					$status.text( done + ' of ' + ids.length + ' checked.' );

					if ( response.data.done || done >= ids.length || stopped ) {
						finished( ( stopped && done < ids.length ? 'Stopped. ' : 'Finished. ' ) + deleted + ' missing image' + ( deleted === 1 ? '' : 's' ) + ' deleted.' );
						scan( true );
						releaseJob();
						return;
					}

					deleteGone( ids, done, deleted );
				} ).fail( function () {
					stopClock();
					releaseJob();
					failJob( 'The server did not answer. ' + deleted + ' had already been deleted.' );
					buttons( true );
				} );
			}

			$panel.on( 'click', '#sbsk-gone-delete', function () {
				var ids = $( this ).data( 'ids' ) || [];

				if ( ! ids.length ) {
					return;
				}

				if ( ! confirmed( this ) ) {
					ask( this, { title: 'Delete missing images', message: 'Delete ' + ids.length + ' missing image' + ( ids.length === 1 ? '' : 's' ) + ' from the Media Library? Pages using them lose them. This cannot be undone.', action: 'Delete' } );
					return;
				}

				buttons( false );
				openJob( 'Deleting missing images' );
				$status.text( 'Deleting...' );
				startClock();
				newJob();

				deleteGone( ids, 0, 0 );
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

				if ( force && ! confirmed( this ) ) {
					ask( this, { title: 'Rebuild every image', message: 'Rebuild the ' + savedCount + ' saved size' + ( savedCount === 1 ? '' : 's' ) + ' on every image? This replaces files that already exist.', action: 'Rebuild' } );
					return;
				}

				start( 'build', force, [] );
			} );

			$clean.on( 'click', function () {
				if ( ! confirmed( this ) ) {
					ask( this, { title: 'Remove old thumbnails', message: 'Remove the thumbnails left over from sizes you have taken off the list?', action: 'Remove' } );
					return;
				}

				start( 'clean', false );
			} );

			function clearOrphans( offset, cleared, freed ) {
				$.post( ajaxurl, { action: 'sbsk_images_orphans', nonce: nonce, job: jobToken, offset: offset } ).done( function ( response ) {
					if ( ! response || ! response.success ) {
						stopClock();

						if ( stoppedIn( response ) ) {
							jobToken = '';
							$status.text( 'Stopped from another window. Anything already done has been kept.' );
							buttons( true );
							return;
						}

						if ( busyIn( response ) ) {
							jobToken = '';
							watch( busyIn( response ) );
							return;
						}

						releaseJob();
						failJob( 'Something went wrong. Nothing else was changed.' );
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
						releaseJob();
						return;
					}

					clearOrphans( data.offset, cleared, freed );
				} ).fail( function () {
					stopClock();
					releaseJob();
					failJob( 'The server did not answer. Nothing else was changed, and what was already removed has gone.' );
					buttons( true );
				} );
			}

			$orphan.on( 'click', function () {
				if ( ! confirmed( this ) ) {
					ask( this, { title: 'Delete orphaned images', message: 'Delete ' + lastOrphans + ' orphaned image' + ( lastOrphans === 1 ? '' : 's' ) + '? This cannot be undone.', action: 'Delete' } );
					return;
				}

				buttons( false );
				openJob( 'Deleting orphan images' );
				$status.text( 'Clearing...' );
				startClock();
				newJob();

				clearOrphans( 0, 0, 0 );
			} );

			// Another window, or another person, may already be running a job.
			$.post( ajaxurl, { action: 'sbsk_images_status', nonce: nonce } ).done( function ( response ) {
				if ( response && response.success && response.data.busy ) {
					watch( response.data.busy );
				}
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

		// Reset asks first. When WordPress's own sizes are set differently from
		// its defaults, the popup offers to put those back as well.
		$( '#sbsk-reset-widths' ).on( 'click', function ( e ) {
			if ( confirmed( this ) ) {
				return;
			}

			e.preventDefault();

			var $form  = $( this.form );
			var widths = ( $( '#sbsk-widths' ).data( 'defaults' ) || [] ).join( ', ' ).replace( /, ([^,]*)$/, ' and $1' );
			var drift  = $( this ).data( 'wp-drift' ) || [];

			ask( this, {
				title: 'Reset to default sizes',
				message: 'Put the standard image sizes back (' + widths + '), and switch off medium_large, 1536x1536 and 2048x2048? Any widths you have added or changed will be lost.',
				check: drift.length ? { label: 'Also put the WordPress sizes back to their defaults:', list: drift } : '',
				action: 'Reset',
				before: function ( checked ) {
					$form.find( 'input[name="sbsk_reset_wp"]' ).remove();

					if ( checked ) {
						$form.append( '<input type="hidden" name="sbsk_reset_wp" value="1">' );
					}
				}
			} );
		} );

		// Buttons that ask first: data-sbsk-confirm holds the question, with %s
		// standing for the value of the field named in data-sbsk-confirm-field.
		// data-sbsk-progress opens the progress popup once it is confirmed, for a
		// long post such as Publish release; it stays until the next page loads.
		$( document ).on( 'click', '[data-sbsk-confirm]', function ( e ) {
			if ( confirmed( this ) ) {
				return;
			}

			e.preventDefault();

			var button  = this;
			var $button = $( button );
			var field   = $button.data( 'sbsk-confirm-field' );
			var value   = field && button.form && button.form.elements[ field ] ? button.form.elements[ field ].value : '';

			ask( button, {
				title: $button.data( 'sbsk-confirm-title' ) || $.trim( $button.text() ),
				message: String( $button.data( 'sbsk-confirm' ) ).replace( '%s', value ),
				action: $button.data( 'sbsk-confirm-action' ) || $.trim( $button.text() ),
				before: function () {
					var title = $button.data( 'sbsk-progress' );

					// Not when the form will refuse to send, or the popup would never go.
					if ( ! title || ! window.SBSKProgress || ( button.form && ! button.form.checkValidity() ) ) {
						return;
					}

					var job = window.SBSKProgress.open( title );

					job.busy();
					job.status( String( $button.data( 'sbsk-progress-status' ) || '' ).replace( '%s', value ) );
				}
			} );
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