/**
 * Reload the editor after a save.
 *
 * The block editor saves in the background and leaves you on the same page, so
 * anything the server changed on save, a rewritten title, a field filled in by
 * another plugin, a template that has moved on, is not in front of you until you
 * reload by hand. This does it for you.
 *
 * It waits for the save to finish and succeed, and for any meta boxes to finish
 * saving too, because those are sent after the post itself and a reload in the
 * middle of them would lose whatever they were writing.
 */
( function ( wp ) {
	'use strict';

	var cfg = window.sbskRefreshOnSave || {};

	if ( ! wp || ! wp.data || ! wp.data.subscribe ) {
		return;
	}

	var FLAG = 'sbsk-saved';

	/** Say so after the reload, since the editor own notice does not survive it. */
	function announce() {
		var url = new URL( window.location.href );

		if ( ! url.searchParams.has( FLAG ) ) {
			return;
		}

		url.searchParams.delete( FLAG );
		window.history.replaceState( {}, '', url.toString() );

		if ( wp.data.dispatch( 'core/notices' ) ) {
			wp.data.dispatch( 'core/notices' ).createNotice( 'success', cfg.saved || 'Saved.', { type: 'snackbar', isDismissible: true } );
		}
	}

	function reload() {
		var url = new URL( window.location.href );

		url.searchParams.set( FLAG, '1' );

		// replace, so the back button does not walk through every save.
		window.location.replace( url.toString() );
	}

	function watch() {
		var editor = wp.data.select( 'core/editor' );

		if ( ! editor ) {
			return;
		}

		var wasSaving = false;

		wp.data.subscribe( function () {
			var post = wp.data.select( 'core/editor' );
			var edit = wp.data.select( 'core/edit-post' );

			if ( ! post ) {
				return;
			}

			// An autosave is not a save you asked for, so it is left alone.
			var saving = post.isSavingPost() && ! post.isAutosavingPost();
			var boxes  = edit && edit.isSavingMetaBoxes ? edit.isSavingMetaBoxes() : false;
			var busy   = saving || boxes;

			if ( wasSaving && ! busy ) {
				wasSaving = false;

				if ( post.didPostSaveRequestSucceed && ! post.didPostSaveRequestSucceed() ) {
					return;
				}

				reload();

				return;
			}

			if ( busy ) {
				wasSaving = true;
			}
		} );
	}

	if ( wp.domReady ) {
		wp.domReady( function () {
			announce();
			watch();
		} );

		return;
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		announce();
		watch();
	} );
}( window.wp ) );
