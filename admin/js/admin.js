/* Podcast Analytics for OP3 — Admin JS */
/* global op3paData, jQuery */

( function ( $ ) {
	'use strict';

	$( document ).on( 'click', '.op3pa-period-btn', function () {
		var $btn = $( this );
		var days = parseInt( $btn.data( 'days' ), 10 );
		$( '.op3pa-period-btn' ).removeClass( 'active' );
		$btn.addClass( 'active' );
		loadStats( days );
	} );

	$( '#op3pa-refresh' ).on( 'click', function () {
		var days = parseInt( $( '.op3pa-period-btn.active' ).data( 'days' ) || 30, 10 );
		loadStats( days );
	} );

	function loadStats( days ) {
		$( '#op3pa-stats-container' ).html(
			'<div class="op3pa-loading">' + op3paData.strings.refreshing + '</div>'
		);

		$.ajax( {
			url:    op3paData.ajaxUrl,
			method: 'POST',
			data:   {
				action: 'op3pa_refresh_stats',
				nonce:  op3paData.nonce,
				days:   days,
			},
			success: function ( response ) {
				if ( response.success ) {
					$( '#op3pa-stats-container' ).html( response.data.html );
				} else {
					$( '#op3pa-stats-container' ).html(
						'<p class="op3pa-error">' + op3paData.strings.error + '</p>'
					);
				}
			},
			error: function () {
				$( '#op3pa-stats-container' ).html(
					'<p class="op3pa-error">' + op3paData.strings.error + '</p>'
				);
			},
		} );
	}

} )( jQuery );
