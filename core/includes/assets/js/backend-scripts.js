/**
 * SAMOMIND — Backend Scripts
 * SamoMind AI Assistant
 */
( function ( $ ) {
	'use strict';

	$( document ).ready( function () {
		// Auto-dismiss success notices after 4 seconds.
		$( '.samomind-admin-wrap .notice-success.is-dismissible' ).delay( 4000 ).fadeOut( 400 );
	} );

} )( jQuery );
