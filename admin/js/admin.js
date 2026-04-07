/* Podcast Analytics for OP3 — Admin JS v2.0 */
/* global op3paData, jQuery */

( function ( $ ) {
	'use strict';

	// ── Stats page ──────────────────────────────────────────────────────────

	$( document ).on( 'click', '.op3pa-period-btn', function () {
		var $btn = $( this );
		$( '.op3pa-period-btn' ).removeClass( 'active' );
		$btn.addClass( 'active' );
		loadStats();
	} );

	$( '#op3pa-refresh' ).on( 'click', function () {
		loadStats( true );
	} );

	$( document ).on( 'change', '.op3pa-podcast-check', function () {
		// If "network" checkbox toggled, sync all others.
		if ( $( this ).val() === 'network' ) {
			var checked = $( this ).is( ':checked' );
			$( '.op3pa-podcast-check[value!="network"]' ).prop( 'checked', checked );
		}
		loadStats();
	} );

	function getSelectedIndexes() {
		var indexes = [];
		$( '.op3pa-podcast-check:checked' ).each( function () {
			indexes.push( $( this ).val() );
		} );
		return indexes;
	}

	function loadStats( forceRefresh ) {
		var days    = parseInt( $( '.op3pa-period-btn.active' ).data( 'days' ) || 30, 10 );
		var indexes = getSelectedIndexes();

		$( '#op3pa-stats-container' ).html(
			'<div class="op3pa-loading">' + op3paData.strings.refreshing + '</div>'
		);

		$.ajax( {
			url:    op3paData.ajaxUrl,
			method: 'POST',
			data:   {
				action:  'op3pa_refresh_stats',
				nonce:   op3paData.nonce,
				days:    days,
				indexes: indexes,
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

	// ── Dashboard widget pagination ─────────────────────────────────────────

	$( document ).on( 'click', '.op3pa-nav-next, .op3pa-nav-prev', function () {
		var $btn     = $( this );
		var $widget  = $btn.closest( '.op3pa-widget' );
		var $slides  = $widget.find( '.op3pa-widget-slides' );
		var $items   = $slides.find( '.op3pa-widget-slide' );
		var total    = $items.length;
		if ( total < 2 ) return;
		var current  = parseInt( $slides.data( 'current' ) || 0, 10 );
		var isNext   = $btn.hasClass( 'op3pa-nav-next' );

		$items.eq( current ).hide();
		current = isNext
			? ( current + 1 ) % total
			: ( current - 1 + total ) % total;
		$items.eq( current ).show();
		$slides.data( 'current', current );

		$widget.find( '.op3pa-nav-dot' ).removeClass( 'active' ).eq( current ).addClass( 'active' );
	} );

} )( jQuery );
