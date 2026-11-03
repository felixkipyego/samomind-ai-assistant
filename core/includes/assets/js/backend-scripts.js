/**
 * SAMOMIND — Backend Scripts
 * SamoMind AI Assistant
 */
( function ( $ ) {
	'use strict';

	var POLL_MS = 5000;

	function pollProgress( $table ) {
		$.post( samomind_params.ajaxurl, {
			action: 'samomind_sync_progress',
			nonce: samomind_params.nonce
		} ).done( function ( response ) {
			if ( ! response || ! response.success ) {
				return;
			}
			var data = response.data;
			$table.attr( 'data-state', data.state );
			$table.find( '.samomind-sync-label' ).text( data.label );
			$table.find( '.samomind-progress' ).val( data.percent );
			$table.find( '.samomind-sync-count' ).text( data.sent + ' / ' + data.total );
			$table.find( '.samomind-sync-message' ).text( data.message );
			$table.find( '.samomind-pending' ).text( data.pending );
			$table.find( '.samomind-reconcile-label' ).text( data.reconcile );
			if ( 'running' === data.state ) {
				window.setTimeout( function () {
					pollProgress( $table );
				}, POLL_MS );
			}
		} );
	}

	$( document ).ready( function () {
		// Auto-dismiss success notices after 4 seconds.
		$( '.samomind-admin-wrap .notice-success.is-dismissible' ).delay( 4000 ).fadeOut( 400 );

		// Confirm destructive actions.
		$( '[data-samomind-confirm]' ).on( 'click', function ( event ) {
			if ( ! window.confirm( $( this ).attr( 'data-samomind-confirm' ) ) ) {
				event.preventDefault();
			}
		} );

		// Keep the sync progress current while a sync runs.
		var $table = $( '#samomind-sync-progress' );
		if ( $table.length && 'running' === $table.attr( 'data-state' ) ) {
			window.setTimeout( function () {
				pollProgress( $table );
			}, POLL_MS );
		}
	} );

} )( jQuery );
